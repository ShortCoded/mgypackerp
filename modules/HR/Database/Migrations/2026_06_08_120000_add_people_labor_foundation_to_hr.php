<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->foundationTables();
        $this->alignShiftsTable();
        $this->alignBiometricDevicesTable();
        $this->extendEmployeesTable();
        $this->extendEmployeeDocumentsTable();
        $this->biometricMappingsTable();
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_employee_biometric_mappings');

        $this->dropEmployeeDocumentColumns();
        $this->dropEmployeeColumns();
        $this->dropShiftColumns();
        $this->dropBiometricDeviceColumns();

        Schema::dropIfExists('hr_document_types');
        Schema::dropIfExists('hr_professions');
    }

    private function alignShiftsTable(): void
    {
        if (! Schema::hasTable('hr_shifts')) {
            Schema::create('hr_shifts', function (Blueprint $table): void {
                $this->foundationColumns($table);
                $table->time('start_time')->nullable();
                $table->time('end_time')->nullable();
                $table->unsignedSmallInteger('break_minutes')->default(0);
                $table->boolean('crosses_midnight')->default(false);
                $this->auditColumns($table);
            });
        } else {
            Schema::table('hr_shifts', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'doc_number', fn (Blueprint $table): mixed => $table->integer('doc_number')->nullable()->index());
                $this->addColumnIfMissing($table, 'doc_num', fn (Blueprint $table): mixed => $table->string('doc_num')->nullable()->index());
                $this->addColumnIfMissing($table, 'notes', fn (Blueprint $table): mixed => $table->text('notes')->nullable());
            });
        }

        foreach (['doc_number', 'doc_num', 'name'] as $column) {
            $this->createActiveUniqueIndex('hr_shifts', $column);
        }

        $this->backfillDocumentNumbers('hr_shifts', 'hr_shifts');
    }

    private function foundationTables(): void
    {
        foreach (['hr_professions', 'hr_document_types'] as $tableName) {
            if (! Schema::hasTable($tableName)) {
                Schema::create($tableName, function (Blueprint $table): void {
                    $this->foundationColumns($table);
                    $this->auditColumns($table);
                });
            }

            foreach (['doc_number', 'doc_num', 'name'] as $column) {
                $this->createActiveUniqueIndex($tableName, $column);
            }
        }
    }

    private function alignBiometricDevicesTable(): void
    {
        if (! Schema::hasTable('hr_biometric_devices')) {
            Schema::create('hr_biometric_devices', function (Blueprint $table): void {
                $this->foundationColumns($table);
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
                $table->string('device_uid', 120)->unique();
                $table->string('serial_number', 120)->nullable()->index();
                $table->string('location')->nullable();
                $this->auditColumns($table);
            });
        } else {
            Schema::table('hr_biometric_devices', function (Blueprint $table): void {
                $this->addColumnIfMissing($table, 'doc_number', fn (Blueprint $table): mixed => $table->integer('doc_number')->nullable()->index());
                $this->addColumnIfMissing($table, 'doc_num', fn (Blueprint $table): mixed => $table->string('doc_num')->nullable()->index());
                $this->addColumnIfMissing($table, 'company_id', fn (Blueprint $table): mixed => $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete());
                $this->addColumnIfMissing($table, 'serial_number', fn (Blueprint $table): mixed => $table->string('serial_number', 120)->nullable()->index());
                $this->addColumnIfMissing($table, 'location', fn (Blueprint $table): mixed => $table->string('location')->nullable());
                $this->addColumnIfMissing($table, 'notes', fn (Blueprint $table): mixed => $table->text('notes')->nullable());
            });
        }

        foreach (['doc_number', 'doc_num', 'device_uid'] as $column) {
            $this->createActiveUniqueIndex('hr_biometric_devices', $column);
        }

        $this->backfillDocumentNumbers('hr_biometric_devices', 'hr_biometric_devices');
    }

    private function extendEmployeesTable(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'person_type', fn (Blueprint $table): mixed => $table->string('person_type', 40)->default('fixed_employee')->index());
            $this->addColumnIfMissing($table, 'alternate_phone', fn (Blueprint $table): mixed => $table->string('alternate_phone', 50)->nullable());
            $this->addColumnIfMissing($table, 'start_date', fn (Blueprint $table): mixed => $table->date('start_date')->nullable()->index());
            $this->addColumnIfMissing($table, 'end_date', fn (Blueprint $table): mixed => $table->date('end_date')->nullable()->index());
            $this->addColumnIfMissing($table, 'photo_archive_file_id', fn (Blueprint $table): mixed => $table->foreignId('photo_archive_file_id')->nullable()->constrained('archive_files')->nullOnDelete());
            $this->addColumnIfMissing($table, 'profession_id', fn (Blueprint $table): mixed => $table->foreignId('profession_id')->nullable()->constrained('hr_professions')->nullOnDelete());
            $this->addColumnIfMissing($table, 'employment_type_id', fn (Blueprint $table): mixed => $table->foreignId('employment_type_id')->nullable()->constrained('hr_employment_types')->nullOnDelete());
            $this->addColumnIfMissing($table, 'attendance_tracking_enabled', fn (Blueprint $table): mixed => $table->boolean('attendance_tracking_enabled')->default(true));
            $this->addColumnIfMissing($table, 'attendance_policy_type', fn (Blueprint $table): mixed => $table->string('attendance_policy_type', 60)->nullable()->index());
            $this->addColumnIfMissing($table, 'default_shift_id', fn (Blueprint $table): mixed => $table->foreignId('default_shift_id')->nullable()->constrained('hr_shifts')->nullOnDelete());
            $this->addColumnIfMissing($table, 'allow_late_minutes', fn (Blueprint $table): mixed => $table->unsignedSmallInteger('allow_late_minutes')->nullable());
            $this->addColumnIfMissing($table, 'allow_early_leave_minutes', fn (Blueprint $table): mixed => $table->unsignedSmallInteger('allow_early_leave_minutes')->nullable());
            $this->addColumnIfMissing($table, 'overtime_enabled', fn (Blueprint $table): mixed => $table->boolean('overtime_enabled')->default(false));
            $this->addColumnIfMissing($table, 'pay_basis', fn (Blueprint $table): mixed => $table->string('pay_basis', 40)->nullable()->index());
            $this->addColumnIfMissing($table, 'payroll_currency_id', fn (Blueprint $table): mixed => $table->foreignId('payroll_currency_id')->nullable()->constrained('currencies')->nullOnDelete());
            $this->addColumnIfMissing($table, 'exchange_rate', fn (Blueprint $table): mixed => $table->decimal('exchange_rate', 18, 6)->nullable());
            $this->addColumnIfMissing($table, 'weekly_wage', fn (Blueprint $table): mixed => $table->decimal('weekly_wage', 15, 4)->nullable());
            $this->addColumnIfMissing($table, 'daily_wage', fn (Blueprint $table): mixed => $table->decimal('daily_wage', 15, 4)->nullable());
            $this->addColumnIfMissing($table, 'hourly_wage', fn (Blueprint $table): mixed => $table->decimal('hourly_wage', 15, 4)->nullable());
            $this->addColumnIfMissing($table, 'shift_wage', fn (Blueprint $table): mixed => $table->decimal('shift_wage', 15, 4)->nullable());
            $this->addColumnIfMissing($table, 'piece_rate', fn (Blueprint $table): mixed => $table->decimal('piece_rate', 15, 4)->nullable());
            $this->addColumnIfMissing($table, 'payment_method', fn (Blueprint $table): mixed => $table->string('payment_method', 40)->nullable());
        });
    }

    private function extendEmployeeDocumentsTable(): void
    {
        if (! Schema::hasTable('hr_employee_documents')) {
            return;
        }

        Schema::table('hr_employee_documents', function (Blueprint $table): void {
            $this->addColumnIfMissing($table, 'document_type_id', fn (Blueprint $table): mixed => $table->foreignId('document_type_id')->nullable()->constrained('hr_document_types')->nullOnDelete());
            $this->addColumnIfMissing($table, 'archive_file_id', fn (Blueprint $table): mixed => $table->foreignId('archive_file_id')->nullable()->constrained('archive_files')->nullOnDelete());
            $this->addColumnIfMissing($table, 'document_number_text', fn (Blueprint $table): mixed => $table->string('document_number_text', 120)->nullable()->index());
            $this->addColumnIfMissing($table, 'issue_date', fn (Blueprint $table): mixed => $table->date('issue_date')->nullable());
            $this->addColumnIfMissing($table, 'alert_before_expiry_days', fn (Blueprint $table): mixed => $table->unsignedSmallInteger('alert_before_expiry_days')->nullable());
            $this->addColumnIfMissing($table, 'file_label', fn (Blueprint $table): mixed => $table->string('file_label', 80)->nullable());
            $this->addColumnIfMissing($table, 'sort_order', fn (Blueprint $table): mixed => $table->unsignedSmallInteger('sort_order')->default(0));
        });
    }

    private function biometricMappingsTable(): void
    {
        if (! Schema::hasTable('hr_employee_biometric_mappings')) {
            Schema::create('hr_employee_biometric_mappings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('company_id')->nullable()->constrained('companies')->nullOnDelete();
                $table->foreignId('employee_id')->constrained('hr_employees')->cascadeOnDelete();
                $table->foreignId('biometric_device_id')->nullable()->constrained('hr_biometric_devices')->nullOnDelete();
                $table->string('biometric_code', 120)->index();
                $table->boolean('is_active')->default(true)->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        $this->createActiveBiometricMappingIndex();
    }

    private function dropEmployeeColumns(): void
    {
        if (! Schema::hasTable('hr_employees')) {
            return;
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            foreach ([
                'photo_archive_file_id',
                'profession_id',
                'employment_type_id',
                'default_shift_id',
                'payroll_currency_id',
            ] as $column) {
                if (Schema::hasColumn('hr_employees', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            $this->dropColumnIfExists($table, [
                'person_type',
                'alternate_phone',
                'start_date',
                'end_date',
                'attendance_tracking_enabled',
                'attendance_policy_type',
                'allow_late_minutes',
                'allow_early_leave_minutes',
                'overtime_enabled',
                'pay_basis',
                'exchange_rate',
                'weekly_wage',
                'daily_wage',
                'hourly_wage',
                'shift_wage',
                'piece_rate',
                'payment_method',
            ]);
        });
    }

    private function dropEmployeeDocumentColumns(): void
    {
        if (! Schema::hasTable('hr_employee_documents')) {
            return;
        }

        Schema::table('hr_employee_documents', function (Blueprint $table): void {
            foreach (['document_type_id', 'archive_file_id'] as $column) {
                if (Schema::hasColumn('hr_employee_documents', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }

            $this->dropColumnIfExists($table, [
                'document_number_text',
                'issue_date',
                'alert_before_expiry_days',
                'file_label',
                'sort_order',
            ]);
        });
    }

    private function dropShiftColumns(): void
    {
        if (! Schema::hasTable('hr_shifts')) {
            return;
        }

        Schema::table('hr_shifts', function (Blueprint $table): void {
            $this->dropColumnIfExists($table, [
                'doc_number',
                'doc_num',
            ]);
        });
    }

    private function dropBiometricDeviceColumns(): void
    {
        if (! Schema::hasTable('hr_biometric_devices')) {
            return;
        }

        Schema::table('hr_biometric_devices', function (Blueprint $table): void {
            if (Schema::hasColumn('hr_biometric_devices', 'company_id')) {
                $table->dropConstrainedForeignId('company_id');
            }

            $this->dropColumnIfExists($table, [
                'doc_number',
                'doc_num',
                'serial_number',
                'location',
                'notes',
            ]);
        });
    }

    private function foundationColumns(Blueprint $table): void
    {
        $table->id();
        $table->integer('doc_number')->nullable()->index();
        $table->string('doc_num')->nullable()->index();
        $table->string('name')->index();
        $table->string('status', 30)->default('active')->index();
        $table->text('notes')->nullable();
    }

    private function auditColumns(Blueprint $table): void
    {
        $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('restored_at')->nullable();
        $table->timestamps();
        $table->softDeletes()->index();
    }

    private function addColumnIfMissing(Blueprint $table, string $column, callable $callback): void
    {
        if (! Schema::hasColumn($table->getTable(), $column)) {
            $callback($table);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function dropColumnIfExists(Blueprint $table, array $columns): void
    {
        $existing = array_values(array_filter(
            $columns,
            fn (string $column): bool => Schema::hasColumn($table->getTable(), $column),
        ));

        if ($existing !== []) {
            $table->dropColumn($existing);
        }
    }

    private function createActiveUniqueIndex(string $table, string $column): void
    {
        if (! Schema::hasColumn($table, $column) || ! Schema::hasColumn($table, 'deleted_at')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $wrappedIndex = $grammar->wrap("{$table}_{$column}_unique_active");
        $wrappedTable = $grammar->wrapTable($table);
        $wrappedColumn = $grammar->wrap($column);
        $wrappedDeletedAt = $grammar->wrap('deleted_at');

        match (DB::getDriverName()) {
            'pgsql', 'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$wrappedIndex} ON {$wrappedTable} ({$wrappedColumn}) WHERE {$wrappedDeletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL"),
            default => null,
        };
    }

    private function createActiveBiometricMappingIndex(): void
    {
        if (! Schema::hasTable('hr_employee_biometric_mappings')) {
            return;
        }

        $grammar = DB::getQueryGrammar();
        $index = $grammar->wrap('hr_employee_biometric_mappings_active_code_unique');
        $table = $grammar->wrapTable('hr_employee_biometric_mappings');
        $company = $grammar->wrap('company_id');
        $device = $grammar->wrap('biometric_device_id');
        $code = $grammar->wrap('biometric_code');
        $active = $grammar->wrap('is_active');
        $deletedAt = $grammar->wrap('deleted_at');

        match (DB::getDriverName()) {
            'pgsql' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} (COALESCE({$company}, 0), COALESCE({$device}, 0), {$code}) WHERE {$deletedAt} IS NULL AND {$active} = TRUE"),
            'sqlite' => DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS {$index} ON {$table} (COALESCE({$company}, 0), COALESCE({$device}, 0), {$code}) WHERE {$deletedAt} IS NULL AND {$active} = 1"),
            default => null,
        };
    }

    private function backfillDocumentNumbers(string $table, string $documentKey): void
    {
        if (! Schema::hasColumn($table, 'doc_number') || ! Schema::hasColumn($table, 'doc_num')) {
            return;
        }

        $prefix = (string) config("document_numbers.{$documentKey}.prefix", '');
        $padding = (int) config("document_numbers.{$documentKey}.padding", 5);
        $nextNumber = (int) DB::table($table)->max('doc_number');

        DB::table($table)
            ->whereNull('doc_number')
            ->orWhereNull('doc_num')
            ->orderBy('id')
            ->get(['id', 'doc_number'])
            ->each(function (object $row) use ($table, $prefix, $padding, &$nextNumber): void {
                $number = $row->doc_number ? (int) $row->doc_number : ++$nextNumber;

                DB::table($table)
                    ->where('id', $row->id)
                    ->update([
                        'doc_number' => $number,
                        'doc_num' => $prefix.str_pad((string) $number, $padding, '0', STR_PAD_LEFT),
                    ]);
            });
    }
};
