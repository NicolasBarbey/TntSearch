<?php

declare(strict_types=1);

namespace TntSearch\Search\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

#[Exclude]
final readonly class MarkCatalogueIndexed
{
    public function __construct(
        public string $run,
        public int $postponements = 0,
    ) {
    }
}
