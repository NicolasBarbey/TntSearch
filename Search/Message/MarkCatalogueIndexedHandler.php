<?php

declare(strict_types=1);

namespace TntSearch\Search\Message;

use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Command\SearchReindexCommand;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\RebuildTally;

#[AsMessageHandler]
final readonly class MarkCatalogueIndexedHandler
{
    public const int DELAY_MILLISECONDS = 30_000;

    public const int MAX_POSTPONEMENTS = 120;

    public function __construct(
        private CatalogueCompletion $completion,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private RebuildTally $tally,
    ) {
    }

    public function __invoke(MarkCatalogueIndexed $message): void
    {
        $tally = $this->tally->of($message->run);

        if (null === $tally) {
            $this->logger->info('TntSearch: the rebuild {run} is no longer counted, another marker already marked it or gave up.', ['run' => $message->run]);

            return;
        }

        if ($tally['closed'] && $tally['done'] >= $tally['sent']) {
            foreach ($tally['failed'] as $family) {
                $this->completion->recordFailure($family);
            }

            $failed = $this->completion->mark($tally['families'], $tally['locales']);
            $this->tally->finish($message->run);

            if ([] !== $failed) {
                $this->logger->warning('TntSearch: a batch of {families} failed during the rebuild, their index is not marked complete and the database answers their searches; run thelia:search:reindex.', ['families' => implode(', ', $failed)]);
            }

            return;
        }

        if ($message->postponements >= self::MAX_POSTPONEMENTS) {
            $this->tally->finish($message->run);
            $this->logger->warning('TntSearch: {waiting} catalogue batches of {sent} were still waiting after {count} checks, the index is not marked complete and the database answers the searches; run thelia:search:reindex.', [
                'waiting' => $tally['sent'] - $tally['done'],
                'sent' => $tally['sent'],
                'count' => $message->postponements,
            ]);

            return;
        }

        $this->bus->dispatch(new Envelope(
            new MarkCatalogueIndexed($message->run, $message->postponements + 1),
            [new DelayStamp(self::DELAY_MILLISECONDS), new TransportNamesStamp([SearchReindexCommand::QUEUE_TRANSPORT])],
        ));
    }
}
