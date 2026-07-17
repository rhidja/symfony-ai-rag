<?php

namespace App\Rag\Loader;

use Smalot\PdfParser\Parser as PdfParser;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Extracts text from a PDF file into a single TextDocument.
 */
#[AutoconfigureTag('app.rag.loader', ['extension' => 'pdf'])]
final class PdfLoader implements LoaderInterface
{
    public function __construct(
        private readonly PdfParser $parser = new PdfParser(),
    ) {
    }

    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('PdfLoader requires a file path as source, null given.');
        }

        if (!is_file($source)) {
            throw new RuntimeException(\sprintf('File "%s" does not exist.', $source));
        }

        try {
            $text = $this->parser->parseFile($source)->getText();
        } catch (\Exception $e) {
            throw new RuntimeException(\sprintf('Unable to extract text from PDF "%s": %s', $source, $e->getMessage()), previous: $e);
        }

        $text = trim($text);

        if ('' === $text) {
            return;
        }

        yield new TextDocument(Uuid::v4(), $text, new Metadata([
            Metadata::KEY_SOURCE => $source,
        ]));
    }
}
