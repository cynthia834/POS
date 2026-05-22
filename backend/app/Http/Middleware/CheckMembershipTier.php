<?php
namespace App\Http\Middleware;
use Closure;
class CheckMembershipTier {
    public function handle($request, Closure $next) {
        // Apply global scopes based on tier
        return $next($request);
    }
}
