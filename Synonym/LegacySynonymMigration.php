<?php

declare(strict_types=1);

namespace TntSearch\Synonym;

use Propel\Runtime\Propel;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Thelia\Core\Event\TheliaEvents;
use Thelia\Domain\Search\Dictionary\SearchTermNormalizer;
use Thelia\Domain\Search\Event\SearchSynonymWriteEvent;
use Thelia\Model\LangQuery;
use Thelia\Model\SearchSynonymQuery;
use TntSearch\Model\TntSynonymQuery;
use TntSearch\TntSearch;

#[Autoconfigure(public: true)]
readonly class LegacySynonymMigration
{
    public const string PENDING = 'legacy_synonyms_to_copy';

    public function __construct(
        private EventDispatcherInterface $dispatcher,
        private LoggerInterface $logger,
    ) {
    }

    public static function schedule(): void
    {
        TntSearch::setConfigValue(self::PENDING, '1');
    }

    public function copyWhilePending(): void
    {
        if ('1' !== (string) TntSearch::getConfigValue(self::PENDING, '')) {
            return;
        }

        try {
            if (!self::legacyTableExists()) {
                TntSearch::setConfigValue(self::PENDING, '');

                return;
            }

            [, $failed] = $this->copy();
        } catch (\Throwable $exception) {
            $this->logger->error('TntSearch: the synonyms of the module could not be copied, the copy is tried again on the next back-office request: {message}', ['message' => $exception->getMessage()]);

            return;
        }

        if (0 === $failed) {
            TntSearch::setConfigValue(self::PENDING, '');
        }
    }

    public function migrate(): int
    {
        return $this->copy()[0];
    }

    /**
     * @return array{int, int}
     */
    private function copy(): array
    {
        $groups = [];

        foreach (TntSynonymQuery::create()->filterByEnabled(true)->orderByGroupId()->orderByPosition()->find() as $synonym) {
            $term = SearchTermNormalizer::normalize((string) $synonym->getTerm());

            if ('' !== $term) {
                $groups[(int) $synonym->getGroupId()][] = $term;
            }
        }

        $created = 0;
        $failed = 0;

        foreach (LangQuery::create()->filterByActive(true)->find() as $lang) {
            foreach ($groups as $groupId => $terms) {
                $terms = array_values(array_unique($terms));

                if (\count($terms) < 2 || SearchSynonymQuery::create()->filterByLangId($lang->getId())->filterByTerm($terms[0])->exists()) {
                    continue;
                }

                try {
                    $this->dispatcher->dispatch(
                        (new SearchSynonymWriteEvent())
                            ->setLangId($lang->getId())
                            ->setTerm($terms[0])
                            ->setSynonyms(\array_slice($terms, 1))
                            ->setBidirectional(true)
                            ->setActive(true),
                        TheliaEvents::SEARCH_SYNONYM_CREATE,
                    );
                    ++$created;
                } catch (\InvalidArgumentException $exception) {
                    $this->logger->warning('TntSearch: the synonym group {group} is refused by the {locale} dictionary and left out: {message}', [
                        'group' => $groupId,
                        'locale' => $lang->getLocale(),
                        'message' => $exception->getMessage(),
                    ]);
                } catch (\Throwable $exception) {
                    ++$failed;
                    $this->logger->warning('TntSearch: the synonym group {group} was not copied to the {locale} dictionary: {message}', [
                        'group' => $groupId,
                        'locale' => $lang->getLocale(),
                        'message' => $exception->getMessage(),
                    ]);
                }
            }
        }

        return [$created, $failed];
    }

    private static function legacyTableExists(): bool
    {
        return false !== Propel::getConnection()->query("SHOW TABLES LIKE 'tnt_synonym'")?->fetchColumn();
    }
}
