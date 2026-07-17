<?php

namespace App\Rag\Loader;

use Psr\Container\ContainerInterface;
use Symfony\AI\Store\Document\LoaderInterface;
use Symfony\AI\Store\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/**
 * Dispatches loading to a sub-loader chosen by the source file's extension.
 *
 * Loaders are auto-discovered via #[AutoconfigureTag('app.rag.loader', ['extension' => ...])]
 * on each loader class - adding a new format only requires tagging the new loader, no wiring here.
 */
final class ExtensionAwareLoader implements LoaderInterface
{
    public function __construct(
        #[AutowireLocator('app.rag.loader', indexAttribute: 'extension')]
        private readonly ContainerInterface $loaders,
    ) {
    }

    public function load(?string $source = null, array $options = []): iterable
    {
        if (null === $source) {
            throw new InvalidArgumentException('ExtensionAwareLoader requires a file path as source, null given.');
        }

        $extension = strtolower(pathinfo($source, \PATHINFO_EXTENSION));

        if (!$this->loaders->has($extension)) {
            throw new InvalidArgumentException(\sprintf('No loader registered for extension "%s".', $extension));
        }

        yield from $this->loaders->get($extension)->load($source, $options);
    }
}
