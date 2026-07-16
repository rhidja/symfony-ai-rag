<?php

namespace App\Rag\Loader;

use PhpOffice\PhpWord\Element\AbstractContainer;
use PhpOffice\PhpWord\IOFactory;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Extracts text from a DOCX file into a single TextDocument.
 */
final class DocxLoader implements LoaderInterface
{
    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('DocxLoader requires a file path as source, null given.');
        }

        if (!is_file($source)) {
            throw new RuntimeException(\sprintf('File "%s" does not exist.', $source));
        }

        try {
            $document = IOFactory::load($source, 'Word2007');
        } catch (\Exception $e) {
            throw new RuntimeException(\sprintf('Unable to extract text from DOCX "%s": %s', $source, $e->getMessage()), previous: $e);
        }

        $paragraphs = [];
        foreach ($document->getSections() as $section) {
            $this->collectText($section, $paragraphs);
        }

        $text = trim(implode("\n", $paragraphs));

        if ('' === $text) {
            return;
        }

        yield new TextDocument(Uuid::v4(), $text, new Metadata([
            Metadata::KEY_SOURCE => $source,
        ]));
    }

    /**
     * @param list<string> $paragraphs
     */
    private function collectText(AbstractContainer $container, array &$paragraphs): void
    {
        foreach ($container->getElements() as $element) {
            if (method_exists($element, 'getText')) {
                $text = $element->getText();
                if (\is_string($text) && '' !== trim($text)) {
                    $paragraphs[] = trim($text);
                }
                continue;
            }

            if ($element instanceof AbstractContainer) {
                $this->collectText($element, $paragraphs);
            }
        }
    }
}
