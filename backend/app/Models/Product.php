<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Product extends Model {
    protected $fillable = ['name', 'barcode', 'price', 'category', 'image_url'];
    public function stockVariants() { return $this->hasMany(StockVariant::class); }
}
