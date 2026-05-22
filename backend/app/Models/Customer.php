<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model {
    protected $fillable = ['name', 'phone', 'points_balance'];

    public function pointTransactions() {
        return $this->hasMany(PointTransaction::class);
    }

    public function getTierAttribute() {
        return $this->points_balance >= 1000 ? 'gold' : 'standard';
    }
}
