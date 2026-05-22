<?php
namespace App\Services;

use App\Models\Customer;
use App\Models\PointTransaction;
use Illuminate\Support\Facades\DB;

class LoyaltyService {
    /**
     * Award points to a customer based on the amount spent.
     * Earns 1 point for every 100 KES spent.
     *
     * @param Customer $customer
     * @param float $amountSpent
     * @param string $description
     * @return PointTransaction|null
     */
    public function awardPoints(Customer $customer, float $amountSpent, string $description = '') {
        $points = floor($amountSpent / 100);
        
        if ($points > 0) {
            return DB::transaction(function () use ($customer, $points, $description) {
                return PointTransaction::create([
                    'customer_id' => $customer->id,
                    'points' => (int) $points,
                    'type' => 'earned',
                    'description' => $description,
                ]);
            });
        }
        
        return null;
    }
}
