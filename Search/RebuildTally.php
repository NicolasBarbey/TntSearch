<?php

declare(strict_types=1);

namespace TntSearch\Search;

use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

#[Autoconfigure(public: true)]
readonly class RebuildTally
{
    private const string FILE = 'rebuild.tally';

    public function __construct(private DocumentIndexes $indexes)
    {
    }

    public function start(string $run): void
    {
        $this->write(static fn (): array => ['run' => $run, 'expected' => null, 'done' => 0]);
    }

    public function expect(string $run, int $batches): void
    {
        $this->write(static fn (?array $tally): ?array => $run === ($tally['run'] ?? null) ? ['expected' => $batches] + $tally : $tally);
    }

    public function count(string $run): void
    {
        $this->write(static fn (?array $tally): ?array => $run === ($tally['run'] ?? null) ? ['done' => (int) $tally['done'] + 1] + $tally : $tally);
    }

    public function isComplete(string $run): ?bool
    {
        $tally = $this->write(static fn (?array $tally): ?array => $tally);

        if ($run !== ($tally['run'] ?? null)) {
            return null;
        }

        return null !== $tally['expected'] && (int) $tally['done'] >= (int) $tally['expected'];
    }

    public function finish(string $run): void
    {
        $this->write(static fn (?array $tally): ?array => $run === ($tally['run'] ?? null) ? null : $tally);
    }

    /**
     * @param callable(?array{run: string, expected: ?int, done: int}): ?array{run: string, expected: ?int, done: int} $change
     *
     * @return ?array{run: string, expected: ?int, done: int}
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
            $before = \is_array($read) && \is_string($read['run'] ?? null) ? $read : null;
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
