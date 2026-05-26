<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Product;
use App\Models\StockVariant;
use App\Models\Batch;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('stockVariants.batches')->get();
        
        $products->map(function ($product) {
            $totalStock = 0;
            $oldestExpiry = null;
            foreach ($product->stockVariants as $variant) {
                $totalStock += $variant->batches->sum('quantity');
                $batch = $variant->batches->where('quantity', '>', 0)->whereNotNull('expiration_date')->sortBy('expiration_date')->first();
                if ($batch && (!$oldestExpiry || $batch->expiration_date < $oldestExpiry)) {
                    $oldestExpiry = $batch->expiration_date;
                }
            }
            $product->stock = $totalStock;
            $product->expiration_date = $oldestExpiry;
            return $product;
        });

        return response()->json($products);
    }

    public function lookup($barcode)
    {
        // Cache the product lookup by barcode for 3600 seconds (1 hour)
        $product = Cache::remember('product_' . $barcode, 3600, function () use ($barcode) {
            return Product::where('barcode', $barcode)->first();
        });

        if (!$product) {
            return response()->json(['error' => 'Product not found'], 404);
        }

        // Attach stock to the lookup result too
        $totalStock = 0;
        $oldestExpiry = null;
        foreach ($product->stockVariants as $variant) {
            $totalStock += $variant->batches->sum('quantity');
            $batch = $variant->batches->where('quantity', '>', 0)->whereNotNull('expiration_date')->sortBy('expiration_date')->first();
            if ($batch && (!$oldestExpiry || $batch->expiration_date < $oldestExpiry)) {
                $oldestExpiry = $batch->expiration_date;
            }
        }
        $product->stock = $totalStock;
        $product->expiration_date = $oldestExpiry;

        return response()->json($product);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string',
            'barcode' => 'required|string|unique:products,barcode',
            'price' => 'required|numeric|min:0',
            'category' => 'required|string',
            'stock' => 'required|integer|min:0',
            'image_url' => 'nullable|string',
            'expiration_date' => 'nullable|date'
        ]);

        $product = Product::create([
            'name' => $request->name,
            'barcode' => $request->barcode,
            'price' => $request->price,
            'category' => $request->category,
            'image_url' => $request->image_url ?: 'https://images.unsplash.com/photo-1546069901-ba9599a7e63c?q=80&w=200'
        ]);

        $variant = StockVariant::create([
            'product_id' => $product->id,
            'unit' => 'Piece',
            'conversion_rate' => 1.00,
            'sku' => strtoupper(substr(str_replace(' ', '', $product->name), 0, 5)) . '-' . $product->id . '-' . time()
        ]);

        Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => $request->stock,
            'expiration_date' => $request->expiration_date ?: now()->addDays(30)->toDateString()
        ]);

        $product->load('stockVariants.batches');
        $product->stock = $request->stock;
        $product->expiration_date = $request->expiration_date ?: now()->addDays(30)->toDateString();

        // Clear any barcode lookup cache to be safe
        Cache::forget('product_' . $product->barcode);

        return response()->json($product, 201);
    }

    public function restock(Request $request, Product $product)
    {
        $variant = $product->stockVariants()->first();
        if (!$variant) {
            $variant = StockVariant::create([
                'product_id' => $product->id,
                'unit' => 'Piece',
                'conversion_rate' => 1.00,
                'sku' => 'VAR-' . $product->id . '-' . time(),
            ]);
        }

        $quantity = $request->input('quantity', 30);
        $expiry = $request->input('expiration_date') ?: now()->addDays(30)->toDateString();

        Batch::create([
            'stock_variant_id' => $variant->id,
            'quantity' => $quantity,
            'expiration_date' => $expiry
        ]);

        $product->load('stockVariants.batches');
        
        $totalStock = 0;
        $oldestExpiry = null;
        foreach ($product->stockVariants as $v) {
            $totalStock += $v->batches->sum('quantity');
            $batch = $v->batches->where('quantity', '>', 0)->whereNotNull('expiration_date')->sortBy('expiration_date')->first();
            if ($batch && (!$oldestExpiry || $batch->expiration_date < $oldestExpiry)) {
                $oldestExpiry = $batch->expiration_date;
            }
        }
        $product->stock = $totalStock;
        $product->expiration_date = $oldestExpiry;

        // Clear cache
        Cache::forget('product_' . $product->barcode);

        return response()->json([
            'success' => true,
            'product' => $product
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $request->validate([
            'name' => 'required|string',
            'barcode' => 'required|string|unique:products,barcode,' . $product->id,
            'price' => 'required|numeric|min:0',
            'category' => 'required|string',
            'image_url' => 'nullable|string',
            'stock' => 'nullable|integer|min:0',
            'expiration_date' => 'nullable|date'
        ]);

        $product->update([
            'name' => $request->name,
            'barcode' => $request->barcode,
            'price' => $request->price,
            'category' => $request->category,
            'image_url' => $request->image_url
        ]);

        $variant = $product->stockVariants()->first();
        if ($variant) {
            $batch = $variant->batches()->first();
            $dataToUpdate = [];
            if ($request->has('stock')) {
                $dataToUpdate['quantity'] = $request->stock;
            }
            if ($request->has('expiration_date') && $request->expiration_date) {
                $dataToUpdate['expiration_date'] = $request->expiration_date;
            }
            
            if ($batch) {
                if (!empty($dataToUpdate)) {
                    $batch->update($dataToUpdate);
                }
            } else {
                Batch::create([
                    'stock_variant_id' => $variant->id,
                    'quantity' => $request->input('stock', 0),
                    'expiration_date' => $request->input('expiration_date') ?: now()->addDays(30)->toDateString()
                ]);
            }
        }

        $product->load('stockVariants.batches');
        $totalStock = 0;
        $oldestExpiry = null;
        foreach ($product->stockVariants as $v) {
            $totalStock += $v->batches->sum('quantity');
            $batch = $v->batches->where('quantity', '>', 0)->whereNotNull('expiration_date')->sortBy('expiration_date')->first();
            if ($batch && (!$oldestExpiry || $batch->expiration_date < $oldestExpiry)) {
                $oldestExpiry = $batch->expiration_date;
            }
        }
        $product->stock = $totalStock;
        $product->expiration_date = $oldestExpiry;

        // Clear cache
        Cache::forget('product_' . $product->barcode);

        return response()->json([
            'success' => true,
            'product' => $product
        ]);
    }
}
