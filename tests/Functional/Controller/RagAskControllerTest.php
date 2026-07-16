<?php

namespace App\Tests\Functional\Controller;

use App\Rag\RagQueryService;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\RetrieverInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Uid\Uuid;

final class RagAskControllerTest extends WebTestCase
{
    public function testAskReturnsAnswerAndSources(): void
    {
        $client = static::createClient();

        $document = new VectorDocument(
            Uuid::v4(),
            new Vector([0.1, 0.2]),
            new Metadata([
                Metadata::KEY_SOURCE => '/docs/manual.pdf',
                Metadata::KEY_TEXT => 'Reset instructions.',
            ]),
        );

        $retriever = $this->createMock(RetrieverInterface::class);
        $retriever->method('retrieve')->willReturn([$document]);

        $platform = new InMemoryPlatform('Hold the button for 5 seconds.');

        static::getContainer()->set(
            RagQueryService::class,
            new RagQueryService($retriever, $platform, 'gpt-4o-mini', 5),
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
