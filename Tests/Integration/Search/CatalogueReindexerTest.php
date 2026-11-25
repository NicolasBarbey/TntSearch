<?php

declare(strict_types=1);

namespace TntSearch\Tests\Integration\Search;

use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Domain\Search\Contract\SearchableDocumentProviderInterface;
use Thelia\Domain\Search\Contract\SearchScope;
use Thelia\Domain\Search\Provider\ProductDocumentProvider;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchEngineRegistry;
use Thelia\Domain\Search\SearchIndexer;
use Thelia\Domain\Search\SearchLocales;
use Thelia\Domain\Search\UnorderedDocumentIdentifiersException;
use Thelia\Test\IntegrationTestCase;
use Thelia\Test\Trait\RecordsSqlQueries;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\CatalogueReindexer;
use TntSearch\Search\Message\RebuildCatalogue;
use TntSearch\Search\SearchMemo;
use TntSearch\Search\TntSearchEngine;
use TntSearch\TntSearch;

final class CatalogueReindexerTest extends IntegrationTestCase
{
    use RecordsSqlQueries;

    private const string ENVIRONMENT = 'phpunit-catalogue-reindexer';

    /** @var list<Envelope> */
    private array $dispatched = [];

    /** @var list<string> */
    private array $warnings = [];

    private TntSearchEngine $engine;

    private int $failingDispatches = 0;

    private SearchMemo $memo;

