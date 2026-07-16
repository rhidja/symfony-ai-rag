<?php

namespace App\Mcp;

use Mcp\Capability\Attribute\McpTool;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Finder\Finder;

/**
 * Lists PDF/DOCX/CSV/XLSX files found on disk in the documents directory. Independent of
 * the RAG vector store - reflects what's on disk, not what has been ingested.
 */
final class ListDocumentsTool
{
    private const DOCUMENTS_SUBDIR = 'var/ebook';

    public function __construct(
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{documents: list<array{filename: string, size_bytes: int}>}
     */
    #[McpTool(
        name: 'list_documents',
        description: 'Lists the PDF/DOCX/CSV/XLSX files available in the documents directory (var/ebook), independently of the RAG index.',
    )]
    public function list(): array
    {
        $directory = $this->projectDir.'/'.self::DOCUMENTS_SUBDIR;

        if (!is_dir($directory)) {
            return ['documents' => []];
        }

        $finder = (new Finder())
            ->files()
            ->in($directory)
            ->name(['*.pdf', '*.docx', '*.csv', '*.xlsx'])
            ->sortByName();

        $documents = [];
        foreach ($finder as $file) {
            $documents[] = [
                'filename' => $file->getRelativePathname(),
                'size_bytes' => $file->getSize(),
            ];
        }

        return ['documents' => $documents];
    }
}
