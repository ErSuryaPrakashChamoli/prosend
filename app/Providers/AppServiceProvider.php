<?php

namespace App\Providers;

use App\Models\ApiKey;
use App\Services\Automation\OutreachEvents;
use App\Services\Deliverability\DnsResolver;
use App\Services\Deliverability\SystemDnsResolver;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Inbox\RoutingMailboxReader;
use App\Services\Providers\MailProviderManager;
use App\Services\Tenancy\CurrentClient;
use Filament\Actions\ExportAction;
use Filament\Actions\ExportBulkAction;
use Filament\Actions\Exports\ExportColumn;
use Filament\Resources\Resource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Middleware\TrustProxies;
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
        $this->app->scoped(CurrentClient::class);
        $this->app->scoped(OutreachEvents::class);
        $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        $this->app->bind(MailboxReader::class, RoutingMailboxReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Exports contain imported, untrusted text: a cell such as "=HYPERLINK(...)" must not run as a spreadsheet formula.
        ExportColumn::configureUsing(fn (ExportColumn $column) => $column->preventFormulaInjection());

        // ProSend's ClientScope and BelongsToClient scope every client-owned model, in the panel and outside it.
        // Filament's own tenancy scope is turned off: it would overwrite a client chosen on purpose, such as a
        // Super Admin adding someone to another client.
        Resource::scopeToTenant(false);

        // Exports run in a queue job, where no client is current, so the client is written into the query itself.
        // An export action that sets its own modifyQueryUsing() must keep this constraint.
        foreach ([ExportAction::class, ExportBulkAction::class] as $exportAction) {
            $exportAction::configureUsing(fn (ExportAction|ExportBulkAction $action) => $action->modifyQueryUsing(
                fn (Builder $query): Builder => static::limitToCurrentClient($query),
            ));
        }

        if (filled($trustedProxies = config('prosend.trusted_proxies'))) {
            TrustProxies::at($trustedProxies === '*' ? '*' : array_map(trim(...), explode(',', $trustedProxies)));
        }

        RateLimiter::for('api', function (Request $request): Limit {
            $apiKey = $request->attributes->get('api_key');

            return $apiKey instanceof ApiKey
                ? Limit::perMinute($apiKey->rate_limit_per_minute)->by("api-key:{$apiKey->getKey()}")
                : Limit::perMinute(30)->by("api-ip:{$request->ip()}");
        });
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function limitToCurrentClient(Builder $query): Builder
    {
        $clientId = app(CurrentClient::class)->id();

        return $clientId === null ? $query->whereRaw('1 = 0') : $query->where($query->qualifyColumn('client_id'), $clientId);
    }
}
