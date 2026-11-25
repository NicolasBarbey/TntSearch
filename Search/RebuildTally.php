<?php

declare(strict_types=1);

namespace TntSearch\Search;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * @phpstan-type Tally array{run: string, families: list<string>, locales: list<string>|null, sent: int, done: int, closed: bool, failed: list<string>}
 */
#[Autoconfigure(public: true)]
readonly class RebuildTally
{
    private const string FILE = 'rebuild.tally';

    public function __construct(private DocumentIndexes $indexes)
    {
    }

    /**
     * @param list<string>      $families
     * @param list<string>|null $locales
     */
    public function send(string $run, array $families, ?array $locales): string
    {
        $tally = $this->write(static fn (?array $tally): array => null === $tally
            ? ['run' => $run, 'families' => $families, 'locales' => $locales, 'sent' => 1, 'done' => 0, 'closed' => false, 'failed' => []]
            : ['sent' => $tally['sent'] + 1, 'closed' => false] + self::widened($tally, $families, $locales));

        return $tally['run'];
    }

    /**
     * @param list<string>      $families
     * @param list<string>|null $locales
     */
    public function close(array $families, ?array $locales): ?string
    {
        $tally = $this->write(static fn (?array $tally): ?array => null === $tally ? null : ['closed' => true] + self::widened($tally, $families, $locales));

        return $tally['run'] ?? null;
    }

    public function count(string $run, ?string $failedFamily = null): void
    {
        $this->write(static function (?array $tally) use ($run, $failedFamily): ?array {
            if ($run !== ($tally['run'] ?? null)) {
                return $tally;
            }

            $failed = null === $failedFamily ? $tally['failed'] : array_values(array_unique([...$tally['failed'], $failedFamily]));

            return ['done' => $tally['done'] + 1, 'failed' => $failed] + $tally;
        });
    }

    /**
     * @return Tally|null
     */
    public function of(string $run): ?array
    {
        $tally = $this->write(static fn (?array $tally): ?array => $tally);

        return $run === ($tally['run'] ?? null) ? $tally : null;
    }

    public function finish(string $run): void
    {
        $this->write(static fn (?array $tally): ?array => $run === ($tally['run'] ?? null) ? null : $tally);
    }

    /**
     * @param Tally             $tally
     * @param list<string>      $families
     * @param list<string>|null $locales
     *
     * @return Tally
     */
    private static function widened(array $tally, array $families, ?array $locales): array
    {
        $tally['families'] = [] === $tally['families'] || [] === $families ? [] : array_values(array_unique([...$tally['families'], ...$families]));
        $tally['locales'] = null === $tally['locales'] || null === $locales ? null : array_values(array_unique([...$tally['locales'], ...$locales]));

        return $tally;
    }

    /**
     * @param callable(Tally|null): (Tally|null) $change
     *
     * @return Tally|null
     */
    private function write(callable $change): ?array
    {
        $path = $this->indexes->storage().self::FILE;
        $handle = fopen($path, 'c+');

        if (false === $handle) {
            throw new \RuntimeException(\sprintf('The rebuild tally %s cannot be opened.', $path));
        }

        try {
            flock($handle, \LOCK_EX);
            $read = json_decode((string) stream_get_contents($handle), true);
            $before = \is_array($read) && \is_string($read['run'] ?? null) && \is_int($read['sent'] ?? null) ? $read : null;
            $after = $change($before);

            if ($after !== $before) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, null === $after ? '' : (string) json_encode($after));
                fflush($handle);
            }

            return $after;
        } finally {
            flock($handle, \LOCK_UN);
            fclose($handle);
        }
    }
}
