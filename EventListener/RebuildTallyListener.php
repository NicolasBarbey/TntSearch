<?php

declare(strict_types=1);

namespace TntSearch\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\Message\IndexDocument;
use TntSearch\Search\Message\RebuildRunStamp;
use TntSearch\Search\RebuildTally;

final readonly class RebuildTallyListener
{
    public function __construct(
        private RebuildTally $tally,
        private LoggerInterface $logger,
    ) {
    }

    #[AsEventListener(event: WorkerMessageHandledEvent::class)]
    public function countHandledBatch(WorkerMessageHandledEvent $event): void
    {
        $this->count($event->getEnvelope(), $event->getReceiverName(), false);
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function countFailedBatch(WorkerMessageFailedEvent $event): void
    {
        if (!$event->willRetry()) {
            $this->count($event->getEnvelope(), $event->getReceiverName(), true);
        }
    }

    private function count(Envelope $envelope, string $receiver, bool $failed): void
    {
        $stamp = $envelope->last(RebuildRunStamp::class);
        $message = $envelope->getMessage();

        if (!$stamp instanceof RebuildRunStamp || SearchReindexCommand::QUEUE_TRANSPORT !== $receiver) {
            return;
        }

        try {
            $this->tally->count($stamp->run, $failed && $message instanceof IndexDocument ? $message->family : null);
        } catch (\Throwable $exception) {
            $this->logger->error('TntSearch: a rebuild batch could not be counted ({exception}), the rebuild will not be marked complete.', ['exception' => $exception::class]);
        }
    }
}
