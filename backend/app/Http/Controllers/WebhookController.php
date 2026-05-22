<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Models\Order;

class WebhookController extends Controller
{
    /**
     * Handle incoming M-Pesa STK Push Callback from Safaricom.
     */
    public function handleMpesaWebhook(Request $request)
    {
        Log::info('M-Pesa Webhook Received', ['payload' => $request->all()]);

        $stkCallback = $request->input('Body.stkCallback');
        if (!$stkCallback) {
            Log::warning('M-Pesa Webhook: Missing stkCallback in payload');
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Missing stkCallback'
            ], 400);
        }

        $checkoutRequestId = $stkCallback['CheckoutRequestID'] ?? null;
        $resultCode = $stkCallback['ResultCode'] ?? null;
        $resultDesc = $stkCallback['ResultDesc'] ?? 'No description';

        if (!$checkoutRequestId) {
            Log::warning('M-Pesa Webhook: Missing CheckoutRequestID in callback');
            return response()->json([
                'ResultCode' => 1,
                'ResultDesc' => 'Missing CheckoutRequestID'
            ], 400);
        }

        // Find the payment by checkout_request_id
        $payment = Payment::where('checkout_request_id', $checkoutRequestId)->first();

        if (!$payment) {
            Log::warning('M-Pesa Webhook: Payment record not found for CheckoutRequestID', [
                'CheckoutRequestID' => $checkoutRequestId
            ]);
            return response()->json([
                'ResultCode' => 0,
                'ResultDesc' => 'Accepted' // Return accepted even if not found to stop retries, but log warning
            ]);
        }

        if ($resultCode == 0) {
            // Success
            $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
            $receiptNumber = null;
            
            foreach ($metadata as $item) {
                if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                    $receiptNumber = $item['Value'] ?? null;
                    break;
                }
            }

            $payment->update([
                'status' => 'completed',
                'transaction_id' => $receiptNumber,
            ]);

            // Update polymorphic parent (Order) to 'Paid'
            $order = $payment->payable;
            if ($order instanceof Order) {
                $order->update(['status' => 'Paid']);
                Log::info('M-Pesa Webhook: Payment completed successfully', [
                    'payment_id' => $payment->id,
                    'order_id' => $order->id,
                    'receipt' => $receiptNumber
                ]);
            }
        } else {
            // Failed (e.g. user cancelled, insufficient funds, etc.)
            $payment->update([
                'status' => 'failed',
            ]);
            Log::info('M-Pesa Webhook: Payment failed', [
                'payment_id' => $payment->id,
                'result_code' => $resultCode,
                'result_desc' => $resultDesc
            ]);
        }

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
    }
}
