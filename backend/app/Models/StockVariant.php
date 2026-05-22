<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StockVariant extends Model {
    protected $fillable = ['product_id', 'unit', 'conversion_rate', 'sku'];
    public function batches() { return $this->hasMany(Batch::class); }
}
