<?php
namespace App\Discounts;

use Closure;
use App\Models\DiscountRule;

class ApplyMemberDiscounts {
    public function handle($cart, Closure $next) {
        if ($cart->customer) {
            $rules = DiscountRule::where('type', 'member')->where('is_active', true)->get();

            foreach ($rules as $rule) {
                $cond = $rule->conditions;
                if (!$cond) {
                    continue;
                }

                $tier = $cond['tier'] ?? '';
                $discountPercent = $cond['discount_percentage'] ?? 0;

                if (strtolower($cart->customer->tier) === strtolower($tier)) {
                    $discountAmount = $cart->subtotal * ($discountPercent / 100);
                    if ($discountAmount > 0) {
                        $cart->addDiscount($discountAmount, "Member Tier Discount ({$discountPercent}%)");
                    }
                }
            }
        }

        return $next($cart);
    }
}
