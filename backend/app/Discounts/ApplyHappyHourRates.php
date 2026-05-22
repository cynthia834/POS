<?php
namespace App\Discounts;
use Closure;
use App\Models\DiscountRule;

class ApplyHappyHourRates {
    public function handle($cart, Closure $next) {
        $rules = DiscountRule::where('type', 'happy_hour')->where('is_active', true)->get();
        $currentTime = now()->toTimeString();

        foreach ($rules as $rule) {
            $cond = $rule->conditions;
            if (!$cond) {
                continue;
            }

            $startTime = $cond['start_time'] ?? '00:00:00';
            $endTime = $cond['end_time'] ?? '23:59:59';
            $discountPercent = $cond['discount_percentage'] ?? 0;

            if ($currentTime >= $startTime && $currentTime <= $endTime) {
                $discountAmount = $cart->subtotal * ($discountPercent / 100);
                if ($discountAmount > 0) {
                    $cart->addDiscount($discountAmount, "Happy Hour Discount ({$discountPercent}%)");
                }
            }
        }

        return $next($cart);
    }
}
