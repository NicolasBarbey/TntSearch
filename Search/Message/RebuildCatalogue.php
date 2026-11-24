<?php

declare(strict_types=1);

namespace TntSearch\Search\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class RebuildCatalogue
{
    /**
     * @param list<string> $families
     */
    public function __construct(
        public array $families,
        public int $afterId = 0,
    ) {
    }
}
