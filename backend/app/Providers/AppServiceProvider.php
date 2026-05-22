<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Models\OrderLineItem;
use App\Observers\OrderLineItemObserver;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        OrderLineItem::observe(OrderLineItemObserver::class);
        \App\Models\Order::observe(\App\Observers\OrderObserver::class);
    }
}
