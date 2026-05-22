<?php
namespace App\Discounts;
use Closure;
use App\Models\DiscountRule;

class ApplyBogoDiscounts {
    public function handle($cart, Closure $next) {
        $rules = DiscountRule::where('type', 'bogo')->where('is_active', true)->get();

        foreach ($rules as $rule) {
            $cond = $rule->conditions;
            if (!$cond) {
                continue;
            }

            $productId = $cond['product_id'] ?? null;
            $buyQty = $cond['buy_qty'] ?? 1;
            $getQty = $cond['get_qty'] ?? 1;
            $discountPercent = $cond['discount_percentage'] ?? 100;

            foreach ($cart->items as $item) {
                $itemId = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
                $itemQty = is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0);
                $itemPrice = is_array($item) ? ($item['price'] ?? 0.0) : ($item->price ?? 0.0);

                if ($itemId == $productId) {
                    $groupSize = $buyQty + $getQty;
                    if ($groupSize <= 0) {
                        continue;
                    }

                    $groups = floor($itemQty / $groupSize);
                    $freeQty = $groups * $getQty;
                    $discountAmount = $freeQty * $itemPrice * ($discountPercent / 100);

                    if ($discountAmount > 0) {
                        $cart->addDiscount($discountAmount, "BOGO: Buy {$buyQty} Get {$getQty} Discount");
                    }
                }
            }
        }

        return $next($cart);
    }
}
