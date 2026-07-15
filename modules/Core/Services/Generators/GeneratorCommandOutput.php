<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Console\Command;

class GeneratorCommandOutput
{
    /**
     * @param  array<string, list<array{path: string, reason?: string}>>  $results
     * @param  list<string>  $nextCommands
     */
    public function write(Command $command, array $results, bool $dryRun, array $nextCommands): void
    {
        $command->newLine();
        $command->info($dryRun ? 'Dry-run summary' : 'Generation summary');

        foreach (['generated', 'updated', 'skipped', 'manual'] as $section) {
            $items = $results[$section] ?? [];
            $command->line('');
            $command->line(strtoupper($section).' ('.count($items).')');

            if ($items === []) {
                $command->line('  - none');

                continue;
            }

            foreach ($items as $item) {
                $reason = isset($item['reason']) ? " ({$item['reason']})" : '';
                $command->line("  - {$item['path']}{$reason}");
            }
        }

        $manualItems = $results['manual'] ?? [];
        $command->line('');
        $command->line('Manual follow-up needed');

        if ($manualItems === []) {
            $command->line('  - none');
        } else {
            foreach ($manualItems as $item) {
                $command->line("  - Review {$item['path']}: {$item['reason']}");
            }
        }

        $command->line('');
        $command->line('Next commands');

        if ($dryRun) {
            $command->line('  - Re-run without --dry-run after reviewing the planned changes.');
        }

        foreach ($nextCommands as $nextCommand) {
            $command->line("  - {$nextCommand}");
        }
    }
}
