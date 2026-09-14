<?php

namespace App\Tests\Functional\Controller\Rag;

use App\Service\Rag\RagAgentQueryService;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Source\Source;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class RagHistoryControllerTest extends WebTestCase
{
    public function testHistoryTracksAndClearsQuestionsForTheSameSession(): void
    {
        $client = static::createClient();
        // This test chains several requests and relies on the RagAgentQueryService
        // override below staying in effect for all of them — by default the test
        // client reboots the kernel (and its container) on every request.
        $client->disableReboot();

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

        // Empty history before any question has been asked in this session.
        $client->request('GET', '/api/rag/history');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));

        $client->request(
            'POST',
            '/api/rag/ask',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['question' => 'How do I reset the device?']),
        );
        self::assertResponseIsSuccessful();

        $client->request('GET', '/api/rag/history');
        self::assertResponseIsSuccessful();

        $history = json_decode($client->getResponse()->getContent(), true);
        self::assertCount(1, $history);
        self::assertSame('How do I reset the device?', $history[0]['question']);
        self::assertSame('Hold the button for 5 seconds.', $history[0]['answer']);
        self::assertSame(['/docs/manual.pdf'], $history[0]['sources']);
        self::assertArrayHasKey('createdAt', $history[0]);

        $client->request('DELETE', '/api/rag/history');
        self::assertResponseStatusCodeSame(204);

        $client->request('GET', '/api/rag/history');
        self::assertResponseIsSuccessful();
        self::assertSame([], json_decode($client->getResponse()->getContent(), true));
    }
}
