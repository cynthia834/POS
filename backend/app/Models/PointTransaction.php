<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PointTransaction extends Model {
    protected $fillable = ['customer_id', 'points', 'type', 'description'];

    public function customer() {
        return $this->belongsTo(Customer::class);
    }

    public static function booted() {
        static::created(function ($transaction) {
            $transaction->customer->increment('points_balance', $transaction->points);
        });
    }
}

if (!class_exists('Illuminate\Foundation\Application', false)) {
    PointTransaction::booted();
}
