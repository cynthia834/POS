$backendDir = "d:\SupermarketPOs\backend"
$appDir = "$backendDir\app"

$directories = @(
    "$appDir\Models",
    "$appDir\Observers",
    "$appDir\Jobs",
    "$appDir\Console\Commands",
    "$appDir\Http\Controllers",
    "$appDir\Payments",
    "$appDir\Http\Middleware",
    "$appDir\Discounts"
)

foreach ($dir in $directories) {
    if (-not (Test-Path -Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
    }
}

function Write-Class($path, $content) {
    Set-Content -Path $path -Value $content
}

# 1. Models
Write-Class "$appDir\Models\Product.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Product extends Model {
    protected `$fillable = ['name', 'barcode', 'price'];
    public function stockVariants() { return `$this->hasMany(StockVariant::class); }
}
"@

Write-Class "$appDir\Models\StockVariant.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class StockVariant extends Model {
    protected `$fillable = ['product_id', 'unit', 'conversion_rate', 'sku'];
    public function batches() { return `$this->hasMany(Batch::class); }
}
"@

Write-Class "$appDir\Models\Batch.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Batch extends Model {
    protected `$fillable = ['stock_variant_id', 'quantity', 'expiration_date'];
}
"@

Write-Class "$appDir\Models\Order.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Order extends Model {
    public function payments() { return `$this->morphMany(Payment::class, 'payable'); }
    public function items() { return `$this->hasMany(OrderLineItem::class); }
}
"@

Write-Class "$appDir\Models\OrderLineItem.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class OrderLineItem extends Model {
    protected `$fillable = ['order_id', 'product_id', 'quantity', 'price'];
}
"@

# Observer & Job
Write-Class "$appDir\Observers\OrderObserver.php" @"
<?php
namespace App\Observers;
use App\Models\Order;
use App\Jobs\ProcessStockDeduction;
class OrderObserver {
    public function created(Order `$order) {
        ProcessStockDeduction::dispatch(`$order);
    }
}
"@

Write-Class "$appDir\Jobs\ProcessStockDeduction.php" @"
<?php
namespace App\Jobs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\Order;
class ProcessStockDeduction implements ShouldQueue {
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected `$order;
    public function __construct(Order `$order) { `$this->order = `$order; }
    public function handle() {
        // Implement FIFO stock deduction logic here...
    }
}
"@

Write-Class "$appDir\Console\Commands\CheckLowStock.php" @"
<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class CheckLowStock extends Command {
    protected `$signature = 'stock:check-low';
    protected `$description = 'Check for low stock and expiring batches';
    public function handle() {
        // Logic to dispatch mailables to store managers
        `$this->info('Low stock check complete.');
    }
}
"@

# Lookup Controller
Write-Class "$appDir\Http\Controllers\ProductLookupController.php" @"
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
class ProductLookupController extends Controller {
    public function lookup(`$barcode) {
        `$product = Cache::remember('product_'.`$barcode, 3600, function() use (`$barcode) {
            return Product::where('barcode', `$barcode)->first();
        });
        return response()->json(`$product);
    }
}
"@

# Payments Strategy
Write-Class "$appDir\Payments\PaymentGatewayInterface.php" @"
<?php
namespace App\Payments;
interface PaymentGatewayInterface {
    public function charge(`$amount, array `$options = []);
}
"@

Write-Class "$appDir\Payments\MPesaGateway.php" @"
<?php
namespace App\Payments;
class MPesaGateway implements PaymentGatewayInterface {
    public function charge(`$amount, array `$options = []) {
        // Trigger STK Push logic here
        return ['status' => 'pending_push'];
    }
}
"@

# M-Pesa Webhook Controller
Write-Class "$appDir\Http\Controllers\MPesaWebhookController.php" @"
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
class MPesaWebhookController extends Controller {
    public function handlePushCallback(Request `$request) {
        // Verify signature and process STK push result
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
"@

# Loyalty & CRM
Write-Class "$appDir\Models\Customer.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class Customer extends Model {
    protected `$fillable = ['name', 'phone', 'points_balance'];
}
"@

Write-Class "$appDir\Models\PointTransaction.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class PointTransaction extends Model {
    protected `$fillable = ['customer_id', 'points', 'type', 'description'];
}
"@

Write-Class "$appDir\Http\Middleware\CheckMembershipTier.php" @"
<?php
namespace App\Http\Middleware;
use Closure;
class CheckMembershipTier {
    public function handle(`$request, Closure `$next) {
        // Apply global scopes based on tier
        return `$next(`$request);
    }
}
"@

# Discount Pipeline
Write-Class "$appDir\Models\DiscountRule.php" @"
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
class DiscountRule extends Model {
    protected `$casts = ['conditions' => 'json'];
}
"@

Write-Class "$appDir\Discounts\ApplyBogoDiscounts.php" @"
<?php
namespace App\Discounts;
use Closure;
class ApplyBogoDiscounts {
    public function handle(`$cart, Closure `$next) {
        // Evaluate BOGO rules
        return `$next(`$cart);
    }
}
"@

Write-Class "$appDir\Discounts\ApplyHappyHourRates.php" @"
<?php
namespace App\Discounts;
use Closure;
class ApplyHappyHourRates {
    public function handle(`$cart, Closure `$next) {
        return `$next(`$cart);
    }
}
"@

Write-Class "$appDir\Http\Controllers\CheckoutController.php" @"
<?php
namespace App\Http\Controllers;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use App\Discounts\ApplyBogoDiscounts;
use App\Discounts\ApplyHappyHourRates;
class CheckoutController extends Controller {
    public function calculate(Request `$request) {
        `$cart = `$request->input('cart');
        
        `$cart = app(Pipeline::class)
            ->send(`$cart)
            ->through([
                ApplyBogoDiscounts::class,
                ApplyHappyHourRates::class,
            ])
            ->thenReturn();
            
        return response()->json(`$cart);
    }
}
"@

Write-Host "Laravel backend architecture files created successfully!"
