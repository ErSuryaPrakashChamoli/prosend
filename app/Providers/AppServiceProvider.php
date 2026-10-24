<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Services\Deliverability\DnsResolver;
use App\Services\Deliverability\SystemDnsResolver;
use App\Services\Providers\MailProviderManager;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(MailProviderManager::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            return $apiKey instanceof ApiKey
                ? Limit::perMinute($apiKey->rate_limit_per_minute)->by("api-key:{$apiKey->getKey()}")
                : Limit::perMinute(30)->by("api-ip:{$request->ip()}");
        });
    }
}
