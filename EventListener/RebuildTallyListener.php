<?php

declare(strict_types=1);

namespace TntSearch\EventListener;

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
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
        $this->count($event->getEnvelope());
    }

    #[AsEventListener(event: WorkerMessageFailedEvent::class)]
    public function countFailedBatch(WorkerMessageFailedEvent $event): void
    {
        if (!$event->willRetry()) {
            $this->count($event->getEnvelope());
        }
    }

    private function count(Envelope $envelope): void
    {
        $stamp = $envelope->last(RebuildRunStamp::class);

        if (!$stamp instanceof RebuildRunStamp) {
            return;
        }

        try {
            $this->tally->count($stamp->run);
        } catch (\Throwable $exception) {
            $this->logger->error('TntSearch: a rebuild batch could not be counted ({exception}), the rebuild will not be marked complete.', ['exception' => $exception::class]);
        }
    }
}