    protected function setUp(): void
    {
        parent::setUp();
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);
        TntSearch::setConfigValue(CatalogueReindexer::QUEUED, '');
        $fixtures = $this->createFixtureFactory();
        $fixtures->product($fixtures->category(), $fixtures->taxRule(), $fixtures->currency(), ['title' => 'Lampe']);
        $this->memo = new SearchMemo();
        $this->engine = TntSearchEngineTest::engine(self::ENVIRONMENT, [new ProductDocumentProvider()], null, $this->memo);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT);

        parent::tearDown();
    }

    public function testAMissingCatalogueIsHandedToTheHeavyQueueOnlyOnce(): void
    {
        $reindexer = $this->reindexer('doctrine://default');

        $reindexer->queueOnceWhenMissing();
        $queued = \count($this->dispatched);
        $reindexer->queueOnceWhenMissing();

        self::assertSame(1, $queued);
        self::assertCount(1, $this->dispatched);
        self::assertEquals(new RebuildCatalogue([ProductDocumentProvider::FAMILY]), $this->dispatched[0]->getMessage());
        self::assertSame(['async_heavy'], $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames());
        self::assertFalse($this->engine->isAvailable());
    }

    public function testTheQueuedRebuildIsMarkedOnlyByTheStepAfterItsLastBatch(): void
    {
        $fixtures = $this->createFixtureFactory();
        $category = $fixtures->category();
        $taxRule = $fixtures->taxRule();
        $currency = $fixtures->currency();

        foreach (range(1, 100) as $index) {
            $fixtures->product($category, $taxRule, $currency, ['title' => 'Lanterne '.$index]);
        }

        $reindexer = $this->reindexer('doctrine://default');
        $reindexer->queueOnceWhenMissing();
        $steps = 0;

        while ([] !== $this->dispatched) {
            $step = array_shift($this->dispatched)->getMessage();
            self::assertInstanceOf(RebuildCatalogue::class, $step);
            self::assertFalse($this->engine->isAvailable(), 'marked before the step '.$step->afterId);
            $this->memo->reset();
            $reindexer->continueRebuild($step);
            ++$steps;
        }

        self::assertGreaterThanOrEqual(3, $steps);
        $this->memo->reset();
        self::assertTrue($this->engine->isAvailable());
    }

    public function testAFailedBatchLetsTheRebuildGoOnAndLeavesItsFamilyUnmarked(): void
    {
        $fixtures = $this->createFixtureFactory();
        $category = $fixtures->category();
        $taxRule = $fixtures->taxRule();
        $currency = $fixtures->currency();

        foreach (range(1, 100) as $index) {
            $fixtures->product($category, $taxRule, $currency, ['title' => 'Lanterne '.$index]);
        }

        $failures = 1;
        $providers = new SearchDocumentProviders([new class(new ProductDocumentProvider(), $failures) implements SearchableDocumentProviderInterface {
            public function __construct(private SearchableDocumentProviderInterface $products, private int &$failures)
            {
            }

            public function family(): string
            {
                return $this->products->family();
            }

            public function scope(): SearchScope
            {
                return $this->products->scope();
            }

            public function isTranslatable(): bool
            {
                return $this->products->isTranslatable();
            }

            public function fieldWeights(): array
            {
                return $this->products->fieldWeights();
            }

            public function idsAfter(int $afterId, int $limit): array
            {
                return $this->products->idsAfter($afterId, $limit);
            }

            public function documents(array $ids, ?string $locale): array
            {
                if ($this->failures-- > 0) {
                    throw new \RuntimeException('The index file is locked.');
                }

                return $this->products->documents($ids, $locale);
            }

            public function visibleIds(array $ids): array
            {
                return $this->products->visibleIds($ids);
            }
        }]);
        $reindexer = $this->reindexer('doctrine://default', $providers);
        $reindexer->queueOnceWhenMissing();
        $steps = 0;

        while ([] !== $this->dispatched) {
            $step = array_shift($this->dispatched)->getMessage();
            self::assertInstanceOf(RebuildCatalogue::class, $step);
            $reindexer->continueRebuild($step);
            ++$steps;
        }

        $this->memo->reset();
        self::assertGreaterThanOrEqual(3, $steps);
        self::assertTrue(TntSearchEngineTest::indexes(self::ENVIRONMENT)->hasFailed(ProductDocumentProvider::FAMILY));
        self::assertFalse($this->engine->isAvailable());
    }

    public function testAProviderWhoseIdentifiersDoNotMoveOnStopsTheRebuildWithoutMarkingIt(): void
    {
        $providers = new SearchDocumentProviders([new class(new ProductDocumentProvider()) implements SearchableDocumentProviderInterface {
            public function __construct(private SearchableDocumentProviderInterface $products)
            {
            }

            public function family(): string
            {
                return $this->products->family();
            }

            public function scope(): SearchScope
            {
                return $this->products->scope();
            }

            public function isTranslatable(): bool
            {
                return $this->products->isTranslatable();
            }

            public function fieldWeights(): array
            {
                return $this->products->fieldWeights();
            }

            public function idsAfter(int $afterId, int $limit): array
            {
                return [1, 2];
            }

            public function documents(array $ids, ?string $locale): array
            {
                return $this->products->documents($ids, $locale);
            }

            public function visibleIds(array $ids): array
            {
                return $this->products->visibleIds($ids);
            }
        }]);

        try {
            $this->reindexer('doctrine://default', $providers)->rebuild();
            self::fail('The rebuild went on with identifiers that do not move on.');
        } catch (UnorderedDocumentIdentifiersException) {
        }

        try {
            $this->reindexer('doctrine://default', $providers)->continueRebuild(new RebuildCatalogue([ProductDocumentProvider::FAMILY], 2));
            self::fail('The queued rebuild went on with identifiers that do not move on.');
        } catch (UnorderedDocumentIdentifiersException) {
        }

        self::assertSame([], $this->dispatched);
        self::assertFalse($this->engine->isAvailable());
    }

    public function testACatalogueStillMissingLongAfterItWasQueuedIsQueuedAgain(): void
    {
        TntSearch::setConfigValue(CatalogueReindexer::QUEUED, (string) (time() - CatalogueReindexer::REQUEUE_AFTER_SECONDS - 60));

        $this->reindexer('doctrine://default')->queueOnceWhenMissing();

        self::assertNotSame([], $this->dispatched);
    }

    public function testAQueueThatFailsMidwayIsTriedAgainOnTheNextRequest(): void
    {
        $this->failingDispatches = 1;
        $failing = $this->reindexer('doctrine://default');
        $this->failingDispatches = 0;

        try {
            $failing->queueOnceWhenMissing();
        } catch (\RuntimeException) {
        }

        $this->reindexer('doctrine://default')->queueOnceWhenMissing();

        self::assertNotEmpty($this->dispatched);
    }

    public function testWithoutAQueueNothingIsIndexedInTheRequestAndTheMerchantIsWarned(): void
    {
        $this->reindexer('sync://')->queueOnceWhenMissing();

        self::assertSame([], $this->dispatched);
        self::assertCount(1, $this->warnings);
        self::assertFalse($this->engine->isAvailable());
    }

    public function testAnIndexedCatalogueIsNeitherQueuedNorRebuilt(): void
    {
        $this->reindexer('doctrine://default')->rebuild();
        $this->dispatched = [];

        $this->reindexer('doctrine://default')->queueOnceWhenMissing();

        self::assertTrue($this->engine->isAvailable());
        self::assertSame([], $this->dispatched);
    }

    public function testAMarkedCatalogueIsNotReadAgainAtEachBackOfficeRequest(): void
    {
        $this->reindexer('doctrine://default')->rebuild();
        $this->memo->reset();
        $reindexer = $this->reindexer('doctrine://default');

        $statements = $this->recordSqlQueries($reindexer->queueOnceWhenMissing(...));

        self::assertSame([], array_values(array_filter($statements, static fn (string $sql): bool => 1 === preg_match('/FROM\s+`?product`?\s/i', $sql))));
    }

    public function testAnActivationDropsTheIndexOfAnEarlierActivationAndQueuesTheCatalogueAgain(): void
    {
        $this->reindexer('doctrine://default')->rebuild();
        $this->dispatched = [];
        $environment = $_SERVER['APP_ENV'] ?? null;
        $_SERVER['APP_ENV'] = self::ENVIRONMENT;

        try {
            (new TntSearch())->postActivation();
        } finally {
            $_SERVER['APP_ENV'] = $environment;
        }

        $this->memo->reset();
        $this->reindexer('doctrine://default')->queueOnceWhenMissing();

        $left = glob(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT.\DIRECTORY_SEPARATOR.'document--*') ?: [];
        self::assertSame([], array_values(array_filter($left, static fn (string $file): bool => !str_ends_with($file, '.lock'))));
        self::assertFalse($this->engine->isAvailable());
        self::assertNotSame([], $this->dispatched);
    }

    public function testALanguageWithoutItsIndexIsQueuedWhileTheOtherLanguagesKeepTheEngine(): void
    {
        $this->reindexer('doctrine://default')->rebuild();
        $this->dispatched = [];
        $locales = (new SearchLocales())->indexed();
        self::assertGreaterThan(1, \count($locales));
        unlink(TntSearch::INDEXES_DIR.\DIRECTORY_SEPARATOR.self::ENVIRONMENT.\DIRECTORY_SEPARATOR.'document--product--'.$locales[1].'.complete');
        $this->memo->reset();

        $this->reindexer('doctrine://default')->queueOnceWhenMissing();

        self::assertTrue($this->engine->isAvailable());
        self::assertNotSame([], $this->dispatched);
    }

    public function testARebuildLiftsTheFailureLeftByAnEarlierOne(): void
    {
        TntSearchEngineTest::indexes(self::ENVIRONMENT)->recordFailure('product');

        $this->reindexer('doctrine://default')->rebuild();

        self::assertTrue($this->engine->isAvailable());
    }

    private function reindexer(string $dsn, ?SearchDocumentProviders $providers = null): CatalogueReindexer
    {
        $providers ??= new SearchDocumentProviders([new ProductDocumentProvider()]);
        $dispatched = &$this->dispatched;
        $warnings = &$this->warnings;

        return new CatalogueReindexer(
            $providers,
            new SearchIndexer(new SearchEngineRegistry([$this->engine]), $providers, new SearchLocales(), new NullLogger()),
            $this->engine,
            new CatalogueCompletion($providers, new SearchLocales(), TntSearchEngineTest::indexes(self::ENVIRONMENT), $this->memo),
            new class($dispatched, $this->failingDispatches) implements MessageBusInterface {
                /** @param list<Envelope> $dispatched */
                public function __construct(private array &$dispatched, private int $failingDispatches)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    if ($this->failingDispatches-- > 0) {
                        throw new \RuntimeException('The queue went away.');
                    }

                    $envelope = Envelope::wrap($message, $stamps);
                    $this->dispatched[] = $envelope;

                    return $envelope;
                }
            },
            new LockFactory(new InMemoryStore()),
            new class($warnings) extends AbstractLogger {
                /** @param list<string> $warnings */
                public function __construct(private array &$warnings)
                {
                }

                public function log($level, \Stringable|string $message, array $context = []): void
                {
                    $this->warnings[] = (string) $message;
                }
            },
            $dsn,
        );
    }
}
