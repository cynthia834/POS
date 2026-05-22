<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
class ProductLookupController extends Controller {
    public function lookup($barcode) {
        $product = Cache::remember('product_'.$barcode, 3600, function() use ($barcode) {
            return Product::where('barcode', $barcode)->first();
        });
        return response()->json($product);
    }
}
