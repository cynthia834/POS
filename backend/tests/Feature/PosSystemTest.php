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

    /**
     * Test customer creation and listing.
     */
    public function test_customer_creation_and_listing(): void
    {
        $payload = [
            'name' => 'John Doe',
            'phone' => '+254700000001',
            'email' => 'john.doe@example.com',
            'points_balance' => 1500,
        ];

        $response = $this->postJson('/api/v1/customers', $payload);
        $response->assertStatus(201);
        $response->assertJsonFragment([
            'name' => 'John Doe',
            'phone' => '+254700000001',
            'email' => 'john.doe@example.com',
            'points_balance' => 1500,
            'tier' => 'gold',
        ]);

        $responseList = $this->getJson('/api/v1/customers');
        $responseList->assertStatus(200);
        $responseList->assertJsonFragment([
            'name' => 'John Doe',
        ]);
    }

    /**
     * Test cart calculation with member tier discounts.
     */
    public function test_cart_calculation_with_member_tier_discount(): void
    {
        // 1. Create a customer with gold tier points
        $customer = \App\Models\Customer::create([
            'name' => 'Gold Customer',
            'phone' => '+254700000002',
            'points_balance' => 1200, // Gold tier
        ]);

        // 2. Create active member discount rule for gold tier
        \App\Models\DiscountRule::create([
            'name' => 'Gold Member Promo',
            'type' => 'member',
            'is_active' => true,
            'conditions' => [
                'tier' => 'gold',
                'discount_percentage' => 10,
            ]
        ]);

        $payload = [
            'customer_id' => $customer->id,
            'cart' => [
                'items' => [
                    [
                        'id' => 999, // Custom / temporary ID
                        'qty' => 1,
                        'price' => 1000.00,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/checkout/calculate', $payload);
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'subtotal' => 1000.00,
            'discount' => 100.00, // 10% of 1000
            'total' => 900.00,
        ]);
    }

    /**
     * Test that checkouts award points to customer profiles.
     */
    public function test_checkout_awards_loyalty_points(): void
    {
        // 1. Create customer
        $customer = \App\Models\Customer::create([
            'name' => 'Loyal Member',
            'phone' => '+254700000003',
            'points_balance' => 100,
        ]);

        // 2. Setup product & batches to allow FIFO deduction
        $product = Product::create([
            'name' => 'Points Product',
            'barcode' => '55554444',
            'price' => 500.00,
        ]);
        $variant = StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'PP-PC',
        ]);
        Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
        ]);

        $payload = [
            'payment_method' => 'cash',
            'customer_id' => $customer->id,
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 2,
                        'price' => 500.00,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/checkout', $payload);
        $response->assertStatus(200);

        // Verify points were awarded. Order observer awards points when status changes to Paid/completed.
        // During CashCheckout, status changes to Paid immediately.
        $this->assertEquals(110, $customer->fresh()->points_balance); // 100 + 10 points (2 * 500 = 1000 KES, 1 point per 100 KES spent = 10 points)
    }

    /**
     * Test that markdown discount applies correctly according to rules when active.
     */
    public function test_markdown_discount_applies_correctly_when_active(): void
    {
        // 1. Create a product and a batch expiring in 2 days
        $product = Product::create([
            'name' => 'Near Expiry Item',
            'barcode' => '11112222',
            'price' => 100.00,
        ]);
        $variant = StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'NEI-PC',
        ]);
        Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addDays(2)->toDateString(),
        ]);

        // 2. Create the markdown rule: 2 days -> 70% off, 7 days -> 50% off
        \App\Models\DiscountRule::create([
            'name' => 'Auto Expiry Markdown',
            'type' => 'markdown',
            'is_active' => true,
            'conditions' => [
                'rules' => [
                    ['days_left' => 2, 'discount_percentage' => 70],
                    ['days_left' => 7, 'discount_percentage' => 50],
                ]
            ]
        ]);

        $payload = [
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 1,
                        'price' => 100.00,
                    ]
                ]
            ]
        ];

        // 3. Verify that 70% discount is applied (since 2 days left <= 2)
        $response = $this->postJson('/api/v1/checkout/calculate', $payload);
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'subtotal' => 100.00,
            'discount' => 70.00, // 70% of 100
            'total' => 30.00,
        ]);
    }

    /**
     * Test that markdown discount does not apply when the markdown rule is disabled.
     */
    public function test_markdown_discount_does_not_apply_when_inactive(): void
    {
        // 1. Create a product and a batch expiring in 2 days
        $product = Product::create([
            'name' => 'Near Expiry Item 2',
            'barcode' => '33334444',
            'price' => 100.00,
        ]);
        $variant = StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'NEI2-PC',
        ]);
        Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addDays(2)->toDateString(),
        ]);

        // 2. Create the markdown rule but set is_active to false
        \App\Models\DiscountRule::create([
            'name' => 'Auto Expiry Markdown Inactive',
            'type' => 'markdown',
            'is_active' => false,
            'conditions' => [
                'rules' => [
                    ['days_left' => 2, 'discount_percentage' => 70],
                ]
            ]
        ]);

        $payload = [
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 1,
                        'price' => 100.00,
                    ]
                ]
            ]
        ];

        // 3. Verify that 0 discount is applied
        $response = $this->postJson('/api/v1/checkout/calculate', $payload);
        $response->assertStatus(200);
        $response->assertJsonFragment([
            'subtotal' => 100.00,
            'discount' => 0.00,
            'total' => 100.00,
        ]);
    }
}

