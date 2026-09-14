<?php

namespace App\Tests\Functional\Twig\Components\Rag;

use App\Service\Rag\Quiz\Dto\GeneratedQuestion;
use App\Service\Rag\Quiz\Dto\GeneratedQuizPayload;
use App\Service\Rag\Quiz\QuizAttemptService;
use App\Service\Rag\Quiz\QuizGenerator;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class QuizComponentTest extends KernelTestCase
{
    use InteractsWithLiveComponents;

    private Connection $connection;
    private string $source;
    private KernelBrowser $client;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = self::getContainer()->get('doctrine.dbal.default_connection');
        $this->source = 'quiz-component-test://manual.pdf';

        $this->connection->insert('document_chunks', [
            'id' => Uuid::v4()->toRfc4122(),
            'embedding' => '['.implode(',', array_fill(0, 1536, 0)).']',
            'metadata' => json_encode(['_text' => 'Some content.', '_source' => $this->source], \JSON_THROW_ON_ERROR),
        ], [
            'id' => ParameterType::STRING,
            'embedding' => ParameterType::STRING,
            'metadata' => ParameterType::STRING,
        ]);

        $payload = new GeneratedQuizPayload([
            new GeneratedQuestion('Single-choice?', ['A', 'B'], [0], false, 'A is correct.', $this->source),
            new GeneratedQuestion('Multi-choice?', ['A', 'B', 'C'], [0, 2], true, 'A and C are correct.', $this->source),
        ]);

        $platform = new InMemoryPlatform(
            static fn (Model $model, $input, array $options): ObjectResult => new ObjectResult($payload),
        );

        $generator = new QuizGenerator(
            $this->connection,
            $platform,
            'gpt-4o-mini',
            2,
            'document_chunks',
            \dirname(__DIR__, 5).'/config/prompts/quiz_generation_system_prompt.md',
        );

        $entityManager = self::getContainer()->get('doctrine')->getManager();
        $repository = $entityManager->getRepository(\App\Entity\Rag\QuizAttempt::class);

        self::getContainer()->set(
            QuizAttemptService::class,
            new QuizAttemptService($generator, $repository, $entityManager, $this->connection, 'document_chunks'),
        );

        // Each call()/set() on a live component performs an HTTP request via
        // this client; without disabling the automatic kernel reboot, the
        // QuizAttemptService override above would be lost after the first one.
        $this->client = self::getContainer()->get('test.client');
        $this->client->disableReboot();
    }

    protected function tearDown(): void
    {
        $this->connection->executeStatement(
            "DELETE FROM document_chunks WHERE metadata->>'_source' = :source",
            ['source' => $this->source],
        );

        parent::tearDown();
    }

    public function testFullQuizCycleFromStartToRecap(): void
    {
        $component = $this->createLiveComponent('Rag:QuizComponent', client: $this->client)
            ->set('documentSource', $this->source)
            ->call('start');

        self::assertNull($component->component()->error);
        self::assertNotNull($component->component()->attemptId);
        self::assertSame(['index' => 0, 'prompt' => 'Single-choice?', 'options' => ['A', 'B'], 'multiple' => false], $component->component()->getCurrentQuestion());

        // Question 1: single-choice, answered correctly via selectOption().
        $component = $component->call('selectOption', ['index' => 0])->call('submitAnswer');

        self::assertSame(['correct', 'correctIndices', 'explanation', 'source'], array_keys($component->component()->feedback));
        self::assertTrue($component->component()->feedback['correct']);

        $component = $component->call('next');
        self::assertNull($component->component()->feedback);
        self::assertSame('Multi-choice?', $component->component()->getCurrentQuestion()['prompt']);

        // Question 2 is also the last one: the attempt becomes complete as
        // soon as this answer is recorded, but its feedback must still be
        // rendered before the recap — asserted against the actual rendered
        // HTML, not just component state, since a template branch ordering
        // bug (checking completion before feedback) previously skipped
        // straight to the recap and silently dropped the last question's
        // feedback from the page.
        $component = $component->set('selectedIndices', [0, 2])->call('submitAnswer');

        self::assertTrue($component->component()->feedback['correct']);
        $renderedFeedback = (string) $component->render();
        self::assertStringContainsString('A and C are correct.', $renderedFeedback);
        self::assertStringNotContainsString('Quiz terminé', $renderedFeedback);

        $component = $component->call('next');

        $attempt = $component->component()->getAttempt();
        self::assertTrue($attempt->isComplete());
        self::assertSame(2, $attempt->getScore());
        self::assertNull($component->component()->getCurrentQuestion());
        self::assertStringContainsString('Quiz terminé', (string) $component->render());
    }

    public function testStartWithoutADocumentShowsAnError(): void
    {
        $component = $this->createLiveComponent('Rag:QuizComponent', client: $this->client)->call('start');

        self::assertNotNull($component->component()->error);
        self::assertNull($component->component()->attemptId);
    }
}
