<?php

namespace App\Observers;

use App\Models\OrderLineItem;
use App\Jobs\DeductStockForLineItem;

class OrderLineItemObserver
{
    /**
     * Handle the OrderLineItem "created" event.
     *
     * @param  \App\Models\OrderLineItem  $orderLineItem
     * @return void
     */
    public function created(OrderLineItem $orderLineItem)
    {
        $order = $orderLineItem->order;

        if ($order && ($order->status === 'completed' || $order->status === 'Paid')) {
            DeductStockForLineItem::dispatch($orderLineItem);
        }
    }
}
