<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Enums\ProviderType;
use App\Enums\SmtpEncryption;
use App\Filament\Resources\SmtpProviders\SmtpProviderResource;
use App\Models\Client;
use App\Models\InboundMailbox;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Services\AuditLogger;
use App\Services\Providers\OAuth\OAuthClient;
use App\Services\Tenancy\CurrentClient;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Connects a Gmail or Microsoft 365 mailbox. connect() starts in the client's panel; the provider sends the person back
 * to callback(), a fixed address that can be registered with Google and Microsoft. The state in the session ties the
 * two together, so a callback can only finish a connection the same person started, for the client they started it in.
 */
class OAuthMailboxController extends Controller
{
    public const SESSION_KEY = 'oauth_mailbox';

    /**
     * The route also carries the client's slug, so the provider is read from the route by name.
     */
    public function connect(Request $request, OAuthClient $oauth): RedirectResponse
    {
        $type = $this->type((string) $request->route('provider'));
        $client = Filament::getTenant();

        abort_unless($client instanceof Client && $request->user()?->hasPermission(Permission::ManageMailboxes, $client), 403);
        abort_unless($oauth->isConfigured($type), 404);

        $state = Str::random(40);
        $verifier = Str::random(64);

        $request->session()->put(self::SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'type' => $type->value,
            'client_id' => $client->getKey(),
            'user_id' => $request->user()->getKey(),
            'expires_at' => now()->addMinutes(10)->getTimestamp(),
        ]);

        return redirect()->away($oauth->authorizationUrl($type, $state, $verifier));
    }

    public function callback(Request $request, string $provider, OAuthClient $oauth, CurrentClient $currentClient, AuditLogger $auditLogger): RedirectResponse
    {
        $type = $this->type($provider);
        $pending = $request->session()->pull(self::SESSION_KEY);

        $valid = is_array($pending)
            && hash_equals((string) $pending['state'], (string) $request->query('state'))
            && $pending['type'] === $type->value
            && $pending['user_id'] === $request->user()?->getKey()
            && $pending['expires_at'] >= now()->getTimestamp();

        abort_unless($valid, 403, 'This sign-in link expired or was not started here. Start again from Mailbox providers.');

        $client = Client::query()->findOrFail($pending['client_id']);
        abort_unless($request->user()->hasPermission(Permission::ManageMailboxes, $client), 403);

        $back = SmtpProviderResource::getUrl(panel: 'admin', tenant: $client);

        if (filled($request->query('error'))) {
            Notification::make()->title('Mailbox not connected')->body('The sign-in was cancelled or refused.')->warning()->send();

            return redirect($back);
        }

        try {
            $tokens = $oauth->exchangeCode($type, (string) $request->query('code'), (string) $pending['verifier']);
            $email = $oauth->accountEmail($type, $tokens['access_token']);
        } catch (Throwable $exception) {
            Notification::make()->title('Mailbox not connected')->body(Str::limit($exception->getMessage(), 300))->danger()->send();

            return redirect($back);
        }

        $smtpProvider = $currentClient->runAs($client, fn (): SmtpProvider => DB::transaction(fn (): SmtpProvider => $this->store($client, $type, $email, $tokens, $request)));

        $auditLogger->record('mailbox.connected', $smtpProvider, ['email' => $email, 'type' => $type->value], client: $client);
        $readsOverImap = $currentClient->runAs($client, fn (): bool => InboundMailbox::query()->where('email', $email)->where('type', InboundMailbox::TYPE_IMAP)->exists());
        Notification::make()
            ->title("{$email} connected")
            ->body($readsOverImap ? 'It can send now. Replies to it are still read over IMAP, by the inbound mailbox already set up for it.' : 'It can send now, and replies to it are read into the inbox.')
            ->success()
            ->send();

        return redirect(SmtpProviderResource::getUrl('view', ['record' => $smtpProvider], panel: 'admin', tenant: $client));
    }

    /**
     * One provider, sender identity and inbound mailbox per connected address. Connecting again refreshes the tokens and
     * leaves a provider someone switched off as it is. An inbound mailbox already reading the address over IMAP is left
     * alone: it keeps its own settings and is not removed with the connection.
     *
     * @param  array{access_token: string, refresh_token: string|null, expires_in: int}  $tokens
     */
    private function store(Client $client, ProviderType $type, string $email, array $tokens, Request $request): SmtpProvider
    {
        $provider = SmtpProvider::query()->where('type', $type)->where('oauth_account_email', $email)->first()
            ?? new SmtpProvider([
                'name' => $email,
                'type' => $type,
                'host' => $type->defaultHost(),
                'port' => $type->defaultPort(),
                'encryption' => SmtpEncryption::Tls,
                'username' => $email,
                // A conservative default, below both services' own daily limits; change it on the provider.
                'daily_limit' => 400,
                'max_per_minute' => 20,
            ]);

        $provider->forceFill([
            'oauth_account_email' => $email,
            'oauth_access_token' => $tokens['access_token'],
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $provider->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'])),
            'is_enabled' => $provider->exists ? $provider->is_enabled : true,
        ])->save();
        $provider->recordConnectionSuccess();

        $identity = SenderIdentity::query()->where('email', $email)->first()
            ?? new SenderIdentity(['email' => $email, 'name' => $request->user()->name]);
        $identity->smtp_provider_id = $provider->getKey();
        $identity->save();

        // Signing in to the mailbox proves the address belongs to whoever connected it.
        if (! $identity->isVerified()) {
            $identity->markVerified($request->user());
        }

        $mailbox = InboundMailbox::query()->firstOrCreate(
            ['email' => $email],
            ['name' => $email, 'type' => $type === ProviderType::Gmail ? InboundMailbox::TYPE_GMAIL : InboundMailbox::TYPE_MICROSOFT, 'is_enabled' => true],
        );

        if ($mailbox->type !== InboundMailbox::TYPE_IMAP) {
            $mailbox->forceFill(['smtp_provider_id' => $provider->getKey()])->save();
        }

        return $provider;
    }

    private function type(string $provider): ProviderType
    {
        return match ($provider) {
            'google' => ProviderType::Gmail,
            'microsoft' => ProviderType::Microsoft365,
            default => abort(404),
        };
    }
}
