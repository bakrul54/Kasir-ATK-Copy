<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('product_categories')
            ->where('name', 'Cetak dan Print')
            ->update(['name' => 'Cetak dan Jasa']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('product_categories')
            ->where('name', 'Cetak dan Jasa')
            ->update(['name' => 'Cetak dan Print']);
    }
};
