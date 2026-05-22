<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;

class CashGateway implements PaymentGatewayInterface
{
    /**
     * Instantly charge order with cash, update payment to completed, order to Paid.
     */
    public function charge(Order $order, float $amount, array $data = [])
    {
        $payment = $order->payments()->create([
            'amount' => $amount,
            'status' => 'completed',
            'payment_method' => 'cash',
        ]);

        $order->update(['status' => 'Paid']);

        return $payment;
    }
}
