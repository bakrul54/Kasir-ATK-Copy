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
        Schema::table('cash_entries', function (Blueprint $table): void {
            $table->string('account', 20)->default('laci');
            $table->string('to_account', 20)->nullable();
        });

        Schema::table('purchases', function (Blueprint $table): void {
            $table->string('payment_account', 20)->default('laci');
        });

        DB::table('cash_entries')
            ->whereNull('sale_id')
            ->where('type', 'keluar')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(category) LIKE ?', ['%qris%'])
                    ->orWhereRaw("LOWER(COALESCE(description, '')) LIKE ?", ['%qris%']);
            })
            ->update(['account' => 'non_tunai']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('payment_account');
        });

        Schema::table('cash_entries', function (Blueprint $table): void {
            $table->dropColumn(['account', 'to_account']);
        });
    }
};
