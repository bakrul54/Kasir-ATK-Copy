<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('product_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('sku')->nullable()->unique();
            $table->string('unit')->default('pcs');
            $table->boolean('tracks_stock')->default(true);
            $table->unsignedBigInteger('sale_price');
            $table->unsignedBigInteger('cost_price')->default(0);
            $table->unsignedInteger('stock_qty')->default(0);
            $table->unsignedInteger('minimum_stock')->default(0);
            $table->unsignedBigInteger('wholesale_price')->nullable();
            $table->unsignedInteger('wholesale_min_qty')->nullable();
            $table->unsignedBigInteger('promo_price')->nullable();
            $table->string('photo_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'tracks_stock', 'stock_qty']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('product_categories');
    }
};
