<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use App\Models\Cart;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Payment;
use App\Discounts\ApplyBogoDiscounts;
use App\Discounts\ApplyHappyHourRates;
use App\Discounts\ApplyMemberDiscounts;
use App\Discounts\ApplySeasonalDiscounts;
use App\Discounts\ApplyMarkdownDiscounts;

class CheckoutController extends Controller {
    public function calculate(Request $request) {
        $cartData = $request->input('cart', []);
        $items = $cartData['items'] ?? [];
        
        $customer = null;
        $customerId = $cartData['customer_id'] ?? $request->input('customer_id');
        if ($customerId) {
            $customer = Customer::find($customerId);
        }

        $mappedItems = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 0,
                'price' => $item['price'],
            ];
        }, $items);

        $cart = new Cart($mappedItems, $customer);
        
        $cart = app(Pipeline::class)
            ->send($cart)
            ->through([
                ApplyBogoDiscounts::class,
                ApplyHappyHourRates::class,
                ApplyMemberDiscounts::class,
                ApplySeasonalDiscounts::class,
                ApplyMarkdownDiscounts::class,
            ])
            ->thenReturn();
            
        return response()->json($cart);
    }

    public function processCheckout(Request $request) {
        $request->validate([
            'payment_method' => 'required|string|in:cash,mpesa,card',
            'phone_number' => 'required_if:payment_method,mpesa|string|nullable',
            'cart.items' => 'required|array',
            'cart.items.*.id' => 'required',
            'cart.items.*.price' => 'required|numeric',
        ]);

        $paymentMethod = $request->input('payment_method');
        $phoneNumber = $request->input('phone_number');
        $cartData = $request->input('cart', []);
        $items = $cartData['items'] ?? [];

        // Map items to match what Cart expects (e.g. quantity key)
        $mappedItems = array_map(function ($item) {
            return [
                'id' => $item['id'],
                'quantity' => $item['qty'] ?? $item['quantity'] ?? 0,
                'price' => $item['price'],
            ];
        }, $items);

        $customer = null;
        $customerId = $cartData['customer_id'] ?? $request->input('customer_id');
        if ($customerId) {
            $customer = Customer::find($customerId);
        }

        // 1. Calculate discount and final amount using the pipeline
        $cart = new Cart($mappedItems, $customer);
        $cart = app(Pipeline::class)
            ->send($cart)
            ->through([
                ApplyBogoDiscounts::class,
                ApplyHappyHourRates::class,
                ApplyMemberDiscounts::class,
                ApplySeasonalDiscounts::class,
                ApplyMarkdownDiscounts::class,
            ])
            ->thenReturn();

        // 2. Create the Order (status = Pending)
        $order = Order::create([
            'status' => 'Pending',
            'total_amount' => $cart->total,
            'customer_id' => $customer ? $customer->id : null,
        ]);

        // 3. Create the OrderLineItems
        foreach ($mappedItems as $item) {
            // Dynamically register the product if it was created on the frontend in-memory but not in MySQL
            $product = \App\Models\Product::find($item['id']);
            if (!$product) {
                $product = new \App\Models\Product();
                $product->id = $item['id'];
                $product->name = 'Custom Product #' . $item['id'];
                $product->barcode = 'custom-' . $item['id'] . '-' . time();
                $product->price = $item['price'];
                $product->save();

                // Create stock variant for FIFO deduction
                $variant = \App\Models\StockVariant::create([
                    'product_id' => $product->id,
                    'unit' => 'Piece',
                    'conversion_rate' => 1.00,
                    'sku' => 'CUSTOM-' . $product->id,
                ]);

                // Create a batch with sufficient stock so FIFO deduction succeeds
                \App\Models\Batch::create([
                    'stock_variant_id' => $variant->id,
                    'quantity' => 100,
                    'expiration_date' => now()->addYear()->toDateString(),
                ]);
            }

            $order->items()->create([
                'product_id' => $item['id'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ]);
        }

        // 4. Charge using the corresponding payment gateway
        try {
            if ($paymentMethod === 'cash') {
                $gateway = new \App\Services\Payments\CashGateway();
                $payment = $gateway->charge($order, $cart->total);
                
                return response()->json([
                    'success' => true,
                    'order_id' => $order->id,
                    'payment' => $payment,
                    'status' => 'Paid'
                ]);
            } else if ($paymentMethod === 'card') {
                $gateway = new \App\Services\Payments\CardGateway();
                $payment = $gateway->charge($order, $cart->total);
                
                return response()->json([
                    'success' => true,
                    'order_id' => $order->id,
                    'payment' => $payment,
                    'status' => 'Paid'
                ]);
            } else if ($paymentMethod === 'mpesa') {
                $gateway = new \App\Services\Payments\MPesaGateway();
                $result = $gateway->charge($order, $cart->total, [
                    'phone_number' => $phoneNumber,
                    'simulate' => $request->boolean('simulate')
                ]);
                
                return response()->json([
                    'success' => true,
                    'order_id' => $order->id,
                    'payment' => $result['payment'],
                    'mpesa_response' => $result['mpesa_response'],
                    'status' => 'Pending'
                ]);
            }
        } catch (\Exception $e) {
            // Rollback order creation on failure
            $order->delete();
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 400);
        }

        return response()->json(['success' => false, 'message' => 'Invalid payment method'], 400);
    }

    public function checkStatus(Payment $payment) {
        if ($payment->payment_method === 'mpesa' && $payment->status === 'pending' && $payment->checkout_request_id) {
            $gateway = new \App\Services\Payments\MPesaGateway();
            $gateway->queryAndSyncPayment($payment);
            $payment->refresh();
        }

        return response()->json([
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'transaction_id' => $payment->transaction_id,
            'checkout_request_id' => $payment->checkout_request_id,
            'order_id' => $payment->payable_id,
            'order_status' => $payment->payable ? $payment->payable->status : null,
        ]);
    }

    /**
     * Abort a pending M-Pesa STK push (customer did not enter PIN or cashier cancelled).
     */
    public function abortMpesaPayment(Request $request, Payment $payment)
    {
        if ($payment->payment_method !== 'mpesa' || $payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending M-Pesa payments can be aborted.',
            ], 400);
        }

        $payment->update(['status' => 'failed']);

        $order = $payment->payable;
        if ($order instanceof Order) {
            $order->update(['status' => 'Cancelled']);
        }

        return response()->json([
            'success' => true,
            'payment_id' => $payment->id,
            'status' => 'failed',
            'order_id' => $payment->payable_id,
            'order_status' => $order ? $order->status : null,
            'message' => $request->input('reason', 'STK push aborted'),
        ]);
    }

    /**
     * Complete a pending M-Pesa payment (automated tests only).
     */
    public function simulateMpesaComplete(Request $request, Payment $payment)
    {
        if (!app()->environment('testing')) {
            return response()->json([
                'success' => false,
                'message' => 'Simulated completion is only available in the test environment.',
            ], 403);
        }

        if ($payment->payment_method !== 'mpesa' || $payment->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending M-Pesa payments can be simulated.',
            ], 400);
        }

        if (!$payment->checkout_request_id) {
            return response()->json([
                'success' => false,
                'message' => 'Payment is missing a checkout request ID.',
            ], 400);
        }

        $request->validate([
            'pin' => 'nullable|string|size:4',
        ]);

        $receipt = 'SIM' . strtoupper(substr(md5($payment->id . microtime()), 0, 8));

        $callbackPayload = [
            'Body' => [
                'stkCallback' => [
                    'MerchantRequestID' => 'mock_merchant_id',
                    'CheckoutRequestID' => $payment->checkout_request_id,
                    'ResultCode' => 0,
                    'ResultDesc' => 'The service request is processed successfully.',
                    'CallbackMetadata' => [
                        'Item' => [
                            ['Name' => 'Amount', 'Value' => (float) $payment->amount],
                            ['Name' => 'MpesaReceiptNumber', 'Value' => $receipt],
                            ['Name' => 'PhoneNumber', 'Value' => (int) ($payment->phone_number ?? '254700000000')],
                        ],
                    ],
                ],
            ],
        ];

        app(WebhookController::class)->handleMpesaWebhook(new Request($callbackPayload));

        $payment->refresh();

        return response()->json([
            'success' => true,
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'transaction_id' => $payment->transaction_id,
            'receipt' => $receipt,
            'order_id' => $payment->payable_id,
            'order_status' => $payment->payable ? $payment->payable->status : null,
        ]);
    }

    public function listOrders(Request $request) {
        $orders = Order::with(['items.product', 'payments'])
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json($orders);
    }
}
