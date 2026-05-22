<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Product;
use App\Models\StockVariant;
use App\Models\Batch;
use App\Models\Customer;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Disable foreign key checks for clean seeding
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Product::truncate();
        StockVariant::truncate();
        Batch::truncate();
        Customer::truncate();
        User::truncate();
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $products = [
            ['id' => 1, 'name' => 'Organic Bananas Basket', 'barcode' => '8901234', 'price' => 12500.00, 'category' => 'Groceries', 'image_url' => 'https://images.unsplash.com/photo-1571771894821-ce9b6c11b08e?q=80&w=200'],
            ['id' => 2, 'name' => 'Sourdough Bread Wholesale', 'barcode' => '8901235', 'price' => 8240.00, 'category' => 'Bakery', 'image_url' => 'https://images.unsplash.com/photo-1549931319-a545dcf3bc73?q=80&w=200'],
            ['id' => 3, 'name' => 'Avocado Bulk Pack', 'barcode' => '8901236', 'price' => 4100.00, 'category' => 'Groceries', 'image_url' => 'https://images.unsplash.com/photo-1523049673857-eb18f1d7b578?q=80&w=200'],
            ['id' => 4, 'name' => 'Whole Milk 10-Pack', 'barcode' => '8901237', 'price' => 2100.00, 'category' => 'Dairy', 'image_url' => 'https://images.unsplash.com/photo-1550583724-b2692b85b150?q=80&w=200'],
            ['id' => 5, 'name' => 'Arabica Coffee Bag', 'barcode' => '8901238', 'price' => 15350.00, 'category' => 'Coffee', 'image_url' => 'https://images.unsplash.com/photo-1447933601403-0c6688de566e?q=80&w=200'],
            ['id' => 6, 'name' => 'Farm Eggs (Case)', 'barcode' => '8901239', 'price' => 3800.00, 'category' => 'Dairy', 'image_url' => 'https://images.unsplash.com/photo-1516448424440-9dbca97779c1?q=80&w=200'],
            ['id' => 7, 'name' => 'Greek Yogurt Box', 'barcode' => '8901240', 'price' => 4200.00, 'category' => 'Dairy', 'image_url' => 'https://images.unsplash.com/photo-1488477181946-6428a0291777?q=80&w=200'],
            ['id' => 8, 'name' => 'Almond Butter Case', 'barcode' => '8901241', 'price' => 8500.00, 'category' => 'Groceries', 'image_url' => 'https://images.unsplash.com/photo-1590080875515-8a3a8dc5735e?q=80&w=200'],
        ];

        foreach ($products as $p) {
            $product = Product::create($p);

            // Create stock variant for FIFO deduction
            $variant = StockVariant::create([
                'product_id' => $product->id,
                'unit' => 'Piece',
                'conversion_rate' => 1.00,
                'sku' => strtoupper(substr(str_replace(' ', '', $product->name), 0, 5)) . '-' . $product->id,
            ]);

            // Create some batches for this variant
            // We can create two batches to demonstrate FIFO
            Batch::create([
                'stock_variant_id' => $variant->id,
                'quantity' => 10,
                'expiration_date' => now()->addDays(5)->toDateString(),
            ]);

            Batch::create([
                'stock_variant_id' => $variant->id,
                'quantity' => 40,
                'expiration_date' => now()->addDays(15)->toDateString(),
            ]);
        }

        $customers = [
            ['id' => 1, 'name' => 'Jane Doe', 'phone' => '+254 712 345678', 'points_balance' => 420],
            ['id' => 2, 'name' => 'David Ndwiga', 'phone' => '+254 722 987654', 'points_balance' => 310],
            ['id' => 3, 'name' => 'Grace Wambui', 'phone' => '+254 733 111222', 'points_balance' => 150],
            ['id' => 4, 'name' => 'Alex Mwangi', 'phone' => '+254 755 444333', 'points_balance' => 950],
        ];

        foreach ($customers as $c) {
            Customer::create($c);
        }
    }
}
