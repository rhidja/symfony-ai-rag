<?php

namespace App\Mcp;

use App\Rag\Loader\ExtensionAwareLoader;
use Mcp\Capability\Attribute\McpTool;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Reads the text content of a PDF/DOCX/CSV/XLSX file from the documents directory (var/ebook),
 * by extracting it directly - independent of the RAG vector store.
 */
final class ReadDocumentTool
{
    private const DOCUMENTS_SUBDIR = 'var/ebook';
    private const DEFAULT_MAX_LENGTH = 20000;

    public function __construct(
        private readonly ExtensionAwareLoader $loader,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{filename: string, content: string, truncated: bool}
     */
    #[McpTool(
        name: 'read_document',
        description: 'Reads and returns the extracted text content of a PDF/DOCX/CSV/XLSX file from the documents directory (as listed by list_documents).',
    )]
    public function read(string $filename, int $max_length = self::DEFAULT_MAX_LENGTH): array
    {
        $directory = $this->projectDir.'/'.self::DOCUMENTS_SUBDIR;
        $path = realpath($directory.'/'.$filename);

        if (false === $path || !str_starts_with($path, realpath($directory).\DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException(\sprintf('File "%s" was not found in the documents directory.', $filename));
        }

        $documents = iterator_to_array($this->loader->load($path));
        $content = implode("\n\n", array_map(static fn ($document) => $document->getContent(), $documents));

        $truncated = mb_strlen($content) > $max_length;
        if ($truncated) {
            $content = mb_substr($content, 0, $max_length);
        }

        return [
            'filename' => $filename,
            'content' => $content,
            'truncated' => $truncated,
        ];
    }
}
