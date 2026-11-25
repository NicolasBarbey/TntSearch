<?php

declare(strict_types=1);

namespace TntSearch\Search;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchDocumentWalk;
use Thelia\Domain\Search\SearchIndexer;
use TntSearch\Search\Message\RebuildCatalogue;
use TntSearch\TntSearch;

#[Autoconfigure(public: true)]
readonly class CatalogueReindexer
{
    public const string QUEUED = 'catalogue_rebuild_queued';

    public const int REQUEUE_AFTER_SECONDS = 21600;

    private const int BATCH_SIZE = 100;

    public function __construct(
        private SearchDocumentProviders $providers,
        private SearchIndexer $indexer,
        private TntSearchEngine $engine,
        private CatalogueCompletion $completion,
        private MessageBusInterface $bus,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        #[Autowire('%env(default:thelia.messenger.inline_transport_dsn:MESSENGER_TRANSPORT_DSN)%')]
        #[\SensitiveParameter]
        private string $jobQueueDsn = 'sync://',
    ) {
    }

    public function rebuild(): void
    {
        $this->completion->startRebuild();

        foreach ($this->batches() as [$family, $ids]) {
            $this->indexer->synchronize($family, $ids);
        }

        $this->completion->mark();
    }

    public function queueOnceWhenMissing(): void
    {
        if ($this->completion->isEveryFamilyMarked() || $this->engine->isCompleteInEveryIndexedLocale()) {
            return;
        }

        $lock = $this->lockFactory->createLock('tntsearch_catalogue_rebuild', 300);

        if (!$lock->acquire()) {
            return;
        }

        try {
            if (self::alreadyQueued()) {
                return;
            }

            if ('' === $this->jobQueueDsn || str_starts_with($this->jobQueueDsn, 'sync://')) {
                TntSearch::setConfigValue(self::QUEUED, (string) time());
                $this->logger->warning('TntSearch: the catalogue is not indexed and no message queue is configured, run thelia:search:reindex; the database answers the searches meanwhile.');

                return;
            }

            $this->completion->startRebuild();
            $this->queue(new RebuildCatalogue($this->providers->families()));
            TntSearch::setConfigValue(self::QUEUED, (string) time());
        } finally {
            $lock->release();
        }
    }

    public function continueRebuild(RebuildCatalogue $message): void
    {
        $families = $message->families;
        $afterId = $message->afterId;

        while ([] !== $families) {
            $provider = $this->providers->get($families[0]);
            $ids = null === $provider ? [] : SearchDocumentWalk::idsAfter($provider, $afterId, self::BATCH_SIZE);

            if ([] !== $ids) {
                try {
                    $this->indexer->synchronize($families[0], $ids);
                } catch (\Throwable $exception) {
                    $this->completion->recordFailure($families[0]);
                    $this->logger->error('TntSearch: a rebuild batch of {family} after #{after} failed ({exception}), the rebuild goes on and leaves {family} to the database until the next one.', [
                        'family' => $families[0],
                        'after' => $afterId,
                        'exception' => $exception::class,
                    ]);
                }

                $this->queue(new RebuildCatalogue($families, max($ids)));

                return;
            }

            array_shift($families);
            $afterId = 0;
        }

        $failed = $this->completion->mark();

        if ([] !== $failed) {
            $this->logger->warning('TntSearch: a batch of {families} failed during the rebuild, their index is not marked complete and the database answers their searches; run thelia:search:reindex.', ['families' => implode(', ', $failed)]);
        }
    }

    private function queue(RebuildCatalogue $message): void
    {
        $this->bus->dispatch(new Envelope($message, [new TransportNamesStamp([SearchReindexCommand::QUEUE_TRANSPORT])]));
    }

    private static function alreadyQueued(): bool
    {
        $queuedAt = (string) TntSearch::getConfigValue(self::QUEUED, '');

        return '' !== $queuedAt && time() - (int) $queuedAt < self::REQUEUE_AFTER_SECONDS;
    }

    /**
     * @return iterable<array{string, list<int>}>
     */
    private function batches(): iterable
    {
        foreach ($this->providers->families() as $family) {
            $provider = $this->providers->get($family);

            if (null === $provider) {
                continue;
            }

            foreach (SearchDocumentWalk::batches($provider, 0, self::BATCH_SIZE) as $ids) {
                yield [$family, $ids];
            }
        }
    }
}
