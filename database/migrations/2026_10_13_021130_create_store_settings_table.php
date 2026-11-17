<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('store_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('store_name', 160);
            $table->string('address')->nullable();
            $table->string('whatsapp', 30)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('printer_paper_size', 10);
            $table->string('receipt_header')->nullable();
            $table->string('receipt_footer')->nullable();
            $table->boolean('receipt_show_logo')->default(true);
            $table->boolean('receipt_auto_print')->default(false);
            $table->json('payment_methods');
            $table->string('payment_information')->nullable();
            $table->boolean('wholesale_enabled')->default(true);
            $table->boolean('promo_enabled')->default(true);
            $table->unsignedInteger('default_minimum_stock')->default(10);
            $table->json('units');
            $table->timestamps();
        });

        DB::table('store_settings')->insert([
            'id' => 1,
            'store_name' => 'Spot Print',
            'printer_paper_size' => '58mm',
            'receipt_footer' => 'Terima kasih telah berbelanja',
            'receipt_show_logo' => true,
            'receipt_auto_print' => false,
            'payment_methods' => json_encode(['cash', 'transfer', 'qris'], JSON_THROW_ON_ERROR),
            'wholesale_enabled' => true,
            'promo_enabled' => true,
            'default_minimum_stock' => 10,
            'units' => json_encode(['pcs', 'lembar', 'rim', 'box', 'pack', 'lusin'], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
