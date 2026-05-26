<?php
namespace App\Observers;
use App\Models\Order;
use App\Jobs\ProcessStockDeduction;
class OrderObserver {
    public function created(Order $order) {
        ProcessStockDeduction::dispatch($order);
    }

    public function updated(Order $order) {
        $originalStatus = $order->getOriginal('status');
        $newStatus = $order->status;

        if (($newStatus === 'Paid' || $newStatus === 'completed') && $originalStatus !== 'Paid' && $originalStatus !== 'completed') {
            foreach ($order->items as $item) {
                \App\Jobs\DeductStockForLineItem::dispatch($item);
            }

            if ($order->customer_id) {
                $customer = \App\Models\Customer::find($order->customer_id);
                if ($customer) {
                    $loyaltyService = app(\App\Services\LoyaltyService::class);
                    $loyaltyService->awardPoints($customer, $order->total_amount, "Points earned from Order #{$order->id}");
                }
            }
        }
    }
}
