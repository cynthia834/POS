<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('products')) {
            Schema::create('products', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('barcode')->unique();
                $table->decimal('price', 10, 2);
                $table->string('category')->default('Groceries');
                $table->string('image_url')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('stock_variants')) {
            Schema::create('stock_variants', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('product_id');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
                $table->string('unit');
                $table->decimal('conversion_rate', 10, 2);
                $table->string('sku')->unique();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('batches')) {
            Schema::create('batches', function (Blueprint $table) {
                $table->id();
                $table->foreignId('stock_variant_id')->constrained()->onDelete('cascade');
                $table->integer('quantity');
                $table->date('expiration_date')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('customers')) {
            Schema::create('customers', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('phone')->unique();
                $table->integer('points_balance')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('point_transactions')) {
            Schema::create('point_transactions', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('customer_id');
                $table->foreign('customer_id')->references('id')->on('customers')->onDelete('cascade');
                $table->integer('points');
                $table->string('type'); // earn, redeem
                $table->string('description')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('discount_rules')) {
            Schema::create('discount_rules', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('type'); // bogo, happy_hour, etc.
                $table->json('conditions');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('orders')) {
            Schema::create('orders', function (Blueprint $table) {
                $table->id();
                $table->string('status')->default('Pending'); // Pending, Paid, Cancelled
                $table->decimal('total_amount', 10, 2)->default(0.00);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('order_line_items')) {
            Schema::create('order_line_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained()->onDelete('cascade');
                $table->unsignedBigInteger('product_id');
                $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
                $table->integer('quantity');
                $table->decimal('price', 10, 2);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('payments')) {
            Schema::create('payments', function (Blueprint $table) {
                $table->id();
                $table->morphs('payable'); // payable_type, payable_id
                $table->decimal('amount', 10, 2);
                $table->string('status')->default('pending'); // pending, completed, failed
                $table->string('checkout_request_id')->nullable()->index();
                $table->string('transaction_id')->nullable();
                $table->string('phone_number')->nullable();
                $table->string('payment_method'); // cash, mpesa
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_line_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('discount_rules');
        Schema::dropIfExists('point_transactions');
        Schema::dropIfExists('customers');
        Schema::dropIfExists('batches');
        Schema::dropIfExists('stock_variants');
        Schema::dropIfExists('products');
    }
};
