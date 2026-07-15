<?php

namespace Modules\Core\Services\Generators;

class GeneratorFileWriter
{
    /**
     * @var array<string, list<array{path: string, reason?: string}>>
     */
    private array $results = [
        'generated' => [],
        'updated' => [],
        'skipped' => [],
        'manual' => [],
    ];

    public function __construct(
        private readonly bool $dryRun,
        private readonly bool $force,
    ) {}

    public function write(string $path, string $contents): void
    {
        $absolutePath = base_path($path);
        $exists = is_file($absolutePath);

        if ($exists && ! $this->force) {
            $this->skip($path, 'already exists; use --force to overwrite');

            return;
        }

        if (! $this->dryRun) {
            $this->ensureDirectory(dirname($absolutePath));
            file_put_contents($absolutePath, $contents);
        }

        $this->results[$exists ? 'updated' : 'generated'][] = ['path' => $path];
    }

    public function update(string $path, string $contents): void
    {
        $absolutePath = base_path($path);
        $exists = is_file($absolutePath);
        $current = $exists ? (string) file_get_contents($absolutePath) : null;

        if ($current === $contents) {
            $this->skip($path, 'already up to date');

            return;
        }

        if (! $this->dryRun) {
            $this->ensureDirectory(dirname($absolutePath));
            file_put_contents($absolutePath, $contents);
        }

        $this->results[$exists ? 'updated' : 'generated'][] = ['path' => $path];
    }

    public function skip(string $path, string $reason): void
    {
        $this->results['skipped'][] = [
            'path' => $path,
            'reason' => $reason,
        ];
    }

    public function manual(string $path, string $contents, string $reason): void
    {
        $this->write($path, $contents);
        $this->results['manual'][] = [
            'path' => $path,
            'reason' => $reason,
        ];
    }

    /**
     * @return array<string, list<array{path: string, reason?: string}>>
     */
    public function results(): array
    {
        return $this->results;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }
    }
}
