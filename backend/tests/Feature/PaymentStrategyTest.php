<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Payments\CashGateway;
use App\Services\Payments\MPesaGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentStrategyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup configuration for M-Pesa testing
        config([
            'services.mpesa.consumer_key' => 'mock_key',
            'services.mpesa.consumer_secret' => 'mock_secret',
            'services.mpesa.shortcode' => '174379',
            'services.mpesa.passkey' => 'mock_passkey',
            'services.mpesa.callback_url' => 'http://localhost/api/v1/webhooks/mpesa',
            'services.mpesa.env' => 'sandbox',
        ]);
    }

    /** @test */
    public function cash_gateway_charges_instantly_and_marks_order_as_paid()
    {
        $order = Order::create([
            'status' => 'Pending',
            'total_amount' => 100.00,
        ]);

        $gateway = new CashGateway();
        $payment = $gateway->charge($order, 100.00);

        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals('cash', $payment->payment_method);
        $this->assertEquals(100.00, $payment->amount);

        $order->refresh();
        $this->assertEquals('Paid', $order->status);
    }

    /** @test */
    public function mpesa_gateway_initiates_stk_push_and_creates_pending_payment()
    {
        // Mock Safaricom APIs
        Http::fake([
            '*/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_access_token',
                'expires_in' => '3599'
            ], 200),
            '*/mpesa/stkpush/v1/processrequest*' => Http::response([
                'MerchantRequestID' => '12345-67890-1',
                'CheckoutRequestID' => 'ws_CO_21052026_1',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing'
            ], 200),
        ]);

        $order = Order::create([
            'status' => 'Pending',
            'total_amount' => 150.00,
        ]);

        $gateway = new MPesaGateway();
        $response = $gateway->charge($order, 150.00, [
            'phone_number' => '0712345678'
        ]);

        $this->assertArrayHasKey('payment', $response);
        $this->assertArrayHasKey('mpesa_response', $response);

        $payment = $response['payment'];
        $this->assertInstanceOf(Payment::class, $payment);
        $this->assertEquals('pending', $payment->status);
        $this->assertEquals('mpesa', $payment->payment_method);
        $this->assertEquals('ws_CO_21052026_1', $payment->checkout_request_id);
        $this->assertEquals('254712345678', $payment->phone_number);

        $order->refresh();
        $this->assertEquals('Pending', $order->status); // Still pending until webhook fires
    }

    /** @test */
    public function mpesa_webhook_completes_payment_and_marks_order_as_paid_on_success()
    {
        $order = Order::create([
            'status' => 'Pending',
            'total_amount' => 200.00,
        ]);

        $payment = $order->payments()->create([
            'amount' => 200.00,
            'status' => 'pending',
            'payment_method' => 'mpesa',
            'checkout_request_id' => 'ws_CO_21052026_2',
            'phone_number' => '254712345678',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '12345-67890-1',
                    'CheckoutRequestID' => 'ws_CO_21052026_2',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 200.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61SV'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ]
                    ]
                ]
            ]
        ];

        // Disable CSRF is automatically configured in bootstrap/app.php, but POST test route is called
        $response = $this->postJson('/api/v1/webhooks/mpesa', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted'
            ]);

        $payment->refresh();
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals('NLJ7RT61SV', $payment->transaction_id);

        $order->refresh();
        $this->assertEquals('Paid', $order->status);
    }

    /** @test */
    public function mpesa_webhook_fails_payment_on_error()
    {
        $order = Order::create([
            'status' => 'Pending',
            'total_amount' => 200.00,
        ]);

        $payment = $order->payments()->create([
            'amount' => 200.00,
            'status' => 'pending',
            'payment_method' => 'mpesa',
            'checkout_request_id' => 'ws_CO_21052026_3',
            'phone_number' => '254712345678',
        ]);

        $payload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '12345-67890-1',
                    'CheckoutRequestID' => 'ws_CO_21052026_3',
                    'ResultCode' => 1032,
                    'ResultDesc' => 'Request cancelled by user.'
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/webhooks/mpesa', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted'
            ]);

        $payment->refresh();
        $this->assertEquals('failed', $payment->status);

        $order->refresh();
        $this->assertEquals('Pending', $order->status); // Should remain Pending
    }

    /** @test */
    public function checkout_via_cash_creates_order_and_payment_and_deducts_stock()
    {
        $product = \App\Models\Product::create([
            'name' => 'Cash Test Product',
            'barcode' => '88887777',
            'price' => 50.00,
        ]);
        $variant = \App\Models\StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'CTP-PC',
        ]);
        $batch = \App\Models\Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
        ]);

        $payload = [
            'payment_method' => 'cash',
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 3,
                        'price' => 50.00,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/checkout', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'Paid'
            ]);

        $orderId = $response->json('order_id');
        $paymentId = $response->json('payment.id');

        $order = Order::find($orderId);
        $this->assertEquals('Paid', $order->status);
        $this->assertEquals(150.00, $order->total_amount);

        $payment = Payment::find($paymentId);
        $this->assertEquals('completed', $payment->status);
        $this->assertEquals('cash', $payment->payment_method);

        // Verify stock is deducted
        $this->assertEquals(7, $batch->fresh()->quantity);
    }

    /** @test */
    public function checkout_via_mpesa_with_simulation_flag_returns_mock_payment_instantly()
    {
        $product = \App\Models\Product::create([
            'name' => 'MPesa Sim Product',
            'barcode' => '99998888',
            'price' => 75.00,
        ]);
        $variant = \App\Models\StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'MSP-PC',
        ]);
        $batch = \App\Models\Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
        ]);

        $payload = [
            'payment_method' => 'mpesa',
            'phone_number' => '0712345678',
            'simulate' => true,
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 2,
                        'price' => 75.00,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/checkout', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'Pending'
            ]);

        $payment = Payment::find($response->json('payment.id'));
        $this->assertEquals('pending', $payment->status);
        $this->assertStringStartsWith('ws_CO_', $payment->checkout_request_id);
    }

    /** @test */
    public function checkout_via_mpesa_initiates_payment_and_status_checks()
    {
        // Mock Safaricom APIs
        Http::fake([
            '*/oauth/v1/generate*' => Http::response([
                'access_token' => 'mock_access_token',
                'expires_in' => '3599'
            ], 200),
            '*/mpesa/stkpush/v1/processrequest*' => Http::response([
                'MerchantRequestID' => '12345-67890-1',
                'CheckoutRequestID' => 'ws_CO_21052026_9',
                'ResponseCode' => '0',
                'ResponseDescription' => 'Success. Request accepted for processing',
                'CustomerMessage' => 'Success. Request accepted for processing'
            ], 200),
        ]);

        $product = \App\Models\Product::create([
            'name' => 'MPesa Test Product',
            'barcode' => '77778888',
            'price' => 100.00,
        ]);
        $variant = \App\Models\StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => 'MTP-PC',
        ]);
        $batch = \App\Models\Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => 10,
            'expiration_date' => now()->addYear()->toDateString(),
            'created_at' => now(),
        ]);

        $payload = [
            'payment_method' => 'mpesa',
            'phone_number' => '0712345678',
            'cart' => [
                'items' => [
                    [
                        'id' => $product->id,
                        'qty' => 2,
                        'price' => 100.00,
                    ]
                ]
            ]
        ];

        $response = $this->postJson('/api/v1/checkout', $payload);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'status' => 'Pending'
            ]);

        $orderId = $response->json('order_id');
        $paymentId = $response->json('payment.id');

        $order = Order::find($orderId);
        $this->assertEquals('Pending', $order->status);

        // Verify stock is NOT deducted yet
        $this->assertEquals(10, $batch->fresh()->quantity);

        // Check payment status endpoint
        $statusResponse = $this->getJson("/api/v1/payments/{$paymentId}/status");
        $statusResponse->assertStatus(200)
            ->assertJson([
                'payment_id' => $paymentId,
                'status' => 'pending',
                'order_status' => 'Pending'
            ]);

        // Mock webhook callback
        $webhookPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => '12345-67890-1',
                    'CheckoutRequestID' => 'ws_CO_21052026_9',
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => 200.00],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => 'NLJ7RT61S9'],
                            ['Name' => 'PhoneNumber', 'Value' => 254712345678],
                        ]
                    ]
                ]
            ]
        ];

        $webhookResponse = $this->postJson('/api/v1/webhooks/mpesa', $webhookPayload);
        $webhookResponse->assertStatus(200);

        // Check payment status endpoint again
        $statusResponse2 = $this->getJson("/api/v1/payments/{$paymentId}/status");
        $statusResponse2->assertStatus(200)
            ->assertJson([
                'payment_id' => $paymentId,
                'status' => 'completed',
                'order_status' => 'Paid'
            ]);

        // Verify stock is now deducted
        $this->assertEquals(8, $batch->fresh()->quantity);
    }
}
