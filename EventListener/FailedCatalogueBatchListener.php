<?php

declare(strict_types=1);

namespace TntSearch\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\Message\IndexDocument;
use TntSearch\Search\DocumentIndexes;
use TntSearch\Search\Message\RebuildCatalogue;
use TntSearch\Search\SearchMemo;

final readonly class FailedCatalogueBatchListener
{
    public function __construct(
        private DocumentIndexes $indexes,
        private SearchMemo $memo,
    ) {
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function forgetTheCompletionOfItsFamily(WorkerMessageFailedEvent $event): void
    {
        $message = $event->getEnvelope()->getMessage();

        $family = match (true) {
            $message instanceof IndexDocument => $message->family,
            $message instanceof RebuildCatalogue => $message->families[0] ?? null,
            default => null,
        };

        if (null === $family || $event->willRetry() || SearchReindexCommand::QUEUE_TRANSPORT !== $event->getReceiverName()) {
            return;
        }

        $this->indexes->recordFailure($family);
        $this->indexes->forgetCompletion($family);
        $this->memo->reset();
    }
}
