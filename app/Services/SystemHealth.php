<?php

namespace App\Services;

use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Models\FailedJob;
use App\Models\InboundMailbox;
use App\Models\SmtpProvider;
use App\Models\WebhookEndpoint;
use App\Services\Providers\OAuth\OAuthClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Checks across the whole installation, for Super Admins: is the scheduler running, is the queue keeping up, which
 * connections are failing, and is the configuration safe for production.
 */
class SystemHealth
{
    public const HEARTBEAT_KEY = 'prosend:scheduler-heartbeat';

    /**
     * @return list<array{name: string, status: string, detail: string}>
     */
    public function checks(): array
    {
        return [
            $this->scheduler(),
            $this->queue(),
            $this->failedJobs(),
            $this->providers(),
            $this->inboundMailboxes(),
            $this->webhooks(),
            $this->configuration(),
            $this->oauthApps(),
        ];
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function scheduler(): array
    {
        $last = Cache::get(self::HEARTBEAT_KEY);

        return match (true) {
            $last === null => $this->check('Scheduler', 'danger', 'No heartbeat seen. Add the cron entry "* * * * * php artisan schedule:run": without it, follow-ups, inbox checks and nightly jobs never run.'),
            now()->getTimestamp() - (int) $last > 180 => $this->check('Scheduler', 'danger', 'Last heartbeat '.now()->subSeconds(now()->getTimestamp() - (int) $last)->diffForHumans().'. Check the cron entry.'),
            default => $this->check('Scheduler', 'ok', 'Running: last heartbeat '.now()->subSeconds(now()->getTimestamp() - (int) $last)->diffForHumans().'.'),
        };
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function queue(): array
    {
        if (config('queue.default') !== 'database') {
            return $this->check('Queue', 'ok', 'Queue driver: '.config('queue.default').'. Waiting jobs are not counted here.');
        }

        $waiting = DB::table('jobs')->count();
        $oldest = DB::table('jobs')->where('available_at', '<=', now()->getTimestamp())->min('available_at');
        $lag = $oldest === null ? 0 : now()->getTimestamp() - (int) $oldest;
        $byQueue = DB::table('jobs')->select('queue', DB::raw('count(*) as aggregate'))->groupBy('queue')->pluck('aggregate', 'queue')
            ->map(fn (mixed $count, string $queue): string => "{$queue}: {$count}")->implode(', ');

        return $this->check('Queue', $lag > 900 ? 'warning' : 'ok', $waiting === 0
            ? 'Nothing waiting.'
            : "{$waiting} job(s) waiting ({$byQueue}).".($lag > 900 ? ' The oldest due job has waited '.intdiv($lag, 60).' minutes: is a worker running for every queue?' : ''));
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function failedJobs(): array
    {
        $count = FailedJob::query()->count();

        return $this->check('Failed jobs', $count > 0 ? 'warning' : 'ok', $count > 0 ? "{$count} job(s) failed for good; see Failed jobs." : 'None.');
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function providers(): array
    {
        $failing = SmtpProvider::acrossClients()->with('client:id,name')->where('is_enabled', true)->where('health_status', ProviderHealth::Failing)->get();

        return $this->check('Mailbox providers', $failing->isEmpty() ? 'ok' : 'danger', $failing->isEmpty()
            ? 'Every enabled provider connected at its last check.'
            : 'Failing: '.$failing->map(fn (SmtpProvider $provider): string => "{$provider->name} ({$provider->client->name})")->implode(', ').'.');
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function inboundMailboxes(): array
    {
        $problems = InboundMailbox::acrossClients()->with('client:id,name')->where('is_enabled', true)->get()
            ->filter(fn (InboundMailbox $mailbox): bool => $mailbox->health_status === ProviderHealth::Failing || $mailbox->last_synced_at === null || $mailbox->last_synced_at->lt(now()->subMinutes(30)));

        return $this->check('Inbound mailboxes', $problems->isEmpty() ? 'ok' : 'warning', $problems->isEmpty()
            ? 'Every enabled mailbox was read in the last 30 minutes.'
            : 'Not read recently or failing: '.$problems->map(fn (InboundMailbox $mailbox): string => "{$mailbox->email} ({$mailbox->client->name})")->implode(', ').'. Replies there do not stop sequences until it is fixed.');
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function webhooks(): array
    {
        $off = WebhookEndpoint::acrossClients()->where('is_enabled', false)->where('consecutive_failures', '>=', WebhookEndpoint::DISABLE_AFTER_FAILURES)->count();
        $failing = WebhookEndpoint::acrossClients()->where('is_enabled', true)->where('consecutive_failures', '>', 0)->count();

        return $this->check('Webhooks', $off > 0 ? 'warning' : 'ok', match (true) {
            $off > 0 => "{$off} endpoint(s) were turned off after repeated failures.",
            $failing > 0 => "{$failing} endpoint(s) failed their last delivery and are being retried.",
            default => 'No delivery problems.',
        });
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function configuration(): array
    {
        $issues = array_values(array_filter([
            app()->isProduction() && config('app.debug') ? 'APP_DEBUG is on in production: error pages show internal details.' : null,
            ! str_starts_with((string) config('app.url'), 'https://') ? 'APP_URL is not https: unsubscribe and tracking links and webhooks need a public https address.' : null,
            app()->isProduction() && ! config('prosend.require_verified_senders') ? 'Unverified senders can send in production.' : null,
            blank(config('prosend.marketing.postal_address')) ? 'PROSEND_POSTAL_ADDRESS is empty; clients without their own address send marketing email without one.' : null,
            config('cache.default') === 'array' ? 'The array cache does not survive between requests, so rate limits do not hold.' : null,
        ]));

        return $this->check('Configuration', $issues === [] ? 'ok' : 'warning', $issues === [] ? 'No problems found.' : implode(' ', $issues));
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function oauthApps(): array
    {
        $oauth = app(OAuthClient::class);
        $configured = array_filter([
            $oauth->isConfigured(ProviderType::Gmail) ? 'Google' : null,
            $oauth->isConfigured(ProviderType::Microsoft365) ? 'Microsoft' : null,
        ]);

        return $this->check('Gmail and Microsoft 365', 'ok', $configured === []
            ? 'Not set up: mailboxes connect over SMTP and IMAP only. Add GOOGLE_* or MICROSOFT_* app credentials to connect them with OAuth.'
            : 'OAuth apps configured: '.implode(', ', $configured).'.');
    }

    /**
     * @return array{name: string, status: string, detail: string}
     */
    private function check(string $name, string $status, string $detail): array
    {
        return ['name' => $name, 'status' => $status, 'detail' => $detail];
    }
}
