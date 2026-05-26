<?php
namespace App\Discounts;
use Closure;
use App\Models\DiscountRule;
use Carbon\Carbon;

class ApplySeasonalDiscounts {
    public function handle($cart, Closure $next) {
        $rules = DiscountRule::where('type', 'seasonal')->where('is_active', true)->get();
        $today = Carbon::today()->toDateString();

        foreach ($rules as $rule) {
            $cond = $rule->conditions;
            if (!$cond) {
                continue;
            }

            $startDate = $cond['start_date'] ?? null;
            $endDate = $cond['end_date'] ?? null;
            $discountPercent = $cond['discount_percentage'] ?? 0;

            if ($startDate && $endDate && $today >= $startDate && $today <= $endDate) {
                $productIds = $cond['product_ids'] ?? [];
                $categories = $cond['categories'] ?? [];

                $discountAmount = 0.0;
                if (!empty($productIds) || !empty($categories)) {
                    foreach ($cart->items as $item) {
                        $itemId = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
                        $itemQty = is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0);
                        $itemPrice = is_array($item) ? ($item['price'] ?? 0.0) : ($item->price ?? 0.0);

                        $product = \App\Models\Product::find($itemId);
                        if ($product) {
                            $matchesProduct = in_array($itemId, $productIds);
                            $matchesCategory = in_array(strtolower($product->category), array_map('strtolower', $categories));

                            if ($matchesProduct || $matchesCategory) {
                                $discountAmount += $itemQty * $itemPrice * ($discountPercent / 100);
                            }
                        }
                    }
                } else {
                    $discountAmount = $cart->subtotal * ($discountPercent / 100);
                }

                if ($discountAmount > 0) {
                    $cart->addDiscount($discountAmount, "Seasonal Discount: {$rule->name} ({$discountPercent}%)");
                }
            }
        }

        return $next($cart);
    }
}
