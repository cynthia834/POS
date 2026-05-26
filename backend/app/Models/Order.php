<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model {
    protected $fillable = ['status', 'total_amount', 'customer_id'];

    public function payments() { return $this->morphMany(Payment::class, 'payable'); }
    public function items() { return $this->hasMany(OrderLineItem::class); }
    public function customer() { return $this->belongsTo(Customer::class); }
}
