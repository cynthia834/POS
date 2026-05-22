<?php
namespace App\Models;

class Cart {
    public $items = [];
    public $customer;
    public $subtotal = 0.0;
    public $discount = 0.0;
    public $total = 0.0;

    /**
     * Cart constructor.
     *
     * @param array $items
     * @param Customer|null $customer
     */
    public function __construct(array $items, $customer = null) {
        $this->items = $items;
        $this->customer = $customer;
        $this->calculateSubtotal();
    }

    /**
     * Calculate initial subtotal before discounts.
     */
    protected function calculateSubtotal() {
        $this->subtotal = 0.0;
        foreach ($this->items as $item) {
            $this->subtotal += ($item['price'] ?? 0.0) * ($item['quantity'] ?? 0);
        }
        $this->updateTotal();
    }

    /**
     * Add a discount value to the cart.
     *
     * @param float $amount
     * @param string $reason
     */
    public function addDiscount(float $amount, string $reason = '') {
        $this->discount += $amount;
        $this->updateTotal();
    }

    /**
     * Update the total based on subtotal and discount.
     */
    public function updateTotal() {
        $this->total = max(0.0, $this->subtotal - $this->discount);
    }
}
