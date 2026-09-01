<?php

namespace App\Rag\Ocr;

use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts text from a single PDF page by rasterizing it (pdftoppm) and
 * running Tesseract OCR on the resulting image.
 *
 * Used as a fallback by PdfLoader for pages that carry no embedded text
 * (i.e. scanned pages).
 */
final class PdfPageOcrExtractor
{
    public function __construct(
        private readonly string $languages = 'fra+eng',
        private readonly int $resolution = 300,
    ) {
    }

    public function extractPageText(string $pdfPath, int $pageNumber): string
    {
        $tmpDir = sys_get_temp_dir().'/rag-ocr-'.bin2hex(random_bytes(8));

        if (!mkdir($tmpDir) && !is_dir($tmpDir)) {
            throw new RuntimeException(\sprintf('Unable to create temporary directory "%s" for OCR.', $tmpDir));
        }

        try {
            $imagePrefix = $tmpDir.'/page';

            $this->runProcess([
                'pdftoppm',
                '-f', (string) $pageNumber,
                '-l', (string) $pageNumber,
                '-r', (string) $this->resolution,
                '-png',
                '-singlefile',
                $pdfPath,
                $imagePrefix,
            ], \sprintf('rasterize page %d of "%s"', $pageNumber, $pdfPath));

            $imagePath = $imagePrefix.'.png';
            if (!is_file($imagePath)) {
                return '';
            }

            $process = $this->runProcess([
                'tesseract',
                $imagePath,
                'stdout',
                '-l', $this->languages,
            ], \sprintf('OCR page %d of "%s"', $pageNumber, $pdfPath));

            return trim($process->getOutput());
        } finally {
            $this->cleanup($tmpDir);
        }
    }

    /**
     * @param list<string> $command
     */
    private function runProcess(array $command, string $action): Process
    {
        $process = new Process($command);
        $process->setTimeout(120);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException(\sprintf('Failed to %s: %s', $action, $process->getErrorOutput() ?: $process->getOutput()));
        }

        return $process;
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
