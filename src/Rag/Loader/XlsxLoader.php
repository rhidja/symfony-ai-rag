<?php

namespace App\Rag\Loader;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * Converts a whole XLSX file into a single TextDocument: for each sheet, the header
 * row labels each subsequent row's cells, one line of text per row.
 */
final class XlsxLoader implements LoaderInterface
{
    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('XlsxLoader requires a file path as source, null given.');
        }

        if (!is_file($source)) {
            throw new RuntimeException(\sprintf('File "%s" does not exist.', $source));
        }

        try {
            $spreadsheet = IOFactory::load($source);
        } catch (\Exception $e) {
            throw new RuntimeException(\sprintf('Unable to read XLSX "%s": %s', $source, $e->getMessage()), previous: $e);
        }

        $blocks = [];

        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $rows = $sheet->toArray(null, true, true, false);
            if ([] === $rows) {
                continue;
            }

            $headers = array_shift($rows);
            $lines = [];

            foreach ($rows as $row) {
                $pairs = [];
                foreach ($headers as $i => $header) {
                    $value = $row[$i] ?? '';
                    if ('' === trim((string) $value)) {
                        continue;
                    }
                    $pairs[] = trim((string) $header).': '.trim((string) $value);
                }

                if ([] !== $pairs) {
                    $lines[] = implode(', ', $pairs);
                }
            }

            if ([] !== $lines) {
                $blocks[] = \sprintf("Sheet: %s\n%s", $sheet->getTitle(), implode("\n", $lines));
            }
        }

        $text = trim(implode("\n\n", $blocks));

        if ('' === $text) {
            return;
        }

        yield new TextDocument(Uuid::v4(), $text, new Metadata([
            Metadata::KEY_SOURCE => $source,
        ]));
    }
}
