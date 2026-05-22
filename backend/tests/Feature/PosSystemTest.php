<?php

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Order;
use App\Models\OrderLineItem;
use App\Models\Product;
use App\Models\StockVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PosSystemTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test product lookup caching behavior.
     */
    public function test_product_lookup_is_cached(): void
    {
        // 1. Create a product
        $product = Product::create([
            'name' => 'Cache Test Product',
            'barcode' => '99998888',
            'price' => 10.50,
        ]);

        // Clear the cache first to ensure a clean state
        Cache::forget('product_99998888');

        // 2. Perform the lookup request
        $response = $this->getJson('/api/v1/products/lookup/99998888');
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'name' => 'Cache Test Product',
            'barcode' => '99998888',
        ]);

        // 3. Confirm it was cached by deleting it from the database and querying again
        $product->delete();

        $response2 = $this->getJson('/api/v1/products/lookup/99998888');
        $response2->assertStatus(200);
        $response2->assertJsonFragment([
            'name' => 'Cache Test Product',
            'barcode' => '99998888',
        ]);

        // 4. Clear cache and confirm it is gone (returns 404)
        Cache::forget('product_99998888');
        $response3 = $this->getJson('/api/v1/products/lookup/99998888');
        $response3->assertStatus(404);
    }

    /**
     * Test stock deduction works FIFO style when a line item is created for a completed order.
     */
    public function test_stock_is_deducted_fifo_style_for_completed_orders(): void
    {
        // 1. Create product & stock variants
        $product = Product::create([
            'name' => 'Stock Test Product',
            'barcode' => '77776666',
            'price' => 5.00,
        ]);

        $variant = StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'STP-PC',
        ]);

        // 2. Create batches with different expiration dates or creation dates
        // Batch B (oldest expiration)
        $batchB = Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 15,
            'expiration_date' => '2026-05-15',
            'created_at' => now()->subDays(2),
        ]);

        // Batch A (middle expiration)
        $batchA = Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => '2026-06-01',
            'created_at' => now()->subDays(1),
        ]);

        // Batch C (newest expiration)
        $batchC = Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 20,
            'expiration_date' => '2026-06-15',
            'created_at' => now(),
        ]);

        // 3. Create a pending order
        $orderPending = Order::create([
            'status' => 'Pending',
            'total_amount' => 0.00,
        ]);

        // Create line item under pending order
        OrderLineItem::create([
            'order_id' => $orderPending->id,
            'product_id' => $product->id,
            'quantity' => 5,
            'price' => 5.00,
        ]);

        // Assert no stock is deducted from any batches because order status is Pending
        $this->assertEquals(15, $batchB->fresh()->quantity);
        $this->assertEquals(10, $batchA->fresh()->quantity);
        $this->assertEquals(20, $batchC->fresh()->quantity);

        // 4. Create a completed order
        $orderCompleted = Order::create([
            'status' => 'completed',
            'total_amount' => 0.00,
        ]);

        // Create line item under completed order (should deduct 20 units total)
        // This should consume Batch B (15 units) completely, and Batch A (5 units) partially.
        OrderLineItem::create([
            'order_id' => $orderCompleted->id,
            'product_id' => $product->id,
            'quantity' => 20,
            'price' => 5.00,
        ]);

        // Assert stock is deducted correctly
        $this->assertEquals(0, $batchB->fresh()->quantity);   // B (15) -> 0
        $this->assertEquals(5, $batchA->fresh()->quantity);   // A (10) -> 5 (deducted 5)
        $this->assertEquals(20, $batchC->fresh()->quantity);  // C (20) remains untouched
    }
}
