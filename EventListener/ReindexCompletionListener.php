<?php

declare(strict_types=1);

namespace TntSearch\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\Message\IndexDocument;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\Message\MarkCatalogueIndexed;
use TntSearch\Search\Message\RebuildRunStamp;
use TntSearch\Search\RebuildTally;

final class ReindexCompletionListener implements EventSubscriberInterface
{
    private const string REINDEX_COMMAND = 'thelia:search:reindex';

    private bool $queued = false;

    private ?string $run = null;

    /** @var list<string> */
    private array $families = [];

    /** @var list<string>|null */
    private ?array $locales = null;

    public function __construct(
        private readonly CatalogueCompletion $completion,
        private readonly MessageBusInterface $bus,
        private readonly RebuildTally $tally,
        #[Autowire('%env(thelia_heavy_queue:MESSENGER_TRANSPORT_DSN)%')]
        #[\SensitiveParameter]
        private readonly string $heavyQueueDsn = 'sync://',
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => ['onReindexStart', -128],
            SendMessageToTransportsEvent::class => 'stampQueuedBatch',
            ConsoleEvents::TERMINATE => ['onReindexEnd', -128],
        ];
    }

    public function onReindexStart(ConsoleCommandEvent $event): void
    {
        if (self::REINDEX_COMMAND !== $event->getCommand()?->getName()) {
            return;
        }

        $input = $event->getInput();
        $this->families = self::families($input);
        $this->locales = self::locales($input);
        $this->queued = true === $input->getOption('queue') && $this->heavyQueueIsAsynchronous();
        $this->run = null;
    }

    public function stampQueuedBatch(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (!$this->queued || !$envelope->getMessage() instanceof IndexDocument || !\array_key_exists(SearchReindexCommand::QUEUE_TRANSPORT, $event->getSenders())) {
            return;
        }

        if (null === $this->run) {
            $this->completion->startRebuild($this->families);
        }

        $this->run = $this->tally->send($this->run ?? bin2hex(random_bytes(16)), $this->families, $this->locales);
        $event->setEnvelope($envelope->with(new RebuildRunStamp($this->run)));
    }

    public function onReindexEnd(ConsoleTerminateEvent $event): void
    {
        if (self::REINDEX_COMMAND !== $event->getCommand()?->getName()) {
            return;
        }

        $queued = $this->queued;
        $this->queued = false;
        $this->run = null;

        if (0 !== $event->getExitCode()) {
            return;
        }

        $families = self::families($event->getInput());
        $locales = self::locales($event->getInput());
        $run = $queued ? $this->tally->close($families, $locales) : null;

        if (null === $run) {
            $this->completion->startRebuild($families);
            $this->completion->mark($families, $locales);

            return;
        }

        $this->bus->dispatch(new Envelope(
            new MarkCatalogueIndexed($run),
            [new TransportNamesStamp([SearchReindexCommand::QUEUE_TRANSPORT])],
        ));
    }

    /**
     * @return list<string>
     */
    private static function families(InputInterface $input): array
    {
        return array_values(array_map('strval', (array) $input->getOption('family')));
    }

    /**
     * @return list<string>|null
     */
    private static function locales(InputInterface $input): ?array
    {
        $locales = array_values(array_map('strval', (array) $input->getOption('locale')));

        return [] === $locales ? null : $locales;
    }

    private function heavyQueueIsAsynchronous(): bool
    {
        return '' !== $this->heavyQueueDsn && !str_starts_with($this->heavyQueueDsn, 'sync://');
    }
}
