<?php

declare(strict_types=1);

namespace TntSearch\Tests\Integration\Synonym;

use Psr\Log\NullLogger;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Model\LangQuery;
use Thelia\Model\SearchSynonym;
use Thelia\Model\SearchSynonymQuery;
use Thelia\Test\IntegrationTestCase;
use TntSearch\Model\TntSynonym;
use TntSearch\Model\TntSynonymQuery;
use TntSearch\Synonym\LegacySynonymMigration;
use TntSearch\TntSearch;

final class LegacySynonymMigrationTest extends IntegrationTestCase
{
    private LegacySynonymMigration $migration;

    protected function setUp(): void
    {
        parent::setUp();
        TntSynonymQuery::create()->deleteAll();
        SearchSynonymQuery::create()->deleteAll();
        $this->migration = static::getContainer()->get(LegacySynonymMigration::class);
    }

    public function testAGroupBecomesATwoWaySynonymInEveryActiveLanguage(): void
    {
        $this->group(1, ['Téléphone', 'tel', 'mobile']);

        $this->migration->migrate();

        foreach (LangQuery::create()->filterByActive(1)->find() as $lang) {
            $synonym = SearchSynonymQuery::create()->filterByLangId($lang->getId())->findOneByTerm('téléphone');
            self::assertNotNull($synonym, $lang->getLocale());
            self::assertSame("tel\nmobile", $synonym->getSynonyms());
            self::assertSame(1, $synonym->getBidirectional());
        }
    }

    public function testADisabledTermIsLeftOutAndAGroupLeftAloneIsNotCopied(): void
    {
        $this->group(1, ['canapé', 'sofa'], disabled: ['sofa']);
        $this->group(2, ['chaise', 'siège', 'tabouret'], disabled: ['tabouret']);

        $this->migration->migrate();

        self::assertFalse(SearchSynonymQuery::create()->filterByTerm('canapé')->exists());
        self::assertSame(['siège'], array_values(array_unique(array_map(
            static fn (SearchSynonym $synonym): string => $synonym->getSynonyms(),
            iterator_to_array(SearchSynonymQuery::create()->filterByTerm('chaise')->find()),
        ))));
    }

    public function testATermTheMerchantAlreadyDescribedInTheCoreIsKept(): void
    {
        $lang = LangQuery::create()->filterByActive(1)->findOne();
        (new SearchSynonym())->setLangId($lang->getId())->setTerm('téléphone')->setSynonyms('smartphone')->setBidirectional(0)->setActive(1)->save();
        $this->group(1, ['téléphone', 'tel']);

        $this->migration->migrate();

        self::assertSame('smartphone', SearchSynonymQuery::create()->filterByLangId($lang->getId())->findOneByTerm('téléphone')?->getSynonyms());
    }

    public function testACopyThatFailedIsTriedAgainUntilItSucceeds(): void
    {
        $this->group(1, ['téléphone', 'tel']);
        LegacySynonymMigration::schedule();
        $refusing = new class implements EventDispatcherInterface {
            public function dispatch(object $event, ?string $eventName = null): object
            {
                throw new \RuntimeException('The dictionary refused the synonym.');
            }
        };

        (new LegacySynonymMigration($refusing, new NullLogger()))->copyWhilePending();

        self::assertFalse(SearchSynonymQuery::create()->filterByTerm('téléphone')->exists());
        self::assertSame('1', (string) TntSearch::getConfigValue(LegacySynonymMigration::PENDING, ''));

        $this->migration->copyWhilePending();

        self::assertTrue(SearchSynonymQuery::create()->filterByTerm('téléphone')->exists());
        self::assertSame('', (string) TntSearch::getConfigValue(LegacySynonymMigration::PENDING, ''));
    }

    public function testAGroupTheDictionaryRefusesIsLeftOutInsteadOfRetriedForever(): void
    {
        $this->group(1, ['café', 'cafe']);
        $this->group(2, ['téléphone', 'tel']);
        LegacySynonymMigration::schedule();

        $this->migration->copyWhilePending();

        self::assertFalse(SearchSynonymQuery::create()->filterByTerm('café')->exists());
        self::assertTrue(SearchSynonymQuery::create()->filterByTerm('téléphone')->exists());
        self::assertSame('', (string) TntSearch::getConfigValue(LegacySynonymMigration::PENDING, ''));
    }

    public function testNothingIsCopiedOnceTheCopyIsDone(): void
    {
        $this->group(1, ['téléphone', 'tel']);
        TntSearch::setConfigValue(LegacySynonymMigration::PENDING, '');

        $this->migration->copyWhilePending();

        self::assertFalse(SearchSynonymQuery::create()->filterByTerm('téléphone')->exists());
    }

    public function testMigratingTwiceCopiesNothingMore(): void
    {
        $this->group(1, ['téléphone', 'tel']);

        $first = $this->migration->migrate();
        $second = $this->migration->migrate();

        self::assertSame(LangQuery::create()->filterByActive(1)->count(), $first);
        self::assertSame(0, $second);
    }

    /**
     * @param list<string> $terms
     * @param list<string> $disabled
     */
    private function group(int $groupId, array $terms, array $disabled = []): void
    {
        foreach ($terms as $position => $term) {
            (new TntSynonym())->setGroupId($groupId)->setTerm($term)->setPosition($position + 1)->setEnabled(!\in_array($term, $disabled, true))->save();
        }
    }
}
