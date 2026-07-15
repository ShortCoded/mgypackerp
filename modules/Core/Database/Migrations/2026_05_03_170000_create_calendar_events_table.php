<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('calendar_events')) {
            Schema::create('calendar_events', function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_uuid')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('title');
                $table->text('description')->nullable();
                $table->timestamp('starts_at')->index();
                $table->timestamp('ends_at')->nullable()->index();
                $table->boolean('all_day')->default(false)->index();
                $table->string('status', 20)->default('pending')->index();
                $table->string('color', 30)->nullable();
                $table->string('location')->nullable();
                $table->string('meeting_url', 2048)->nullable();
                $table->timestamp('reminder_at')->nullable()->index();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('restored_at')->nullable();
                $table->nullableTimestamps();
                $table->softDeletes()->index();
                $table->index(['user_id', 'starts_at']);
                $table->index(['user_id', 'status']);
                $table->index(['user_id', 'deleted_at']);
            });

            return;
        }

        Schema::table('calendar_events', function (Blueprint $table): void {
            $this->addColumnIfMissing('public_uuid', fn () => $table->uuid('public_uuid')->nullable()->unique());
            $this->addColumnIfMissing('user_id', fn () => $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete());
            $this->addColumnIfMissing('title', fn () => $table->string('title')->nullable());
            $this->addColumnIfMissing('description', fn () => $table->text('description')->nullable());
            $this->addColumnIfMissing('starts_at', fn () => $table->timestamp('starts_at')->nullable()->index());
            $this->addColumnIfMissing('ends_at', fn () => $table->timestamp('ends_at')->nullable()->index());
            $this->addColumnIfMissing('all_day', fn () => $table->boolean('all_day')->default(false)->index());
            $this->addColumnIfMissing('status', fn () => $table->string('status', 20)->default('pending')->index());
            $this->addColumnIfMissing('color', fn () => $table->string('color', 30)->nullable());
            $this->addColumnIfMissing('location', fn () => $table->string('location')->nullable());
            $this->addColumnIfMissing('meeting_url', fn () => $table->string('meeting_url', 2048)->nullable()->after('location'));
            $this->addColumnIfMissing('reminder_at', fn () => $table->timestamp('reminder_at')->nullable()->index());
            $this->addColumnIfMissing('created_by', fn () => $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('updated_by', fn () => $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('deleted_by', fn () => $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('restored_by', fn () => $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete());
            $this->addColumnIfMissing('restored_at', fn () => $table->timestamp('restored_at')->nullable());

            if (! Schema::hasColumn('calendar_events', 'created_at')) {
                $table->timestamp('created_at')->nullable();
            }

            if (! Schema::hasColumn('calendar_events', 'updated_at')) {
                $table->timestamp('updated_at')->nullable();
            }

            if (! Schema::hasColumn('calendar_events', 'deleted_at')) {
                $table->softDeletes()->index();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('calendar_events');
    }

    private function addColumnIfMissing(string $column, callable $definition): void
    {
        if (! Schema::hasColumn('calendar_events', $column)) {
            $definition();
        }
    }
};
