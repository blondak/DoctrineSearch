<?php
namespace Kdyby\DoctrineSearch\DI;

use Kdyby;
use Nette;

/**
 * Mapping definition can be
 * - absolute directory path __DIR__
 * - array of absolute directory path [__DIR__]
 * - DI\Statement instance with mapping type as entity new DI\Statement('annotations', [__DIR__])
 *
 */
interface ISearchMetadataProvider
{

    /**
     * Returns associative array of Namespace => mapping definition
     *
     * @return array
     */
    public function getSearchMetadataMappings();

}
