<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasColumn('sales', 'submission_token')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->uuid('submission_token')->nullable();
            });
        }

        if (! Schema::hasIndex('sales', ['submission_token'], 'unique')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->unique('submission_token');
            });
        }

        if (! Schema::hasColumn('sales', 'canceled_by')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->foreignId('canceled_by')->nullable()->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('sales', 'canceled_at')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->timestamp('canceled_at')->nullable();
            });
        }

        if (! Schema::hasColumn('sales', 'cancellation_reason')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->string('cancellation_reason', 500)->nullable();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasColumn('sales', 'canceled_by')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('canceled_by');
            });
        }

        if (Schema::hasIndex('sales', ['submission_token'], 'unique')) {
            Schema::table('sales', function (Blueprint $table): void {
                $table->dropUnique(['submission_token']);
            });
        }

        $columns = array_filter(
            ['submission_token', 'canceled_at', 'cancellation_reason'],
            fn (string $column): bool => Schema::hasColumn('sales', $column),
        );

        if ($columns !== []) {
            Schema::table('sales', function (Blueprint $table) use ($columns): void {
                $table->dropColumn($columns);
            });
        }
    }
};
