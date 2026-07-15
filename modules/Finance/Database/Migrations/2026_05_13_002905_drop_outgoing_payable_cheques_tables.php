<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('outgoing_payable_cheque_print_logs');
        Schema::dropIfExists('outgoing_payable_cheques');
    }

    public function down(): void
    {
        // Destructive feature removal; the dropped schema is intentionally not recreated.
    }
};
