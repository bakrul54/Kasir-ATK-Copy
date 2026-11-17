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
        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name', 160)->nullable();
            $table->unsignedBigInteger('subtotal');
            $table->string('discount_type', 20);
            $table->unsignedBigInteger('discount_value');
            $table->unsignedBigInteger('discount_amount');
            $table->unsignedBigInteger('total');
            $table->string('payment_method', 20);
            $table->unsignedBigInteger('amount_received');
            $table->unsignedBigInteger('change_amount')->default(0);
            $table->timestamps();

            $table->index(['created_at', 'payment_method']);
            $table->index(['customer_name', 'created_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name', 160);
            $table->string('sku', 80)->nullable();
            $table->string('unit', 30);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('unit_price');
            $table->unsignedBigInteger('cost_price');
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();

            $table->index(['sale_id', 'product_id']);
            $table->index(['product_id', 'created_at']);
        });

        Schema::table('product_stock_movements', function (Blueprint $table) {
            $table->foreignId('sale_id')->nullable()->after('purchase_id')->constrained()->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sale_id');
        });

        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
    }
};
