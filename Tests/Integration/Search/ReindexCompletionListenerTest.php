<?php

declare(strict_types=1);

namespace TntSearch\Tests\Integration\Search;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Event\ConsoleTerminateEvent;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputDefinition;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;
use Thelia\Domain\Search\Provider\CategoryDocumentProvider;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchLocales;
use Thelia\Test\IntegrationTestCase;
use TntSearch\EventListener\ReindexCompletionListener;
use TntSearch\Search\CatalogueCompletion;
use TntSearch\Search\DocumentIndexes;
use TntSearch\Search\Message\MarkCatalogueIndexed;
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

    public function testAQueuedReindexIsMarkedCompleteByAMessageQueuedAfterItsBatches(): void
    {
        $this->listener()->onReindexEnd($this->terminate(['--queue' => true], Command::SUCCESS));

        self::assertFalse($this->indexes->isComplete('category', 'fr_FR'));
        self::assertCount(1, $this->dispatched);
        self::assertInstanceOf(MarkCatalogueIndexed::class, $this->dispatched[0]->getMessage());
        self::assertSame(['async_heavy'], $this->dispatched[0]->last(TransportNamesStamp::class)?->getTransportNames());
    }

    private function listener(): ReindexCompletionListener
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
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function terminate(array $options, int $exitCode): ConsoleTerminateEvent
    {
        $definition = new InputDefinition([
            new InputOption('family', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY),
            new InputOption('queue', null, InputOption::VALUE_NONE),
        ]);

        return new ConsoleTerminateEvent(new Command('thelia:search:reindex'), new ArrayInput($options, $definition), new NullOutput(), $exitCode);
    }
}
