<?php

declare(strict_types=1);

namespace TntSearch\Tests\Integration\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleCommandEvent;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\Message\IndexDocument;
use Thelia\Domain\Search\Provider\CategoryDocumentProvider;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchLocales;
use Thelia\Test\IntegrationTestCase;
use TntSearch\EventListener\ReindexCompletionListener;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\DocumentIndexes;
use TntSearch\Search\Message\MarkCatalogueIndexed;
use TntSearch\Search\Message\RebuildRunStamp;
use TntSearch\Search\RebuildTally;
use TntSearch\Search\SearchMemo;
use TntSearch\TntSearch;

final class ReindexCompletionListenerTest extends IntegrationTestCase
{
    private const string ENVIRONMENT = 'phpunit-reindex-completion';

    /** @var list<Envelope> */
    private array $dispatched = [];

    private DocumentIndexes $indexes;

    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);
        $this->indexes = TntSearchEngineTest::indexes(self::ENVIRONMENT);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);

        parent::tearDown();
    }

    public function testAReindexThatEndedWellMarksItsFamiliesAndLocalesComplete(): void
    {
        $this->listener()->onReindexEnd($this->terminate(['--family' => ['category'], '--locale' => ['fr_FR']], Command::SUCCESS));

        self::assertTrue($this->indexes->isComplete('category', 'fr_FR'));
        self::assertFalse($this->indexes->isComplete('category', 'en_US'));
    }

    public function testAFailedReindexMarksNothing(): void
    {
        $this->listener()->onReindexEnd($this->terminate([], Command::FAILURE));

        self::assertFalse($this->indexes->isComplete('category', 'fr_FR'));
    }

    public function testAQueuedReindexStampsItsBatchesAndHandsItsRunToTheMarker(): void
    {
        $listener = $this->listener();
        $listener->onReindexStart($this->start(['--queue' => true]));
        $batches = [
            $this->send($listener, new IndexDocument('category', [1, 2]), SearchReindexCommand::QUEUE_TRANSPORT),
            $this->send($listener, new IndexDocument('category', [3]), SearchReindexCommand::QUEUE_TRANSPORT),
        ];
        $everyday = $this->send($listener, new IndexDocument('category', [4]), 'async');
        $listener->onReindexEnd($this->terminate(['--queue' => true], Command::SUCCESS));

        self::assertFalse($this->indexes->isComplete('category', 'fr_FR'));
        self::assertCount(1, $this->dispatched);
        $marker = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(MarkCatalogueIndexed::class, $marker);
        self::assertSame(['async_heavy'], $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertSame([$marker->run, $marker->run], array_map(static fn (Envelope $batch): ?string => $batch->last(RebuildRunStamp::class)?->run, $batches));
        self::assertNull($everyday->last(RebuildRunStamp::class));
        $tally = $this->tally()->of($marker->run);
        self::assertSame(2, $tally['sent'] ?? null);
        self::assertTrue($tally['closed'] ?? null);
    }

    public function testAReindexQueuedWithoutAnAsynchronousHeavyQueueIsMarkedAtItsEnd(): void
    {
        $listener = $this->listener('sync://');
        $options = ['--queue' => true, '--family' => ['category'], '--locale' => ['fr_FR']];
        $listener->onReindexStart($this->start($options));
        $batch = $this->send($listener, new IndexDocument('category', [1]), SearchReindexCommand::QUEUE_TRANSPORT);
        $listener->onReindexEnd($this->terminate($options, Command::SUCCESS));

        self::assertNull($batch->last(RebuildRunStamp::class));
        self::assertSame([], $this->dispatched);
        self::assertTrue($this->indexes->isComplete('category', 'fr_FR'));
    }

    public function testAReindexRefusedBeforeSendingAnythingLeavesTheRunInProgressAndItsFailuresAlone(): void
    {
        $running = $this->tally()->send('run-a', ['category'], null);
        $this->tally()->close(['category'], null);
        $this->indexes->recordFailure('category');

        $refused = $this->listener();
        $refused->onReindexStart($this->start(['--queue' => true, '--family' => ['category']]));
        $refused->onReindexEnd($this->terminate(['--queue' => true, '--family' => ['category']], Command::FAILURE));

        self::assertSame('run-a', $this->tally()->of($running)['run'] ?? null);
        self::assertTrue($this->indexes->hasFailed('category'));
        self::assertSame([], $this->dispatched);
    }

    public function testAQueuedReindexThatFailedLeavesItsCountOpenForTheRunThatResumesIt(): void
    {
        $interrupted = $this->listener();
        $interrupted->onReindexStart($this->start(['--queue' => true]));
        $first = $this->send($interrupted, new IndexDocument('category', [1]), SearchReindexCommand::QUEUE_TRANSPORT);
        $interrupted->onReindexEnd($this->terminate(['--queue' => true], Command::FAILURE));

        self::assertSame([], $this->dispatched);

        $resumed = $this->listener();
        $resumed->onReindexStart($this->start(['--queue' => true]));
        $second = $this->send($resumed, new IndexDocument('category', [2]), SearchReindexCommand::QUEUE_TRANSPORT);
        $resumed->onReindexEnd($this->terminate(['--queue' => true], Command::SUCCESS));

        $run = $first->last(RebuildRunStamp::class)?->run;
        self::assertNotNull($run);
        self::assertSame($run, $second->last(RebuildRunStamp::class)?->run);
        self::assertSame(2, $this->tally()->of($run)['sent'] ?? null);
        self::assertCount(1, $this->dispatched);
        self::assertSame($run, $this->dispatched[0]->getMessage()->run);
    }

    public function testAQueuedReindexWithNothingToSendWaitsForTheBatchesOfTheRunItFollows(): void
    {
        $this->tally()->send('run-a', ['category'], null);

        $listener = $this->listener();
        $listener->onReindexStart($this->start(['--queue' => true, '--family' => ['brand']]));
        $listener->onReindexEnd($this->terminate(['--queue' => true, '--family' => ['brand']], Command::SUCCESS));

        self::assertCount(1, $this->dispatched);
        self::assertSame('run-a', $this->dispatched[0]->getMessage()->run);
        self::assertSame(['category', 'brand'], $this->tally()->of('run-a')['families'] ?? null);
        self::assertFalse($this->indexes->isComplete('category', 'fr_FR'));
    }

    public function testAReindexThatEndedWellLiftsTheFailureOfItsFamiliesSoItCanMarkThem(): void
    {
        $this->indexes->recordFailure('category');

        $this->listener()->onReindexEnd($this->terminate(['--family' => ['category', '../unknown'], '--locale' => ['fr_FR']], Command::SUCCESS));

        self::assertFalse($this->indexes->hasFailed('category'));
        self::assertTrue($this->indexes->isComplete('category', 'fr_FR'));
    }

    private function tally(): RebuildTally
    {
        return new RebuildTally($this->indexes);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function start(array $options): ConsoleCommandEvent
    {
        return new ConsoleCommandEvent(new Command('thelia:search:reindex'), $this->input($options), new NullOutput());
    }

    private function send(ReindexCompletionListener $listener, object $message, string $transport): Envelope
    {
        $event = new SendMessageToTransportsEvent(new Envelope($message), [$transport => $this->createStub(SenderInterface::class)]);
        $listener->stampQueuedBatch($event);

        return $event->getEnvelope();
    }

    private function listener(string $dsn = 'doctrine://default'): ReindexCompletionListener
    {
        $dispatched = &$this->dispatched;

        return new ReindexCompletionListener(
            new CatalogueCompletion(new SearchDocumentProviders([new CategoryDocumentProvider()]), new SearchLocales(), $this->indexes, new SearchMemo()),
            new class($dispatched) implements MessageBusInterface {
                /** @param list<Envelope> $dispatched */
                public function __construct(private array &$dispatched)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    $envelope = Envelope::wrap($message, $stamps);
                    $this->dispatched[] = $envelope;

                    return $envelope;
                }
            },
            new RebuildTally($this->indexes),
            $dsn,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function terminate(array $options, int $exitCode): ConsoleTerminateEvent
    {
        return new ConsoleTerminateEvent(new Command('thelia:search:reindex'), $this->input($options), new NullOutput(), $exitCode);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function input(array $options): ArrayInput
    {
        $definition = new InputDefinition([
            new InputOption('family', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('queue', null, InputOption::VALUE_NONE),
        ]);

        return new ArrayInput($options, $definition);
    }
}
