<?php

namespace Modules\HR\Services;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Services\ExcelImportService;
use Modules\HR\Models\HrAttendanceEvent;
use Modules\HR\Models\HrBiometricDevice;
use Modules\HR\Models\HrEmployee;
use Modules\HR\Models\HrEmployeeBiometricMapping;
use Modules\HR\Models\HrEmployeeShiftAssignment;
use Modules\HR\Models\HrShift;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Ramsey\Uuid\Uuid;

final class HrAttendanceImportService
{
    /** @var array<string, list<string>> */
    private array $headerAliases = [
        'code' => ['biometric_code', 'employee_code', 'employee_id', 'person_id', 'emp_no', 'enroll_number', 'enroll_no', 'user_id', 'userid', 'pin', 'ac_no', 'code', 'كود_البصمة', 'كود_الموظف', 'رقم_الموظف'],
        'timestamp' => ['punched_at', 'punch_time', 'record_time', 'timestamp', 'date_time', 'datetime', 'check_time', 'time', 'وقت_البصمة', 'التاريخ_والوقت'],
        'date' => ['punch_date', 'date', 'التاريخ', 'تاريخ_البصمة'],
        'time' => ['punch_clock', 'clock', 'وقت', 'الوقت'],
        'type' => ['punch_type', 'event_type', 'attendance_status', 'check_type', 'in_out', 'state', 'status', 'type', 'الحركة', 'نوع_الحركة', 'الحالة'],
    ];

    public function __construct(
        private readonly ExcelImportService $excelImports,
        private readonly HrAttendanceService $attendance,
        private readonly HrLifecycleAuditLogger $audit,
    ) {}

