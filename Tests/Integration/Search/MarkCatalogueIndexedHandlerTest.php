<?php

declare(strict_types=1);

namespace TntSearch\Tests\Integration\Search;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Thelia\Command\SearchReindexCommand;
use Thelia\Domain\Search\Message\IndexDocument;
use Thelia\Domain\Search\Provider\BrandDocumentProvider;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchLocales;
use Thelia\Test\IntegrationTestCase;
use TntSearch\EventListener\FailedCatalogueBatchListener;
use TntSearch\EventListener\RebuildTallyListener;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\DocumentIndexes;
use TntSearch\Search\Message\MarkCatalogueIndexed;
use TntSearch\Search\Message\MarkCatalogueIndexedHandler;
use TntSearch\Search\Message\RebuildCatalogue;
use TntSearch\Search\Message\RebuildRunStamp;
use TntSearch\Search\RebuildTally;
use TntSearch\Search\SearchMemo;
use TntSearch\TntSearch;

final class MarkCatalogueIndexedHandlerTest extends IntegrationTestCase
{
    private const string ENVIRONMENT = 'phpunit-mark-catalogue-indexed';

    private DocumentIndexes $indexes;

    /** @var list<Envelope> */
    private array $dispatched = [];

    /** @var list<string> */
    private array $warnings = [];

    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);
        $this->indexes = TntSearchEngineTest::indexes(self::ENVIRONMENT);
        $this->dispatched = [];
        $this->warnings = [];
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);

        parent::tearDown();
    }

    public function testARunWhoseBatchesWereAllSentAndHandledMarksTheFamiliesAndLocalesItCovers(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);
        $this->tally()->send($run, ['brand'], ['en_US']);
        $this->tally()->close(['brand'], ['en_US']);
        $this->tally()->count($run);
        $this->tally()->count($run);

        $this->handler()(new MarkCatalogueIndexed($run));

        self::assertTrue($this->indexes->isComplete('brand', 'en_US'));
        self::assertFalse($this->indexes->isComplete('brand', 'fr_FR'));
        self::assertNull($this->tally()->of($run));
        self::assertSame([], $this->dispatched);
    }

    public function testBatchesStillWaitingPostponeTheMarker(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);
        $this->tally()->send($run, ['brand'], ['en_US']);
        $this->tally()->close(['brand'], ['en_US']);
        $this->tally()->count($run);

        $this->handler()(new MarkCatalogueIndexed($run));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertCount(1, $this->dispatched);
        $postponed = $this->dispatched[0]->getMessage();
        self::assertInstanceOf(MarkCatalogueIndexed::class, $postponed);
        self::assertSame($run, $postponed->run);
        self::assertSame(1, $postponed->postponements);
        self::assertSame(MarkCatalogueIndexedHandler::DELAY_MILLISECONDS, $this->dispatched[0]->last(DelayStamp::class)?->getDelay());
    }

    public function testARunThatAnotherCommandReopenedWaitsForItsEndEvenWhenEveryBatchSentIsHandled(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);
        $this->tally()->close(['brand'], ['en_US']);
        $this->tally()->send('run-b', ['brand'], ['en_US']);
        $this->tally()->count($run);
        $this->tally()->count($run);

        $this->handler()(new MarkCatalogueIndexed($run));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertCount(1, $this->dispatched);
    }

    public function testTheMarkerOfARunNoLongerCountedMarksNothing(): void
    {
        $this->handler()(new MarkCatalogueIndexed('run-a'));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertSame([], $this->dispatched);
    }

    public function testAFamilyWithABatchFailedForGoodStaysUnmarkedEvenWhenItsTraceWasLiftedSince(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);
        $this->tally()->close(['brand'], ['en_US']);
        $this->tally()->count($run, 'brand');
        $this->completion()->startRebuild();

        $this->handler()(new MarkCatalogueIndexed($run));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertTrue($this->indexes->hasFailed('brand'));
        self::assertCount(1, $this->warnings);
    }

    public function testTheMarkerGivesUpWithoutMarkingAfterTooManyChecks(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);
        $this->tally()->close(['brand'], ['en_US']);

        $this->handler()(new MarkCatalogueIndexed($run, MarkCatalogueIndexedHandler::MAX_POSTPONEMENTS));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertSame([], $this->dispatched);
        self::assertNull($this->tally()->of($run));
        self::assertCount(1, $this->warnings);
    }

    public function testOnlyTheBatchesOfTheRunHandledOrFailedForGoodOnTheHeavyQueueAreCounted(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], null);
        $listener = new RebuildTallyListener($this->tally(), new NullLogger());
        $batch = new Envelope(new IndexDocument('brand', [1]), [new RebuildRunStamp($run)]);

        $listener->countHandledBatch(new WorkerMessageHandledEvent(new Envelope(new IndexDocument('brand', [2])), SearchReindexCommand::QUEUE_TRANSPORT));
        $listener->countHandledBatch(new WorkerMessageHandledEvent(new Envelope(new IndexDocument('brand', [3]), [new RebuildRunStamp('run-z')]), SearchReindexCommand::QUEUE_TRANSPORT));
        $listener->countHandledBatch(new WorkerMessageHandledEvent($batch, 'failed'));
        $retried = new WorkerMessageFailedEvent($batch, SearchReindexCommand::QUEUE_TRANSPORT, new \RuntimeException());
        $retried->setForRetry();
        $listener->countFailedBatch($retried);

        self::assertSame(0, $this->tally()->of($run)['done'] ?? null);

        $listener->countFailedBatch(new WorkerMessageFailedEvent($batch, SearchReindexCommand::QUEUE_TRANSPORT, new \RuntimeException()));
        $listener->countHandledBatch(new WorkerMessageHandledEvent($batch, SearchReindexCommand::QUEUE_TRANSPORT));

        self::assertSame(2, $this->tally()->of($run)['done'] ?? null);
        self::assertSame(['brand'], $this->tally()->of($run)['failed'] ?? null);
    }

    public function testARunThatFollowsAnUnfinishedOneCountsItsBatchesInTheSameRunAndWidensItsScope(): void
    {
        $run = $this->tally()->send('run-a', ['brand'], ['en_US']);

        self::assertSame($run, $this->tally()->send('run-b', ['product'], ['fr_FR']));
        self::assertSame($run, $this->tally()->close([], null));

        $tally = $this->tally()->of($run);
        self::assertNotNull($tally);
        self::assertSame(2, $tally['sent']);
        self::assertSame([], $tally['families']);
        self::assertNull($tally['locales']);
    }

    public function testAStepOfTheModuleRebuildThatFailedForGoodForgetsTheCompletionOfItsFamily(): void
    {
        $this->indexes->markComplete('brand', 'en_US');
        $listener = new FailedCatalogueBatchListener($this->indexes, new SearchMemo());

        $listener->forgetTheCompletionOfItsFamily(new WorkerMessageFailedEvent(new Envelope(new RebuildCatalogue(['brand', 'product'], 4)), SearchReindexCommand::QUEUE_TRANSPORT, new \RuntimeException()));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
        self::assertTrue($this->indexes->hasFailed('brand'));
    }

    public function testABatchThatFailedForGoodForgetsTheCompletionOfItsFamily(): void
    {
        $this->indexes->markComplete('brand', 'en_US');
        $listener = new FailedCatalogueBatchListener($this->indexes, new SearchMemo());

        $retried = new WorkerMessageFailedEvent(new Envelope(new IndexDocument('brand', [1])), SearchReindexCommand::QUEUE_TRANSPORT, new \RuntimeException());
        $retried->setForRetry();
        $listener->forgetTheCompletionOfItsFamily($retried);

        self::assertTrue($this->indexes->isComplete('brand', 'en_US'));

        $listener->forgetTheCompletionOfItsFamily(new WorkerMessageFailedEvent(new Envelope(new IndexDocument('brand', [1])), SearchReindexCommand::QUEUE_TRANSPORT, new \RuntimeException()));

        self::assertFalse($this->indexes->isComplete('brand', 'en_US'));
    }

    public function testAnEverydayWriteThatFailedForGoodLeavesItsFamilyComplete(): void
    {
        $this->indexes->markComplete('brand', 'en_US');
        $listener = new FailedCatalogueBatchListener($this->indexes, new SearchMemo());

        $listener->forgetTheCompletionOfItsFamily(new WorkerMessageFailedEvent(new Envelope(new IndexDocument('brand', [1])), 'async', new \RuntimeException()));

        self::assertTrue($this->indexes->isComplete('brand', 'en_US'));
        self::assertFalse($this->indexes->hasFailed('brand'));
    }

    private function tally(): RebuildTally
    {
        return new RebuildTally($this->indexes);
    }

    private function completion(): CatalogueCompletion
    {
        return new CatalogueCompletion(new SearchDocumentProviders([new BrandDocumentProvider()]), new SearchLocales(), $this->indexes, new SearchMemo());
    }

    private function handler(): MarkCatalogueIndexedHandler
    {
        $dispatched = &$this->dispatched;
        $warnings = &$this->warnings;

        return new MarkCatalogueIndexedHandler(
            $this->completion(),
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
            new class($warnings) extends AbstractLogger {
                /** @param list<string> $warnings */
                public function __construct(private array &$warnings)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    if (LogLevel::WARNING === $level) {
                        $this->warnings[] = (string) $message;
                    }
                }
            },
            $this->tally(),
        );
    }
}
