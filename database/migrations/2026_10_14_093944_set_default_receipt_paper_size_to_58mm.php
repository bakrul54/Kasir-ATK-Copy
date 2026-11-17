<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('store_settings')->where('id', 1)->update([
            'printer_paper_size' => '58mm',
        ]);
    }

    public function down(): void
    {
        DB::table('store_settings')->where('id', 1)->update([
            'printer_paper_size' => '80mm',
        ]);
    }
};
