<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('production_piece_approvals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_run_id')->constrained('production_runs')->restrictOnDelete();
            $table->foreignId('employee_id')->constrained('hr_employees')->restrictOnDelete();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('pay_basis', 40);
            $table->decimal('quantity', 20, 8);
            $table->decimal('rate', 15, 4);
            $table->decimal('run_good_base_quantity', 20, 8);
            $table->timestamp('approved_at');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['production_run_id', 'employee_id']);
            $table->index(['company_id', 'branch_id', 'approved_at'], 'production_piece_approvals_scope_index');
        });

        DB::table('production_runs')
            ->where('status', 'completed')
            ->whereNotNull('labor_details')
            ->orderBy('id')
            ->chunkById(100, function ($runs): void {
                foreach ($runs as $run) {
                    $details = json_decode((string) $run->labor_details, true, 512, JSON_THROW_ON_ERROR);
                    if (! is_array($details)) {
                        throw new RuntimeException('Completed production run has invalid labor evidence.');
                    }

                    $total = '0.00000000';
                    $employeeIds = [];
                    foreach ($details as $labor) {
                        if (! isset($labor['approved_piece_quantity'])) {
                            continue;
                        }

                        $employeeId = (int) ($labor['employee_id'] ?? 0);
                        $quantity = (string) $labor['approved_piece_quantity'];
                        $rate = (string) ($labor['piece_rate_snapshot'] ?? '0');
                        if ($employeeId <= 0
                            || in_array($employeeId, $employeeIds, true)
                            || ! isset($labor['piece_quantity_approved_at'], $labor['piece_quantity'])
                            || bccomp((string) $labor['piece_quantity'], $quantity, 8) !== 0
                            || bccomp($quantity, '0', 8) <= 0
                            || bccomp($rate, '0', 4) <= 0) {
                            throw new RuntimeException('Completed production run has ambiguous piece-pay evidence.');
                        }

                        $employeeIds[] = $employeeId;
                        $total = bcadd($total, $quantity, 8);
                        DB::table('production_piece_approvals')->insert([
                            'production_run_id' => $run->id,
                            'employee_id' => $employeeId,
                            'company_id' => $run->company_id,
                            'branch_id' => $run->branch_id,
                            'pay_basis' => 'piece_rate',
                            'quantity' => $quantity,
                            'rate' => $rate,
                            'run_good_base_quantity' => $run->good_base_quantity,
                            'approved_at' => $labor['piece_quantity_approved_at'],
                            'approved_by' => $labor['piece_quantity_approved_by'] ?? null,
                            'created_at' => now(),
                            'updated_at' => now(),
                        ]);
                    }

                    if (bccomp($total, (string) $run->good_base_quantity, 8) > 0) {
                        throw new RuntimeException('Completed production run piece quantities exceed approved output.');
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('production_piece_approvals')->exists()) {
            throw new RuntimeException('Piece-pay approvals exist; rollback would discard payroll evidence.');
        }

        Schema::dropIfExists('production_piece_approvals');
    }
};
