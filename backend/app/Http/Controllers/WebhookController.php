<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Models\Payment;
use App\Services\Payments\MpesaStkResultHandler;

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

        $metadata = $stkCallback['CallbackMetadata']['Item'] ?? [];
        $receiptNumber = null;

        foreach ($metadata as $item) {
            if (($item['Name'] ?? '') === 'MpesaReceiptNumber') {
                $receiptNumber = $item['Value'] ?? null;
                break;
            }
        }

        app(MpesaStkResultHandler::class)->apply($payment, $resultCode, $resultDesc, $receiptNumber);

        return response()->json([
            'ResultCode' => 0,
            'ResultDesc' => 'Accepted'
        ]);
    }
}