    /**
     * @return array{rows: int, employees: int, check_ins: int, check_outs: int, ignored_duplicates: int}
     */
    public function import(HrBiometricDevice $device, UploadedFile $workbook, Request $request): array
    {
        $this->excelImports->assertSafeUpload($workbook);
        $rows = $this->readRows($workbook);
        $normalized = $this->normalizeRows($device, $rows);

        return DB::transaction(function () use ($device, $request, $normalized): array {
            $counts = ['rows' => 0, 'employees' => 0, 'check_ins' => 0, 'check_outs' => 0, 'ignored_duplicates' => 0];
            $employees = [];

            foreach ($normalized as $row) {
                $existing = HrAttendanceEvent::query()
                    ->where('employee_id', $row['employee']->getKey())
                    ->where('idempotency_key', $row['idempotency_key'])
                    ->first();

                if ($existing instanceof HrAttendanceEvent) {
                    if ($existing->event_type !== $row['event_type']) {
                        throw new DomainException(__('hr_attendance.messages.idempotency_conflict'));
                    }

                    $counts['ignored_duplicates']++;

                    continue;
                }

                $this->attendance->recordBiometricPunch(
                    $row['employee'],
                    $request->user(),
                    [
                        'event_type' => $row['event_type'],
                        'idempotency_key' => $row['idempotency_key'],
                        'notes' => __('hr_attendance.import.event_note', ['device' => $device->doc_num, 'row' => $row['excel_row']]),
                        'client_context' => ['device_doc_num' => $device->doc_num, 'excel_row' => $row['excel_row']],
                    ],
                    $row['occurred_at'],
                );

                DB::table('hr_attendance_raw_logs')->insert([
                    'employee_id' => $row['employee']->getKey(),
                    'device_id' => $device->getKey(),
                    'punched_at' => $row['occurred_at'],
                    'punch_type' => $row['event_type'],
                    'source' => 'excel_import',
                    'raw_payload' => json_encode([
                        'idempotency_key' => $row['idempotency_key'],
                        'excel_row' => $row['excel_row'],
                        'biometric_code' => $row['biometric_code'],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $counts['rows']++;
                $counts[$row['event_type'] === HrAttendanceEvent::CheckIn ? 'check_ins' : 'check_outs']++;
                $employees[$row['employee']->getKey()] = true;
            }

            $counts['employees'] = count($employees);
            $this->audit->logStrict(
                $request,
                'attendance.biometric_import',
                (int) $device->company_id,
                [...$counts, 'device_doc_num' => $device->doc_num],
                $device,
                'biometric-import:'.$device->getKey().':'.hash_file('sha256', $request->file('workbook')->getRealPath()),
            );

            return $counts;
        });
    }

    /**
     * @return list<array{excel_row: int, biometric_code: string, occurred_at: CarbonImmutable, explicit_type: string|null}>
     */
    private function readRows(UploadedFile $workbook): array
    {
        $reader = new Xlsx;
        $reader->setReadDataOnly(false);
        $spreadsheet = $reader->load($workbook->getRealPath());

        try {
            $sheet = $spreadsheet->getSheet(0);
            $highestRow = $sheet->getHighestDataRow();
            $highestColumnIndex = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

            if ($highestRow > (int) config('excel_imports.max_rows') + 10 || $highestColumnIndex > 100) {
                throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.max_rows')]);
            }

            for ($rowNumber = 1; $rowNumber <= $highestRow; $rowNumber++) {
                for ($column = 1; $column <= $highestColumnIndex; $column++) {
                    if ($sheet->getCell([$column, $rowNumber])->isFormula()) {
                        throw ValidationException::withMessages(['workbook' => __('excel_imports.validation.formulas_not_allowed')]);
                    }
                }
            }

            [$headerRow, $columns] = $this->detectHeaders($sheet);
            $rows = [];

            for ($rowNumber = $headerRow + 1; $rowNumber <= $highestRow; $rowNumber++) {
                $code = $this->cellString($sheet->getCell([$columns['code'], $rowNumber]));

                if ($code === '') {
                    continue;
                }

                $occurredAt = isset($columns['timestamp'])
                    ? $this->cellDateTime($sheet->getCell([$columns['timestamp'], $rowNumber]))
                    : $this->separateDateTime(
                        $sheet->getCell([$columns['date'], $rowNumber]),
                        $sheet->getCell([$columns['time'], $rowNumber]),
                    );
                $type = isset($columns['type'])
                    ? $this->eventType($this->cellString($sheet->getCell([$columns['type'], $rowNumber])), $rowNumber)
                    : null;

                if (! $occurredAt instanceof CarbonImmutable) {
                    throw ValidationException::withMessages([
                        'workbook' => __('hr_attendance.import.validation.invalid_datetime', ['row' => $rowNumber]),
                    ]);
                }

                if ($occurredAt->greaterThan(now()->addMinutes(5))) {
                    throw ValidationException::withMessages([
                        'workbook' => __('hr_attendance.import.validation.future_datetime', ['row' => $rowNumber]),
                    ]);
                }

                $rows[] = ['excel_row' => $rowNumber, 'biometric_code' => $code, 'occurred_at' => $occurredAt, 'explicit_type' => $type];
            }

            if ($rows === []) {
                throw ValidationException::withMessages(['workbook' => __('hr_attendance.import.validation.empty')]);
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    /**
     * @param  list<array{excel_row: int, biometric_code: string, occurred_at: CarbonImmutable, explicit_type: string|null}>  $rows
     * @return list<array{excel_row: int, biometric_code: string, occurred_at: CarbonImmutable, event_type: string, idempotency_key: string, employee: HrEmployee}>
     */
    private function normalizeRows(HrBiometricDevice $device, array $rows): array
    {
        $codes = array_values(array_unique(array_column($rows, 'biometric_code')));
        $mappings = HrEmployeeBiometricMapping::query()
            ->with('employee')
            ->where('company_id', $device->company_id)
            ->where('biometric_device_id', $device->getKey())
            ->where('is_active', true)
            ->whereIn('biometric_code', $codes)
            ->get()
            ->keyBy(fn (HrEmployeeBiometricMapping $mapping): string => (string) $mapping->biometric_code);
        $errors = [];

        foreach ($rows as $row) {
            $mapping = $mappings->get($row['biometric_code']);

            if (! $mapping instanceof HrEmployeeBiometricMapping || ! $mapping->employee instanceof HrEmployee) {
                $errors[] = __('hr_attendance.import.validation.unmapped_code', ['row' => $row['excel_row'], 'code' => $row['biometric_code']]);
            } elseif ($mapping->employee->status !== 'active' || ! $mapping->employee->attendance_tracking_enabled) {
                $errors[] = __('hr_attendance.import.validation.employee_unavailable', ['row' => $row['excel_row'], 'employee' => $mapping->employee->doc_num]);
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['workbook' => $errors]);
        }

        $shiftCache = [];
        $collection = collect($rows)
            ->map(function (array $row) use ($mappings, &$shiftCache): array {
                $mapping = $mappings->get($row['biometric_code']);

                return [...$row, 'work_date' => $this->workDate($mapping->employee, $row['occurred_at'], $shiftCache)];
            })
            ->sortBy(fn (array $row): string => $row['occurred_at']->format('Y-m-d H:i:s.u'));
        $hasExplicitTypes = $collection->contains(fn (array $row): bool => $row['explicit_type'] !== null);

        if ($hasExplicitTypes && $collection->contains(fn (array $row): bool => $row['explicit_type'] === null)) {
            throw ValidationException::withMessages(['workbook' => __('hr_attendance.import.validation.mixed_types')]);
        }

        $selected = $hasExplicitTypes ? $collection : $this->firstAndLastPunches($collection);

        return $selected->map(function (array $row) use ($device, $mappings): array {
            $mapping = $mappings->get($row['biometric_code']);
            $eventType = $row['explicit_type'];

            return [
                ...$row,
                'event_type' => $eventType,
                'employee' => $mapping->employee,
                'idempotency_key' => Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    implode('|', ['mgypack-attendance', $device->getKey(), $row['biometric_code'], $row['occurred_at']->format('Y-m-d H:i:s.u'), $eventType]),
                )->toString(),
            ];
        })->values()->all();
    }

    /** @return Collection<int, array<string, mixed>> */
    private function firstAndLastPunches(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => $row['biometric_code'].'|'.$row['work_date'])
            ->flatMap(function (Collection $dayRows): array {
                $ordered = $dayRows->sortBy(fn (array $row): string => $row['occurred_at']->format('Y-m-d H:i:s.u'))->values();

                if ($ordered->count() < 2) {
                    $row = $ordered->first();
                    throw ValidationException::withMessages([
                        'workbook' => __('hr_attendance.import.validation.single_punch', ['row' => $row['excel_row'], 'code' => $row['biometric_code']]),
                    ]);
                }

                return [
                    [...$ordered->first(), 'explicit_type' => HrAttendanceEvent::CheckIn],
                    [...$ordered->last(), 'explicit_type' => HrAttendanceEvent::CheckOut],
                ];
            })
            ->sortBy(fn (array $row): string => $row['occurred_at']->format('Y-m-d H:i:s.u'))
            ->values();
    }

    /** @return array{0: int, 1: array<string, int>} */
    private function detectHeaders($sheet): array
    {
        $highestColumn = min($sheet->getHighestDataColumn(), 'ZZ');
        $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

        for ($row = 1; $row <= min(10, $sheet->getHighestDataRow()); $row++) {
            $columns = [];

            for ($column = 1; $column <= $highestColumnIndex; $column++) {
                $header = $this->normalizeHeader($this->cellString($sheet->getCell([$column, $row])));

                foreach ($this->headerAliases as $key => $aliases) {
                    if (in_array($header, $aliases, true)) {
                        $columns[$key] = $column;
                    }
                }
            }

            if (isset($columns['code']) && (isset($columns['timestamp']) || (isset($columns['date']) && isset($columns['time'])))) {
                return [$row, $columns];
            }
        }

        throw ValidationException::withMessages(['workbook' => __('hr_attendance.import.validation.headers')]);
    }

    private function normalizeHeader(string $header): string
    {
        return trim((string) preg_replace('/[^\pL\pN]+/u', '_', mb_strtolower($header)), '_');
    }

    private function cellString(Cell $cell): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", (string) $cell->getFormattedValue()));
    }

    private function cellDateTime(Cell $cell): ?CarbonImmutable
    {
        $value = $cell->getValue();

        if (is_numeric($value) && ExcelDate::isDateTime($cell)) {
            return CarbonImmutable::instance(ExcelDate::excelToDateTimeObject((float) $value));
        }

        return $this->parseDateTime($this->cellString($cell));
    }

    private function separateDateTime(Cell $dateCell, Cell $timeCell): ?CarbonImmutable
    {
        $date = $dateCell->getValue();
        $time = $timeCell->getValue();

        if (is_numeric($date) && ExcelDate::isDateTime($dateCell)) {
            $date = ExcelDate::excelToDateTimeObject((float) $date)->format('Y-m-d');
        } else {
            $date = $this->cellString($dateCell);
        }

        if (is_numeric($time) && ExcelDate::isDateTime($timeCell)) {
            $time = ExcelDate::excelToDateTimeObject((float) $time)->format('H:i:s');
        } else {
            $time = $this->cellString($timeCell);
        }

        return $this->parseDateTime(trim($date.' '.$time));
    }

    private function parseDateTime(string $value): ?CarbonImmutable
    {
        foreach (['Y-m-d H:i:s', 'Y-m-d H:i', 'Y/m/d H:i:s', 'Y/m/d H:i', 'd/m/Y H:i:s', 'd/m/Y H:i', 'j/n/Y G:i:s', 'j/n/Y G:i', 'd-m-Y H:i:s', 'd-m-Y H:i'] as $format) {
            try {
                $parsed = CarbonImmutable::createFromFormat('!'.$format, $value);

                if ($parsed instanceof DateTimeInterface && $parsed->format($format) === $value) {
                    return $parsed;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function eventType(string $value, int $row): ?string
    {
        if ($value === '') {
            return null;
        }

        $normalized = $this->normalizeHeader($value);
        $type = match ($normalized) {
            '0', 'in', 'check_in', 'checkin', 'on_duty', 'دخول', 'حضور' => HrAttendanceEvent::CheckIn,
            '1', 'out', 'check_out', 'checkout', 'off_duty', 'خروج', 'انصراف' => HrAttendanceEvent::CheckOut,
            default => null,
        };

        if ($type === null) {
            throw ValidationException::withMessages([
                'workbook' => __('hr_attendance.import.validation.invalid_type', ['row' => $row, 'value' => $value]),
            ]);
        }

        return $type;
    }

    /** @param  array<string, HrShift|null>  $shiftCache */
    private function workDate(HrEmployee $employee, CarbonImmutable $occurredAt, array &$shiftCache): string
    {
        $cacheKey = $employee->getKey().'|'.$occurredAt->toDateString();

        if (! array_key_exists($cacheKey, $shiftCache)) {
            $assignment = HrEmployeeShiftAssignment::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('effective_from', '<=', $occurredAt->toDateString())
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $occurredAt->toDateString()))
                ->latest('effective_from')
                ->latest('id')
                ->first();
            $shiftCache[$cacheKey] = $assignment instanceof HrEmployeeShiftAssignment
                ? HrShift::withTrashed()->find($assignment->shift_id)
                : $employee->defaultShift()->withTrashed()->first();
        }

        $shift = $shiftCache[$cacheKey];

        if ($shift?->crosses_midnight && filled($shift->end_time) && $occurredAt->format('H:i:s') <= $shift->end_time) {
            return $occurredAt->subDay()->toDateString();
        }

        return $occurredAt->toDateString();
    }
}
