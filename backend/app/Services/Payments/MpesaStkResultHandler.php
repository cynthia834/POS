<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Support\Facades\Log;

class MpesaStkResultHandler
{
    /** Safaricom result codes that mean the STK attempt is finished and failed. */
    protected const FAILURE_CODES = [
        1032, // cancelled by user
        1037, // timeout — no PIN entered
        2001, // wrong PIN
        1,    // insufficient balance
        17,   // rule limited
        26,   // system busy
    ];

    /**
     * Apply STK callback/query result to payment + order.
     *
     * @return string pending|completed|failed
     */
    public function apply(Payment $payment, mixed $resultCode, string $resultDesc = '', ?string $receiptNumber = null): string
    {
        if ($payment->status !== 'pending') {
            return $payment->status;
        }

        $code = is_numeric($resultCode) ? (int) $resultCode : $resultCode;

        if ($code === 0 || $code === '0') {
            $payment->update([
                'status' => 'completed',
                'transaction_id' => $receiptNumber ?? $payment->transaction_id,
            ]);

            $order = $payment->payable;
            if ($order instanceof Order) {
                $order->update(['status' => 'Paid']);
            }

            Log::info('M-Pesa STK: Payment completed', [
                'payment_id' => $payment->id,
                'order_id' => $payment->payable_id,
                'receipt' => $receiptNumber,
            ]);

            return 'completed';
        }

        if (in_array((int) $code, self::FAILURE_CODES, true)) {
            $payment->update(['status' => 'failed']);

            Log::info('M-Pesa STK: Payment failed', [
                'payment_id' => $payment->id,
                'result_code' => $code,
                'result_desc' => $resultDesc,
            ]);

            return 'failed';
        }

        Log::debug('M-Pesa STK: Still pending', [
            'payment_id' => $payment->id,
            'result_code' => $code,
            'result_desc' => $resultDesc,
        ]);

        return 'pending';
    }
}
