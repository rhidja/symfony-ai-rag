<?php

namespace App\Rag\Loader;

use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;

/**
 * Dispatches loading to a sub-loader chosen by the source file's extension.
 */
final class ExtensionAwareLoader implements LoaderInterface
{
    /**
     * @param array<string, LoaderInterface> $loaders map of lowercase file extension (without leading dot) to loader
     */
    public function __construct(
        private readonly array $loaders,
    ) {
    }

    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('ExtensionAwareLoader requires a file path as source, null given.');
        }

        $extension = strtolower(pathinfo($source, \PATHINFO_EXTENSION));

        if (!isset($this->loaders[$extension])) {
            throw new InvalidArgumentException(\sprintf('No loader registered for extension "%s".', $extension));
        }

        yield from $this->loaders[$extension]->load($source, $options);
    }
}
