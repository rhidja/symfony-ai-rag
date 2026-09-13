<?php

namespace App\Service\Rag\Loader;

use App\Service\Rag\Ocr\PdfPageOcrExtractor;
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
 *
 * Pages that carry no embedded text (i.e. scanned pages) are OCR'd via
 * PdfPageOcrExtractor, so mixed and fully-scanned PDFs are also indexed.
 */
#[AutoconfigureTag('app.rag.loader', ['extension' => 'pdf'])]
final class PdfLoader implements LoaderInterface
{
    public function __construct(
        private readonly PdfParser $parser = new PdfParser(),
        private readonly PdfPageOcrExtractor $ocr = new PdfPageOcrExtractor(),
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
            $document = $this->parser->parseFile($source);
            $pages = $document->getPages();
            $details = $document->getDetails();
        } catch (\Exception $e) {
            throw new RuntimeException(\sprintf('Unable to extract text from PDF "%s": %s', $source, $e->getMessage()), previous: $e);
        }

        $pageTexts = [];
        $scannedPages = [];
        foreach ($pages as $index => $page) {
            $pageNumber = $index + 1;
            $pageText = trim($page->getText());

            if ('' === $pageText) {
                $scannedPages[] = $pageNumber;
            }

            $pageTexts[$pageNumber] = $pageText;
        }

        foreach ($this->ocr->extractPagesText($source, $scannedPages) as $pageNumber => $ocrText) {
            $pageTexts[$pageNumber] = trim($ocrText);
        }

        $textParts = array_filter($pageTexts, static fn (string $pageText): bool => '' !== $pageText);

        $text = trim(implode("\n\n", $textParts));

        if ('' === $text) {
            return;
        }

        $metadata = new Metadata([
            Metadata::KEY_SOURCE => $source,
        ]);

        $title = $this->sanitize($details['Title'] ?? null);
        if (null !== $title) {
            $metadata->setTitle($title);
        }

        $author = $this->sanitize($details['Author'] ?? null);
        if (null !== $author) {
            $metadata['_author'] = $author;
        }

        yield new TextDocument(Uuid::v4(), $text, $metadata);
    }

    private function sanitize(mixed $value): ?string
    {
        if (!\is_string($value)) {
            return null;
        }

        $value = trim(str_replace("\0", '', $value));

        return '' !== $value ? $value : null;
    }
}
