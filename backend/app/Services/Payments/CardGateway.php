<?php

namespace App\Services\Payments;

use App\Models\Order;
use App\Models\Payment;

class CardGateway implements PaymentGatewayInterface
{
    /**
     * Instantly charge order with card, update payment to completed, order to Paid.
     */
    public function charge(Order $order, float $amount, array $data = [])
    {
        $payment = $order->payments()->create([
            'amount' => $amount,
            'status' => 'completed',
            'payment_method' => 'card',
        ]);

        $order->update(['status' => 'Paid']);

        return $payment;
    }
}
