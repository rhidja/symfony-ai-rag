<?php

namespace App\Rag\Loader;

use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Symfony\Component\Uid\Uuid;

/**
 * Converts a whole CSV file into a single TextDocument, one line of text per row
 * (header: value, header: value, ...), consistent with how PdfLoader/DocxLoader
 * treat a whole file as one document.
 */
#[AutoconfigureTag('app.rag.loader', ['extension' => 'csv'])]
final class CsvLoader implements LoaderInterface
{
    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('CsvLoader requires a file path as source, null given.');
        }

        if (!is_file($source)) {
            throw new RuntimeException(\sprintf('File "%s" does not exist.', $source));
        }

        $handle = fopen($source, 'r');
        if (false === $handle) {
            throw new RuntimeException(\sprintf('Unable to open file "%s".', $source));
        }

        $lines = [];
        $headers = null;

        try {
            while (false !== ($row = fgetcsv($handle, escape: ''))) {
                if ([null] === $row) {
                    continue;
                }

                if (null === $headers) {
                    $headers = $row;
                    continue;
                }

                $row = array_pad($row, \count($headers), '');
                $pairs = [];
                foreach ($headers as $i => $header) {
                    $pairs[] = trim((string) $header).': '.trim((string) ($row[$i] ?? ''));
                }

                $lines[] = implode(', ', $pairs);
            }
        } finally {
            fclose($handle);
        }

        $text = trim(implode("\n", $lines));

        if ('' === $text) {
            return;
        }

        yield new TextDocument(Uuid::v4(), $text, new Metadata([
            Metadata::KEY_SOURCE => $source,
        ]));
    }
}
