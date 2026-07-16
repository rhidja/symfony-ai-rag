<?php

namespace App\Tests\Integration\Rag;

use App\Rag\RagQueryService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Test\InMemoryPlatform;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\VectorQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Uid\Uuid;

final class RagQueryServiceTest extends TestCase
{
    public function testAskReturnsAnswerWithSourcesWhenDocumentsFound(): void
    {
        $questionVector = new Vector([0.1, 0.2, 0.3]);

        $document = new VectorDocument(
            Uuid::v4(),
            new Vector([0.1, 0.2, 0.3]),
            new Metadata([
                Metadata::KEY_SOURCE => '/docs/manual.pdf',
                Metadata::KEY_TEXT => 'The manual explains how to reset the device.',
            ]),
        );

        $vectorizer = $this->createMock(VectorizerInterface::class);
        $vectorizer->expects(self::once())
            ->method('vectorize')
            ->with('How do I reset the device?')
            ->willReturn($questionVector);

        $store = $this->createMock(StoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->with(self::isInstanceOf(VectorQuery::class), ['limit' => 5])
            ->willReturn([$document]);

        $platform = new InMemoryPlatform('You can reset the device by holding the button. [/docs/manual.pdf]');

        $service = new RagQueryService($vectorizer, $store, $platform, 'gpt-4o-mini', 5);

        $answer = $service->ask('How do I reset the device?');

        self::assertStringContainsString('reset the device', $answer->answer);
        self::assertSame(['/docs/manual.pdf'], $answer->sources);
    }

    public function testAskReturnsNotFoundMessageWhenNoDocumentsRetrieved(): void
    {
        $vectorizer = $this->createMock(VectorizerInterface::class);
        $vectorizer->method('vectorize')->willReturn(new Vector([0.1]));

        $store = $this->createMock(StoreInterface::class);
        $store->expects(self::once())
            ->method('query')
            ->willReturn([]);

        $platform = new InMemoryPlatform(static function (): never {
            self::fail('The platform should not be called when no documents are retrieved.');
        });

        $service = new RagQueryService($vectorizer, $store, $platform, 'gpt-4o-mini', 5);

        $answer = $service->ask('What is the meaning of life?');

        self::assertSame([], $answer->sources);
        self::assertStringContainsString("Je ne trouve pas d'information", $answer->answer);
    }
}
