<?php

use Illuminate\Support\Facades\DB;

/** @param list<string> $columns @return array{rows:int, sha256:string} */
function customerRehearsalTableSnapshot(string $table, array $columns, ?string $connection = null): array
{
    $hashes = [];
    foreach (DB::connection($connection)->table($table)->select($columns)->cursor() as $row) {
        $hashes[] = hash('sha256', json_encode($row, JSON_THROW_ON_ERROR));
    }
    sort($hashes, SORT_STRING);

    return ['rows' => count($hashes), 'sha256' => hash('sha256', implode('', $hashes))];
}
