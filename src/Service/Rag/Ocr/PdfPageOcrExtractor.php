<?php

namespace App\Service\Rag\Ocr;

use Psr\Cache\CacheItemPoolInterface;
use Symfony\AI\Store\Exception\RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Extracts text from PDF pages by rasterizing them (pdftoppm) and running
 * Tesseract OCR on the resulting images.
 *
 * Used as a fallback by PdfLoader for pages that carry no embedded text
 * (i.e. scanned pages). Pages are processed concurrently (bounded worker
 * pool of subprocesses) and results are cached per page, so re-ingesting
 * an unchanged file skips OCR entirely.
 */
final class PdfPageOcrExtractor
{
    public function __construct(
        private readonly string $languages = 'fra+eng',
        private readonly int $resolution = 300,
        private readonly int $maxConcurrency = 4,
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {
    }

    /**
     * @param list<int> $pageNumbers 1-based page numbers to OCR
     *
     * @return array<int, string> page number => OCR'd text, in ascending page order
     */
    public function extractPagesText(string $pdfPath, array $pageNumbers): array
    {
        if ([] === $pageNumbers) {
            return [];
        }

        $results = [];
        $cacheItems = [];
        $toProcess = [];

        foreach ($pageNumbers as $pageNumber) {
            $item = $this->cache?->getItem($this->cacheKey($pdfPath, $pageNumber));

            if (null !== $item && $item->isHit()) {
                $results[$pageNumber] = $item->get();
                continue;
            }

            $cacheItems[$pageNumber] = $item;
            $toProcess[] = $pageNumber;
        }

        foreach ($this->runOcrJobs($pdfPath, $toProcess) as $pageNumber => $text) {
            $results[$pageNumber] = $text;

            if (null !== ($item = $cacheItems[$pageNumber] ?? null)) {
                $this->cache->save($item->set($text));
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Runs OCR for the given pages using a bounded pool of concurrent
     * subprocesses (rasterization + Tesseract chained per page).
     *
     * @param list<int> $pageNumbers
     *
     * @return array<int, string>
     */
    private function runOcrJobs(string $pdfPath, array $pageNumbers): array
    {
        if ([] === $pageNumbers) {
            return [];
        }

        $pending = [];
        foreach ($pageNumbers as $pageNumber) {
            $pending[$pageNumber] = $this->buildProcess($pdfPath, $pageNumber);
        }

        $results = [];
        $running = [];

        while ([] !== $pending || [] !== $running) {
            while (\count($running) < $this->maxConcurrency && [] !== $pending) {
                $pageNumber = array_key_first($pending);
                $job = $pending[$pageNumber];
                unset($pending[$pageNumber]);

                $job['process']->start();
                $running[$pageNumber] = $job;
            }

            foreach ($running as $pageNumber => $job) {
                if ($job['process']->isRunning()) {
                    continue;
                }

                unset($running[$pageNumber]);

                $process = $job['process'];
                $successful = $process->isSuccessful();
                $output = $successful ? trim($process->getOutput()) : null;
                $errorOutput = $process->getErrorOutput();

                $this->cleanup($job['tmpDir']);

                if (!$successful) {
                    throw new RuntimeException(\sprintf('Failed to OCR page %d of "%s": %s', $pageNumber, $pdfPath, $errorOutput ?: $process->getOutput()));
                }

                $results[$pageNumber] = $output;
            }

            if ([] !== $running) {
                usleep(20_000);
            }
        }

        return $results;
    }

    /**
     * @return array{process: Process, tmpDir: string}
     */
    private function buildProcess(string $pdfPath, int $pageNumber): array
    {
        $tmpDir = sys_get_temp_dir().'/rag-ocr-'.bin2hex(random_bytes(8));

        if (!mkdir($tmpDir) && !is_dir($tmpDir)) {
            throw new RuntimeException(\sprintf('Unable to create temporary directory "%s" for OCR.', $tmpDir));
        }

        $imagePrefix = $tmpDir.'/page';

        // Rasterization and OCR are chained in a single shell process (one
        // pool slot per page) rather than run as two Process objects, since
        // piping the image straight into Tesseract via stdin is unreliable.
        $command = \sprintf(
            'pdftoppm -f %d -l %d -r %d -png -singlefile %s %s && tesseract %s stdout -l %s',
            $pageNumber,
            $pageNumber,
            $this->resolution,
            escapeshellarg($pdfPath),
            escapeshellarg($imagePrefix),
            escapeshellarg($imagePrefix.'.png'),
            escapeshellarg($this->languages),
        );

        $process = Process::fromShellCommandline($command);
        $process->setTimeout(120);

        return ['process' => $process, 'tmpDir' => $tmpDir];
    }

    private function cacheKey(string $pdfPath, int $pageNumber): string
    {
        $fingerprint = $pdfPath;

        if (is_file($pdfPath)) {
            $fingerprint .= '|'.filemtime($pdfPath).'|'.filesize($pdfPath);
        }

        $fingerprint .= '|'.$pageNumber.'|'.$this->resolution.'|'.$this->languages;

        return 'rag_ocr_'.hash('xxh128', $fingerprint);
    }

    private function cleanup(string $dir): void
    {
        foreach (glob($dir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
    }
}
