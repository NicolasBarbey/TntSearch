<?php

declare(strict_types=1);

namespace TntSearch\Search\Message;

use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TntSearch\Search\CatalogueReindexer;

#[AsMessageHandler]
final readonly class RebuildCatalogueHandler
{
    public function __construct(private CatalogueReindexer $reindexer)
    {
    }

    public function __invoke(RebuildCatalogue $message): void
    {
        $this->reindexer->continueRebuild($message);
    }
}
