<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_social_insurance_policies')) {
            Schema::create('hr_social_insurance_policies', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name')->index();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable()->index();
                $table->decimal('employee_contribution_rate', 7, 4)->default(0);
                $table->decimal('employer_contribution_rate', 7, 4)->default(0);
                $table->decimal('minimum_contribution_wage', 15, 2)->nullable();
                $table->decimal('maximum_contribution_wage', 15, 2)->nullable();
                $table->string('rounding_rule', 30)->default('nearest');
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employment_tax_policies')) {
            Schema::create('hr_employment_tax_policies', function (Blueprint $table): void {
                $table->id();
                $table->integer('doc_number')->nullable()->index();
                $table->string('doc_num')->nullable()->index();
                $table->foreignId('company_id')->constrained('companies')->cascadeOnDelete();
                $table->string('name')->index();
                $table->unsignedSmallInteger('tax_year')->index();
                $table->date('effective_from')->index();
                $table->date('effective_to')->nullable()->index();
                $table->decimal('annual_exemption_amount', 15, 2)->default(0);
                $table->string('rounding_rule', 30)->default('nearest');
                $table->string('status', 30)->default('active')->index();
                $table->text('notes')->nullable();
                $this->auditColumns($table);
            });
        }

        if (! Schema::hasTable('hr_employment_tax_brackets')) {
            Schema::create('hr_employment_tax_brackets', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('employment_tax_policy_id')->constrained('hr_employment_tax_policies')->cascadeOnDelete();
                $table->decimal('from_amount', 15, 2);
                $table->decimal('to_amount', 15, 2)->nullable();
                $table->decimal('rate', 7, 4);
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->index(['employment_tax_policy_id', 'sort_order'], 'hr_tax_brackets_policy_order_index');
            });
        }

        Schema::table('hr_employees', function (Blueprint $table): void {
            if (! Schema::hasColumn('hr_employees', 'insurance_status')) {
                $table->string('insurance_status', 30)->default('not_subject')->index();
            }
            if (! Schema::hasColumn('hr_employees', 'social_insurance_number')) {
                $table->string('social_insurance_number', 60)->nullable()->index();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_office_id')) {
                $table->foreignId('insurance_office_id')->nullable()->constrained('hr_insurance_offices')->nullOnDelete();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_start_date')) {
                $table->date('insurance_start_date')->nullable()->index();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_end_date')) {
                $table->date('insurance_end_date')->nullable()->index();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_contribution_wage')) {
                $table->decimal('insurance_contribution_wage', 15, 2)->nullable();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_non_coverage_reason')) {
                $table->string('insurance_non_coverage_reason')->nullable();
            }
            if (! Schema::hasColumn('hr_employees', 'insurance_notes')) {
                $table->text('insurance_notes')->nullable();
            }
            if (! Schema::hasColumn('hr_employees', 'tax_status')) {
                $table->string('tax_status', 30)->default('not_subject')->index();
            }
            if (! Schema::hasColumn('hr_employees', 'tax_start_date')) {
                $table->date('tax_start_date')->nullable()->index();
            }
            if (! Schema::hasColumn('hr_employees', 'tax_end_date')) {
                $table->date('tax_end_date')->nullable()->index();
            }
            if (! Schema::hasColumn('hr_employees', 'tax_special_treatment_reason')) {
                $table->string('tax_special_treatment_reason')->nullable();
            }
            if (! Schema::hasColumn('hr_employees', 'tax_notes')) {
                $table->text('tax_notes')->nullable();
            }
        });

        $this->restoreEmployeeActiveUniqueIndexes();

        $this->createPartialUniqueIndexIfMissing('hr_social_insurance_policies', 'hr_social_insurance_policies_doc_number_unique_active', 'CREATE UNIQUE INDEX hr_social_insurance_policies_doc_number_unique_active ON hr_social_insurance_policies (doc_number) WHERE deleted_at IS NULL AND doc_number IS NOT NULL');
        $this->createPartialUniqueIndexIfMissing('hr_social_insurance_policies', 'hr_social_insurance_policies_doc_num_unique_active', 'CREATE UNIQUE INDEX hr_social_insurance_policies_doc_num_unique_active ON hr_social_insurance_policies (doc_num) WHERE deleted_at IS NULL AND doc_num IS NOT NULL');
        $this->createPartialUniqueIndexIfMissing('hr_social_insurance_policies', 'hr_social_insurance_policies_company_name_unique_active', 'CREATE UNIQUE INDEX hr_social_insurance_policies_company_name_unique_active ON hr_social_insurance_policies (company_id, name) WHERE deleted_at IS NULL');
        $this->createPartialUniqueIndexIfMissing('hr_employment_tax_policies', 'hr_employment_tax_policies_doc_number_unique_active', 'CREATE UNIQUE INDEX hr_employment_tax_policies_doc_number_unique_active ON hr_employment_tax_policies (doc_number) WHERE deleted_at IS NULL AND doc_number IS NOT NULL');
        $this->createPartialUniqueIndexIfMissing('hr_employment_tax_policies', 'hr_employment_tax_policies_doc_num_unique_active', 'CREATE UNIQUE INDEX hr_employment_tax_policies_doc_num_unique_active ON hr_employment_tax_policies (doc_num) WHERE deleted_at IS NULL AND doc_num IS NOT NULL');
        $this->createPartialUniqueIndexIfMissing('hr_employment_tax_policies', 'hr_employment_tax_policies_company_name_unique_active', 'CREATE UNIQUE INDEX hr_employment_tax_policies_company_name_unique_active ON hr_employment_tax_policies (company_id, name) WHERE deleted_at IS NULL');
        $this->createPartialUniqueIndexIfMissing('hr_employees', 'hr_employees_social_insurance_number_unique_active', 'CREATE UNIQUE INDEX hr_employees_social_insurance_number_unique_active ON hr_employees (social_insurance_number) WHERE deleted_at IS NULL AND social_insurance_number IS NOT NULL');

        if (Schema::hasIndex('hr_biometric_devices', 'hr_biometric_devices_device_uid_unique')) {
            Schema::table('hr_biometric_devices', function (Blueprint $table): void {
                $table->dropUnique('hr_biometric_devices_device_uid_unique');
            });
        }
        DB::statement('DROP INDEX IF EXISTS hr_biometric_devices_device_uid_unique_active');
        Schema::table('hr_biometric_devices', function (Blueprint $table): void {
            $table->string('device_uid', 120)->nullable()->change();
        });
        $this->restoreBiometricDeviceActiveUniqueIndexes();
    }

    public function down(): void
    {
        $this->prepareBiometricDeviceUidsForRollback();
        Schema::table('hr_biometric_devices', function (Blueprint $table): void {
            $table->string('device_uid', 120)->nullable(false)->change();
        });
        $this->restoreBiometricDeviceActiveUniqueIndexes();
        DB::statement('DROP INDEX IF EXISTS hr_biometric_devices_company_device_uid_unique_active');
        DB::statement('CREATE UNIQUE INDEX hr_biometric_devices_device_uid_unique_active ON hr_biometric_devices (device_uid) WHERE deleted_at IS NULL');

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropIndex('hr_employees_insurance_status_index');
            $table->dropIndex('hr_employees_social_insurance_number_index');
            $table->dropIndex('hr_employees_insurance_start_date_index');
            $table->dropIndex('hr_employees_insurance_end_date_index');
            $table->dropIndex('hr_employees_tax_status_index');
            $table->dropIndex('hr_employees_tax_start_date_index');
            $table->dropIndex('hr_employees_tax_end_date_index');
        });
        DB::statement('DROP INDEX IF EXISTS hr_employees_social_insurance_number_unique_active');

        Schema::table('hr_employees', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('insurance_office_id');
            $table->dropColumn([
                'insurance_status',
                'social_insurance_number',
                'insurance_start_date',
                'insurance_end_date',
                'insurance_contribution_wage',
                'insurance_non_coverage_reason',
                'insurance_notes',
                'tax_status',
                'tax_start_date',
                'tax_end_date',
                'tax_special_treatment_reason',
                'tax_notes',
            ]);
        });

        $this->restoreEmployeeActiveUniqueIndexes();

        Schema::dropIfExists('hr_employment_tax_brackets');
        Schema::dropIfExists('hr_employment_tax_policies');
        Schema::dropIfExists('hr_social_insurance_policies');
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

    private function restoreEmployeeActiveUniqueIndexes(): void
    {
        $this->restoreActiveUniqueIndexes('hr_employees', ['doc_number', 'doc_num', 'employee_code', 'national_id', 'email', 'work_email']);
    }

    private function restoreBiometricDeviceActiveUniqueIndexes(): void
    {
        $this->restoreActiveUniqueIndexes('hr_biometric_devices', ['doc_number', 'doc_num']);
    }

    private function createPartialUniqueIndexIfMissing(string $tableName, string $indexName, string $statement): void
    {
        if (! Schema::hasIndex($tableName, $indexName)) {
            DB::statement($statement);
        }
    }

    /**
     * @param  list<string>  $columns
     */
    private function restoreActiveUniqueIndexes(string $tableName, array $columns): void
    {
        $grammar = DB::getQueryGrammar();
        $table = $grammar->wrapTable($tableName);
        $deletedAt = $grammar->wrap('deleted_at');

        foreach ($columns as $column) {
            if (! Schema::hasColumn($tableName, $column)) {
                continue;
            }

            $index = $grammar->wrap("{$tableName}_{$column}_unique_active");
            $wrappedColumn = $grammar->wrap($column);

            DB::statement("DROP INDEX IF EXISTS {$index}");
            DB::statement("CREATE UNIQUE INDEX {$index} ON {$table} ({$wrappedColumn}) WHERE {$deletedAt} IS NULL AND {$wrappedColumn} IS NOT NULL");
        }
    }

    private function prepareBiometricDeviceUidsForRollback(): void
    {
        $used = [];

        DB::table('hr_biometric_devices')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'doc_num', 'device_uid'])
            ->each(function (object $device) use (&$used): void {
                $deviceUid = trim((string) $device->device_uid);

                if ($deviceUid === '' || isset($used[$deviceUid])) {
                    $base = trim((string) $device->doc_num) ?: 'device';
                    $deviceUid = substr($base, 0, 100).'-'.$device->id;
                    $attempt = 1;

                    while (isset($used[$deviceUid])) {
                        $deviceUid = substr($base, 0, 95).'-'.$device->id.'-'.$attempt;
                        $attempt++;
                    }

                    DB::table('hr_biometric_devices')->where('id', $device->id)->update(['device_uid' => $deviceUid]);
                }

                $used[$deviceUid] = true;
            });

        DB::table('hr_biometric_devices')
            ->whereNull('device_uid')
            ->orderBy('id')
            ->get(['id', 'doc_num'])
            ->each(function (object $device): void {
                $base = trim((string) $device->doc_num) ?: 'deleted-device';

                DB::table('hr_biometric_devices')
                    ->where('id', $device->id)
                    ->update(['device_uid' => substr($base, 0, 100).'-'.$device->id]);
            });
    }
};
