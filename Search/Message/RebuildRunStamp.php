<?php

declare(strict_types=1);

namespace TntSearch\Search\Message;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Messenger\Stamp\StampInterface;

#[Exclude]
final readonly class RebuildRunStamp implements StampInterface
{
    public function __construct(public string $run)
    {
    }
}
