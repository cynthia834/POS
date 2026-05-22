<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'amount',
        'status',
        'checkout_request_id',
        'transaction_id',
        'phone_number',
        'payment_method',
    ];

    public function payable()
    {
        return $this->morphTo();
    }
}
