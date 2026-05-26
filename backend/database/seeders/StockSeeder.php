<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class StockSeeder extends Seeder
{
    public function run(): void
    {
        $products = DB::table('products')->get();
        foreach ($products as $product) {
            $exists = DB::table('stock_variants')->where('product_id', $product->id)->exists();
            if (!$exists) {
                $unit = $product->measurement_type === 'weight' ? 'Kg' : 'Piece';
                
                // Clean product name for SKU
                $cleanName = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $product->name));
                $cleanName = substr($cleanName, 0, 5);
                if (strlen($cleanName) < 3) {
                    $cleanName = str_pad($cleanName, 3, 'X');
                }
                $sku = $cleanName . '-' . $product->id;
                
                $variantId = DB::table('stock_variants')->insertGetId([
                    'product_id' => $product->id,
                    'unit' => $unit,
                    'conversion_rate' => 1.00,
                    'sku' => $sku,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
                
                DB::table('batches')->insert([
                    'stock_variant_id' => $variantId,
                    'quantity' => 100, // starting stock
                    'expiration_date' => Carbon::now()->addDays(365)->toDateString(),
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
        }
    }
}
