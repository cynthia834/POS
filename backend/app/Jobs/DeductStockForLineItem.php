<?php

namespace App\Jobs;

use App\Models\OrderLineItem;
use App\Models\Batch;
use App\Models\StockVariant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DeductStockForLineItem implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $orderLineItem;

    /**
     * Create a new job instance.
     *
     * @param  \App\Models\OrderLineItem  $orderLineItem
     * @return void
     */
    public function __construct(OrderLineItem $orderLineItem)
    {
        $this->orderLineItem = $orderLineItem;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $productId = $this->orderLineItem->product_id;
        $quantityToDeduct = $this->orderLineItem->quantity;

        // Get all stock variant IDs for this product
        $variantIds = StockVariant::where('product_id', $productId)->pluck('id');

        if ($variantIds->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($variantIds, $quantityToDeduct) {
            // Find all matching batches with stock, sorted by oldest (expiration_date, then created_at)
            $batches = Batch::whereIn('stock_variant_id', $variantIds)
                ->where('quantity', '>', 0)
                ->orderBy('expiration_date', 'asc')
                ->orderBy('created_at', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($batches as $batch) {
                if ($quantityToDeduct <= 0) {
                    break;
                }

                if ($batch->quantity >= $quantityToDeduct) {
                    $batch->quantity -= $quantityToDeduct;
                    $batch->save();
                    $quantityToDeduct = 0;
                } else {
                    $quantityToDeduct -= $batch->quantity;
                    $batch->quantity = 0;
                    $batch->save();
                }
            }
        });
    }
}
