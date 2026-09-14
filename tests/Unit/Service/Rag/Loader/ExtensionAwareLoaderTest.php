<?php

namespace App\Tests\Unit\Service\Rag\Loader;

use App\Service\Rag\Loader\ExtensionAwareLoader;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Symfony\Component\Uid\Uuid;

final class ExtensionAwareLoaderTest extends TestCase
{
    public function testDispatchesToLoaderMatchingExtension(): void
    {
        $pdfLoader = $this->createMock(LoaderInterface::class);
        $pdfLoader->expects(self::once())
            ->method('load')
            ->with('/path/file.pdf', [])
            ->willReturn([new TextDocument(Uuid::v4(), 'pdf content', new Metadata())]);

        $docxLoader = $this->createMock(LoaderInterface::class);
        $docxLoader->expects(self::never())->method('load');

        $loader = new ExtensionAwareLoader(new ServiceLocator([
            'pdf' => static fn () => $pdfLoader,
            'docx' => static fn () => $docxLoader,
        ]));

        $documents = iterator_to_array($loader->load('/path/file.pdf'));

        self::assertCount(1, $documents);
        self::assertSame('pdf content', $documents[0]->getContent());
    }

    public function testThrowsForUnregisteredExtension(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $loader = new ExtensionAwareLoader(new ServiceLocator([
            'pdf' => fn () => $this->createMock(LoaderInterface::class),
        ]));

        iterator_to_array($loader->load('/path/file.txt'));
    }

    public function testThrowsWhenSourceIsNull(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $loader = new ExtensionAwareLoader(new ServiceLocator([]));

        iterator_to_array($loader->load(null));
    }
}
