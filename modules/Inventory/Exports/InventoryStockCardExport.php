<?php

namespace Modules\Inventory\Exports;

use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithCustomValueBinder;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Modules\Core\Services\DateFormatService;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

class InventoryStockCardExport extends DefaultValueBinder implements FromArray, ShouldAutoSize, WithCustomValueBinder, WithHeadings
{
    /** @param array<string, string|int> $totals */
    public function __construct(private readonly Collection $movements, private readonly array $totals) {}

    /** @return list<string> */
    public function headings(): array
    {
        return array_map(fn (string $key): string => __('inventory_correction.card.'.$key), ['date', 'source', 'store', 'type', 'unit', 'in', 'out', 'balance', 'reversal']);
    }

    /** @return list<list<mixed>> */
    public function array(): array
    {
        return [[__('inventory_correction.card.opening'), null, null, null, null, null, null, $this->totals['opening_balance'], null],
            ...$this->movements->map(fn ($row): array => [app(DateFormatService::class)->formatDate($row->transaction_date),
                $row->source_doc_num, $row->branchStore?->name, __('inventory.movements.types.'.$row->transaction_type),
                $row->product?->unit?->name, $row->quantity_in, $row->quantity_out, $row->running_balance,
                $row->is_reversal ? __('Yes') : __('No')])->all(),
            [__('Total'), null, null, null, null, $this->totals['quantity_in'], $this->totals['quantity_out'], $this->totals['closing_balance'], null]];
    }

    public function bindValue(Cell $cell, mixed $value): bool
    {
        $cell->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);

        return true;
    }
}
