<?php

namespace Modules\Core\Imports\Contracts;

use Illuminate\Http\Request;
use Modules\Core\Models\ExcelImportBatch;

interface ExcelImportDefinition
{
    public function module(): string;

    public function permissionPrefix(): string;

    public function templateVersion(): string;

    public function dataSheet(): string;

    /**
     * @return list<string>
     */
    public function expectedSheets(): array;

    /**
     * @return array<string, array{label: string, required: bool, width?: int, comment?: string, lookup?: string}>
     */
    public function fields(): array;

    /**
     * @return array<string, array{title: string, rows: list<array{reference: string, label: string}>}>
     */
    public function lookups(int $companyId): array;

    /**
     * @return list<string>
     */
    public function instructions(): array;

    /**
     * @param  array<string, list<array{excel_row: int, data: array<string, string|null>}>>  $rowsBySheet
     * @return list<array{sheet_key: string, excel_row: int, data: array<string, mixed>, normalized_data: array<string, mixed>|null, import_key: string|null, issues: list<array<string, mixed>>}>
     */
    public function validateRows(array $rowsBySheet, Request $request): array;

    /**
     * @return list<array{sheet_key: string, excel_row: int, import_key?: string|null, doc_num: string, name: string}>
     */
    public function commit(ExcelImportBatch $batch, Request $request): array;
}
