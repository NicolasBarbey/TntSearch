<?php

declare(strict_types=1);

namespace TntSearch\EventListener;

use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
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

    private ?string $run = null;

    private int $batches = 0;

    public function __construct(
        private readonly CatalogueCompletion $completion,
        private readonly MessageBusInterface $bus,
        private readonly RebuildTally $tally,
        #[Autowire('%env(default:thelia.messenger.inline_transport_dsn:MESSENGER_TRANSPORT_DSN)%')]
        #[\SensitiveParameter]
        private readonly string $jobQueueDsn = 'sync://',
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

        /** @var list<string> $families */
        $families = array_values((array) $event->getInput()->getOption('family'));
        $this->completion->startRebuild($families);

        if (true === $event->getInput()->getOption('queue') && $this->queueIsAsynchronous()) {
            $this->run = bin2hex(random_bytes(16));
            $this->batches = 0;
            $this->tally->start($this->run);
        }
    }

    public function stampQueuedBatch(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();

        if (null === $this->run || !$envelope->getMessage() instanceof IndexDocument || !\array_key_exists(SearchReindexCommand::QUEUE_TRANSPORT, $event->getSenders())) {
            return;
        }

        $event->setEnvelope($envelope->with(new RebuildRunStamp($this->run)));
        ++$this->batches;
    }

    public function onReindexEnd(ConsoleTerminateEvent $event): void
    {
        $input = $event->getInput();

        if (self::REINDEX_COMMAND !== $event->getCommand()?->getName()) {
            return;
        }

        $run = $this->run;
        $this->run = null;

        if (0 !== $event->getExitCode()) {
            if (null !== $run) {
                $this->tally->finish($run);
            }

            return;
        }

        /** @var list<string> $families */
        $families = array_values((array) $input->getOption('family'));
        /** @var list<string> $locales */
        $locales = array_values((array) $input->getOption('locale'));

        if (null === $run) {
            $this->completion->mark($families, [] === $locales ? null : $locales);

            return;
        }

        $this->tally->expect($run, $this->batches);
        $this->bus->dispatch(new Envelope(
            new MarkCatalogueIndexed($families, [] === $locales ? null : $locales, 0, $run),
            [new TransportNamesStamp([SearchReindexCommand::QUEUE_TRANSPORT])],
        ));
    }

    private function queueIsAsynchronous(): bool
    {
        return '' !== $this->jobQueueDsn && !str_starts_with($this->jobQueueDsn, 'sync://');
    }
}
