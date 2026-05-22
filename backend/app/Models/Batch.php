<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Batch extends Model {
    protected $fillable = ['stock_variant_id', 'quantity', 'expiration_date'];
}
