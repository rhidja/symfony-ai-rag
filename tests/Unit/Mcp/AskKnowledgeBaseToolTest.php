<?php

namespace App\Tests\Unit\Mcp;

use App\Mcp\AskKnowledgeBaseTool;
use App\Rag\RagQueryService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Uid\Uuid;

final class AskKnowledgeBaseToolTest extends TestCase
{
    public function testAskReturnsAnswerAndSourcesAsArray(): void
    {
        $document = new VectorDocument(
            Uuid::v4(),
            new Vector([0.1]),
            new Metadata([
                Metadata::KEY_SOURCE => '/docs/manual.pdf',
                Metadata::KEY_TEXT => 'Reset instructions.',
            ]),
        );

        $vectorizer = $this->createMock(VectorizerInterface::class);
        $vectorizer->method('vectorize')->willReturn(new Vector([0.1]));

        $store = $this->createMock(StoreInterface::class);
        $store->method('query')->willReturn([$document]);

        $platform = new InMemoryPlatform('Hold the button for 5 seconds.');

        $ragQueryService = new RagQueryService($vectorizer, $store, $platform, 'gpt-4o-mini', 5);

        $tool = new AskKnowledgeBaseTool($ragQueryService);

        $result = $tool->ask('How do I reset the device?');

        self::assertSame([
            'answer' => 'Hold the button for 5 seconds.',
            'sources' => ['/docs/manual.pdf'],
        ], $result);
    }
}
