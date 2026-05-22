<?php
namespace App\Console\Commands;
use Illuminate\Console\Command;
class CheckLowStock extends Command {
    protected $signature = 'stock:check-low';
    protected $description = 'Check for low stock and expiring batches';
    public function handle() {
        // Logic to dispatch mailables to store managers
        $this->info('Low stock check complete.');
    }
}
