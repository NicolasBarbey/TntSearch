<?php

declare(strict_types=1);

namespace TntSearch\Search;

use TeamTNT\TNTSearch\Exceptions\IndexNotFoundException;
use Thelia\Domain\Search\Contract\SearchEngineInterface;
use Thelia\Domain\Search\Document\SearchDocument;
use Thelia\Domain\Search\Document\SearchHit;
use Thelia\Domain\Search\Document\SearchQuery;
use Thelia\Domain\Search\Document\SearchResult;
use Thelia\Domain\Search\SearchDocumentProviders;
use Thelia\Domain\Search\SearchLocales;
use TntSearch\Engine\DocumentEngine;

final readonly class TntSearchEngine implements SearchEngineInterface
{
    private const int SQLITE_BUSY = 5;

    private const int SQLITE_LOCKED = 6;

    public function __construct(
        private DocumentIndexes $indexes,
        private SearchDocumentProviders $providers,
        private TermScorer $scorer,
        private SearchMemo $memo,
        private SearchLocales $locales,
        private EngineOutage $outage,
    ) {
    }

    public function isAvailable(): bool
    {
        if (!\extension_loaded('pdo_sqlite') || $this->outage->isOngoing()) {
            return false;
        }

        return $this->memo->availability($this->everyFamilyIsComplete(...));
    }

    private function everyFamilyIsComplete(): bool
    {
        foreach ($this->providers->families() as $family) {
            $provider = $this->providers->get($family);

            if (null === $provider || [] === $provider->idsAfter(0, 1)) {
                continue;
            }

            foreach ($provider->isTranslatable() ? $this->locales->indexed() : [null] as $locale) {
                if (!$this->indexes->isComplete($family, $locale)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function index(array $documents): void
    {
        $this->memo->reset();
        $this->indexes->reset();
        $groups = [];

        foreach ($documents as $document) {
            $groups[$document->family.'|'.($document->locale ?? '')][] = $document;
        }

        foreach ($groups as $group) {
            $this->write($group);
        }
    }

    public function remove(string $family, array $ids, ?string $locale): void
    {
        $this->memo->reset();
        if ([] === $ids) {
            return;
        }

        $this->indexes->exclusively($family, function () use ($family, $locale, $ids): void {
            $engine = $this->indexes->writer($family, $locale, create: false);

            if (null === $engine) {
                return;
            }

            $engine->beginWrites();

            try {
                foreach ($ids as $id) {
                    $engine->erase($id);
                }

                $engine->commitWrites();
            } catch (\Throwable $exception) {
                $engine->rollBackWrites();

                throw $exception;
            }
        });
    }

    public function purge(string $family): void
    {
        $this->memo->reset();
        $this->indexes->reset();
        $this->indexes->forgetCompletion($family);

        foreach ($this->indexes->files($family) as $file) {
            $this->indexes->exclusively($family, fn () => $this->indexes->remove($file));
        }
    }

    public function search(SearchQuery $query): SearchResult
    {
        $ranked = $this->memo->hitsOf($query, fn (): array => $this->ranked($query));
        $hits = [];

        foreach (\array_slice($ranked, $query->offset, $query->limit, true) as $key => $score) {
            [$family, $id] = explode(':', (string) $key, 2);
            $hits[] = new SearchHit($family, (int) $id, $score);
        }

        return new SearchResult($hits, \count($ranked));
    }

    /**
     * @return array<string, float>
     */
    private function ranked(SearchQuery $query): array
    {
        $terms = array_values(array_unique(array_filter([trim($query->term), ...array_map(trim(...), $query->alternativeTerms)], static fn (string $term): bool => '' !== $term)));
        $families = [] === $query->families ? $this->indexes->families() : $query->families;
        sort($families);
        $ranked = [];

        foreach ($families as $family) {
            $scores = [];

            foreach (array_unique([$query->locale, null]) as $locale) {
                foreach ($this->scoresIn($family, $locale, $terms, $query) as $id => $score) {
                    $scores[$id] = max($scores[$id] ?? 0.0, $score);
                }
            }

            ksort($scores);

            foreach ($scores as $id => $score) {
                $ranked[$family.':'.$id] = $score;
            }
        }

        arsort($ranked);

        return $ranked;
    }

    /**
     * @param list<SearchDocument> $documents
     */
    private function write(array $documents): void
    {
        $family = $documents[0]->family;
        $locale = $documents[0]->locale;

        $this->indexes->exclusively($family, function () use ($family, $locale, $documents): void {
            $engine = $this->indexes->writer($family, $locale, create: true);

            if (null === $engine) {
                return;
            }

            $engine->beginWrites();

            try {
                foreach ($documents as $document) {
                    $engine->write($document);
                }

                $engine->commitWrites();
            } catch (\Throwable $exception) {
                $engine->rollBackWrites();

                throw $exception;
            }
        });
    }

    /**
     * @param list<string> $terms
     *
     * @return array<int, float>
     */
    private function scoresIn(string $family, ?string $locale, array $terms, SearchQuery $query): array
    {
        try {
            $search = $this->indexes->reader($family, $locale);

            if (null === $search || !$search->engine instanceof DocumentEngine) {
                return [];
            }

            $scores = [];

            foreach ($terms as $term) {
                foreach ($this->scorer->scores($search, $search->engine, $term, $query->scope, $query->visibleOnly) as $id => $score) {
                    $scores[$id] = max($scores[$id] ?? 0.0, $score);
                }
            }
        } catch (IndexNotFoundException) {
            return [];
        } catch (\PDOException $exception) {
            if (str_contains($exception->getMessage(), 'no such table')) {
                return [];
            }

            if (!self::isBusy($exception)) {
                $this->outage->begin();
            }

            throw $exception;
        }

        return $scores;
    }

    private static function isBusy(\PDOException $exception): bool
    {
        return \in_array($exception->errorInfo[1] ?? null, [self::SQLITE_BUSY, self::SQLITE_LOCKED], true)
            || str_contains($exception->getMessage(), 'database is locked');
    }
}
