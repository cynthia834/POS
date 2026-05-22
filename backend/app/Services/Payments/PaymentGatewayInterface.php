<?php

namespace App\Services\Payments;

use App\Models\Order;

interface PaymentGatewayInterface
{
    /**
     * Charge the order a specific amount.
     *
     * @param Order $order
     * @param float $amount
     * @param array $data
     * @return mixed
     */
    public function charge(Order $order, float $amount, array $data = []);
}
