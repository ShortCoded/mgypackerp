<?php

namespace Modules\Accounting\Services;

use Illuminate\Support\Collection;

final class ReconciliationComparisonService
{
    public const Matched = 'MATCHED';

    public const Difference = 'DIFFERENCE';

    public const MissingMapping = 'MISSING MAPPING';

    public const InsufficientData = 'INSUFFICIENT DATA';

    public const NotApplicable = 'NOT APPLICABLE';

    /**
     * @param  iterable<array<string, mixed>>  $rows
     * @param  list<string>  $notes
     * @return array<string, mixed>
     */
    public function compare(
        string $key,
        string $title,
        iterable $rows,
        bool $mappingConfigured = true,
        bool $sourceAvailable = true,
        bool $applicable = true,
        array $notes = [],
    ): array {
        $normalizedRows = collect($rows)->map(fn (array $row): array => $this->row($row))->values();
        $mismatches = $normalizedRows->filter(fn (array $row): bool => $row['status'] === self::Difference);
        $status = match (true) {
            ! $applicable => self::NotApplicable,
            ! $mappingConfigured => self::MissingMapping,
            ! $sourceAvailable || $normalizedRows->isEmpty() => self::InsufficientData,
            $mismatches->isNotEmpty() => self::Difference,
            default => self::Matched,
        };

        return [
            'key' => $key,
            'title' => $title,
            'status' => $status,
            'rows' => $normalizedRows,
            'notes' => $notes,
            'summary' => [
                'row_count' => $normalizedRows->count(),
                'mismatch_count' => $mismatches->count(),
                'absolute_difference_total' => $this->sumAbsolute($mismatches, 'ending_difference'),
                'positive_difference_total' => $this->sumWhereSign($mismatches, positive: true),
                'negative_difference_total' => $this->sumWhereSign($mismatches, positive: false),
            ],
        ];
    }

    /** @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function row(array $row): array
    {
        $sourceOpening = $this->amount($row['source_opening'] ?? 0);
        $glOpening = $this->amount($row['gl_opening'] ?? 0);
        $sourceMovement = $this->amount($row['source_movement'] ?? 0);
        $glMovement = $this->amount($row['gl_movement'] ?? 0);
        $sourceEnding = $this->amount($row['source_ending'] ?? bcadd($sourceOpening, $sourceMovement, 4));
        $glEnding = $this->amount($row['gl_ending'] ?? bcadd($glOpening, $glMovement, 4));
        $openingDifference = bcsub($sourceOpening, $glOpening, 4);
        $movementDifference = bcsub($sourceMovement, $glMovement, 4);
        $endingDifference = bcsub($sourceEnding, $glEnding, 4);
        $expectedEndingDifference = bcadd($openingDifference, $movementDifference, 4);
        $invariantDifference = bcsub($endingDifference, $expectedEndingDifference, 4);
        $isMismatch = collect([$openingDifference, $movementDifference, $endingDifference, $invariantDifference])
            ->contains(fn (string $value): bool => bccomp($value, '0.0000', 4) !== 0);

        return [
            ...$row,
            'source_opening' => $sourceOpening,
            'gl_opening' => $glOpening,
            'opening_difference' => $openingDifference,
            'source_movement' => $sourceMovement,
            'gl_movement' => $glMovement,
            'movement_difference' => $movementDifference,
            'source_ending' => $sourceEnding,
            'gl_ending' => $glEnding,
            'ending_difference' => $endingDifference,
            'invariant_difference' => $invariantDifference,
            'status' => $isMismatch ? self::Difference : self::Matched,
        ];
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function sumAbsolute(Collection $rows, string $key): string
    {
        return $rows->reduce(
            fn (string $sum, array $row): string => bcadd($sum, $this->absolute((string) $row[$key]), 4),
            '0.0000',
        );
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function sumWhereSign(Collection $rows, bool $positive): string
    {
        return $rows->reduce(function (string $sum, array $row) use ($positive): string {
            $difference = (string) $row['ending_difference'];
            $matches = $positive
                ? bccomp($difference, '0.0000', 4) > 0
                : bccomp($difference, '0.0000', 4) < 0;

            return $matches ? bcadd($sum, $difference, 4) : $sum;
        }, '0.0000');
    }

    private function absolute(string $value): string
    {
        return bccomp($value, '0.0000', 4) < 0 ? bcmul($value, '-1', 4) : $value;
    }

    private function amount(mixed $value): string
    {
        return bcadd(is_numeric($value) ? (string) $value : '0', '0', 4);
    }
}
