<?php

namespace App\Tests\Functional\Controller\Rag;

use App\Service\Rag\RagAgentQueryService;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Source\Source;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RagAskControllerTest extends WebTestCase
{
    public function testAskReturnsAnswerAndSources(): void
    {
        $client = static::createClient();

        $sources = new SourceCollection([
            new Source('/docs/manual.pdf', '/docs/manual.pdf', 'Reset instructions.'),
        ]);

        $result = $this->createMock(ResultInterface::class);
        $result->method('getContent')->willReturn('Hold the button for 5 seconds.');
        $result->method('getMetadata')->willReturn(new Metadata(['sources' => $sources]));

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn($result);

        static::getContainer()->set(
            RagAgentQueryService::class,
            new RagAgentQueryService($agent),
        );

        $client->request(
            'POST',
            '/api/rag/ask',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['question' => 'How do I reset the device?']),
        );

        self::assertResponseIsSuccessful();

        $data = json_decode($client->getResponse()->getContent(), true);

        self::assertSame('Hold the button for 5 seconds.', $data['answer']);
        self::assertSame(['/docs/manual.pdf'], $data['sources']);
    }

    public function testAskReturnsValidationErrorForEmptyQuestion(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/rag/ask',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['question' => '']),
        );

        self::assertResponseStatusCodeSame(400);
    }

    public function testAskReturnsBadRequestForInvalidJson(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/rag/ask',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: 'this is not json',
        );

        self::assertResponseStatusCodeSame(400);
    }
}
