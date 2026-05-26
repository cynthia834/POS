<?php
namespace App\Discounts;
use Closure;
use App\Models\DiscountRule;
use App\Models\StockVariant;
use App\Models\Batch;
use Carbon\Carbon;

class ApplyMarkdownDiscounts {
    public function handle($cart, Closure $next) {
        $rules = DiscountRule::where('type', 'markdown')->where('is_active', true)->get();

        if ($rules->isEmpty()) {
            return $next($cart);
        }

        $markdownRules = [];
        // If rules exist in DB, parse and override
        foreach ($rules as $rule) {
            $cond = $rule->conditions;
            if ($cond && isset($cond['rules'])) {
                $markdownRules = $cond['rules'];
                break;
            }
        }

        if (empty($markdownRules)) {
            return $next($cart);
        }

        // Sort markdown rules by days_left ascending (e.g. 7 before 30)
        usort($markdownRules, function($a, $b) {
            return $a['days_left'] <=> $b['days_left'];
        });

        foreach ($cart->items as $item) {
            $productId = is_array($item) ? ($item['id'] ?? null) : ($item->id ?? null);
            $itemQty = is_array($item) ? ($item['quantity'] ?? 0) : ($item->quantity ?? 0);
            $itemPrice = is_array($item) ? ($item['price'] ?? 0.0) : ($item->price ?? 0.0);

            if (!$productId) {
                continue;
            }

            // Find variant IDs for the product
            $variantIds = StockVariant::where('product_id', $productId)->pluck('id');
            if ($variantIds->isEmpty()) {
                continue;
            }

            // Find the oldest active batch with stock and expiration date
            $batch = Batch::whereIn('stock_variant_id', $variantIds)
                ->where('quantity', '>', 0)
                ->whereNotNull('expiration_date')
                ->orderBy('expiration_date', 'asc')
                ->first();

            if ($batch) {
                $expirationDate = Carbon::parse($batch->expiration_date);
                $daysLeft = Carbon::today()->diffInDays($expirationDate, false);

                // Find the appropriate markdown rule (lowest matching days_left)
                $appliedDiscountPercent = 0;
                foreach ($markdownRules as $rule) {
                    if ($daysLeft <= $rule['days_left']) {
                        $appliedDiscountPercent = $rule['discount_percentage'];
                        break;
                    }
                }

                if ($appliedDiscountPercent > 0) {
                    $discountAmount = $itemQty * $itemPrice * ($appliedDiscountPercent / 100);
                    $cart->addDiscount($discountAmount, "Markdown: End-of-life discount ({$appliedDiscountPercent}%, {$daysLeft} days left)");
                }
            }
        }

        return $next($cart);
    }
}
