<?php

use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\ProviderHealth;
use App\Enums\ProviderType;
use App\Enums\SystemRole;
use App\Http\Controllers\OAuthMailboxController;
use App\Jobs\SendEmailMessage;
use App\Models\AuditLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\EmailMessage;
use App\Models\InboundMailbox;
use App\Models\Scopes\ClientScope;
use App\Models\SenderIdentity;
use App\Models\SmtpProvider;
use App\Models\User;
use App\Services\Inbox\ApiMailboxReader;
use App\Services\Inbox\InboxSynchronizer;
use App\Services\Providers\OAuth\OAuthClient;
use App\Services\Providers\OAuth\OAuthRevoked;
use Filament\Facades\Filament;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config([
        'services.google' => ['client_id' => 'google-id', 'client_secret' => 'google-secret'],
        'services.microsoft' => ['client_id' => 'ms-id', 'client_secret' => 'ms-secret', 'tenant' => 'common'],
    ]);
});

function oauthProvider(ProviderType $type = ProviderType::Gmail, array $attributes = []): SmtpProvider
{
    $provider = SmtpProvider::factory()->create(['type' => $type, 'host' => $type->defaultHost(), 'port' => 443, 'name' => 'Connected']);
    $provider->forceFill(['oauth_account_email' => 'sales@acme.test', 'oauth_access_token' => 'access-1', 'oauth_refresh_token' => 'refresh-1', 'oauth_expires_at' => now()->addHour(), ...$attributes])->save();

    return $provider;
}

it('asks Google for offline access with PKCE', function () {
    $url = app(OAuthClient::class)->authorizationUrl(ProviderType::Gmail, 'state-1', 'verifier-1');
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    expect($url)->toStartWith('https://accounts.google.com/o/oauth2/v2/auth?')
        ->and($query)->state->toBe('state-1')
        ->code_challenge->toBe(rtrim(strtr(base64_encode(hash('sha256', 'verifier-1', true)), '+/', '-_'), '='))
        ->code_challenge_method->toBe('S256')
        ->access_type->toBe('offline')
        ->redirect_uri->toBe(url('/admin/oauth/google/callback'))
        ->scope->toContain('gmail.send')->toContain('gmail.readonly');
});

it('connects a Gmail mailbox: provider, verified sender and inbound mailbox, with encrypted tokens', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 3599]),
        'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'Sales@Acme.test']),
    ]);
    $this->actingAs($admin = User::factory()->withClientRole(SystemRole::Admin)->create());

    $redirect = $this->get(route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => 'google']));
    $redirect->assertRedirectContains('accounts.google.com');
    $state = session(OAuthMailboxController::SESSION_KEY)['state'];

    $this->get(route('filament.admin.mailboxes.oauth-callback', ['provider' => 'google', 'state' => $state, 'code' => 'code-1']))->assertRedirect();

    $provider = SmtpProvider::query()->where('type', ProviderType::Gmail)->sole();
    expect($provider)
        ->oauth_account_email->toBe('sales@acme.test')
        ->oauth_access_token->toBe('access-1')
        ->health_status->toBe(ProviderHealth::Healthy);
    expect(DB::table('smtp_providers')->where('id', $provider->id)->value('oauth_refresh_token'))->not->toBe('refresh-1');
    expect(SenderIdentity::query()->where('email', 'sales@acme.test')->sole())->smtp_provider_id->toBe($provider->id)->isVerified()->toBeTrue();
    expect(InboundMailbox::query()->sole())->type->toBe(InboundMailbox::TYPE_GMAIL)->smtp_provider_id->toBe($provider->id);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://oauth2.googleapis.com/token' && filled($request['code_verifier']) && $request['code'] === 'code-1');
});

it('refuses a callback it did not start, from someone else, or too late', function (Closure $tamper) {
    Http::fake();
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    $this->get(route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => 'google']));
    $state = session(OAuthMailboxController::SESSION_KEY)['state'];

    $state = $tamper($this, $state);

    $this->get(route('filament.admin.mailboxes.oauth-callback', ['provider' => 'google', 'state' => $state, 'code' => 'code-1']))->assertForbidden();
    expect(SmtpProvider::query()->where('type', ProviderType::Gmail)->exists())->toBeFalse();
    Http::assertNothingSent();
})->with([
    'wrong state' => [fn ($test, string $state): string => 'forged'],
    'another person' => [function ($test, string $state): string {
        $test->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

        return $state;
    }],
    'expired' => [function ($test, string $state): string {
        $test->travel(11)->minutes();

        return $state;
    }],
]);

it('only lets people who manage mailboxes connect one, and only when the app is configured', function () {
    $this->actingAs(User::factory()->sender()->create());
    $this->get(route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => 'google']))->assertForbidden();

    config(['services.microsoft.client_id' => null]);
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());
    $this->get(route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => 'microsoft']))->assertNotFound();
});

it('refreshes an expired token, and marks the mailbox disconnected when access was revoked', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::sequence()
        ->push(['access_token' => 'access-2', 'expires_in' => 3600])
        ->push(['error' => 'invalid_grant'], 400)]);
    $provider = oauthProvider(attributes: ['oauth_expires_at' => now()->subMinute()]);

    expect(app(OAuthClient::class)->accessToken($provider))->toBe('access-2')
        ->and($provider->refresh()->oauth_refresh_token)->toBe('refresh-1');

    $provider->forceFill(['oauth_expires_at' => now()->subMinute()])->save();

    expect(fn () => app(OAuthClient::class)->accessToken($provider))->toThrow(OAuthRevoked::class);
    expect($provider->refresh()->health_status)->toBe(ProviderHealth::Failing);
});

it('sends through the Gmail API with ProSend\'s own Message-ID', function () {
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'g1'])]);
    $identity = SenderIdentity::factory()->for(oauthProvider())->create(['email' => 'sales@acme.test']);
    $message = EmailMessage::factory()->for($identity)->create(['subject' => 'Hello', 'html_body' => '<p>Hi</p>']);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
    Http::assertSent(function (Request $request) use ($message): bool {
        $mime = base64_decode(strtr($request['raw'], '-_', '+/'));

        return $request->hasHeader('Authorization', 'Bearer access-1') && stripos($mime, "Message-ID: <{$message->provider_message_id}>") !== false;
    });
});

it('classifies Microsoft Graph failures', function (int $status, MessageStatus $expected) {
    Http::fake(['graph.microsoft.com/*' => Http::response(['error' => ['code' => 'x', 'message' => 'Nope']], $status)]);
    $identity = SenderIdentity::factory()->for(oauthProvider(ProviderType::Microsoft365))->create();
    $message = EmailMessage::factory()->for($identity)->create();

    app()->call([(new SendEmailMessage($message))->withFakeQueueInteractions(), 'handle']);

    expect($message->refresh()->status)->toBe($expected);
})->with([
    'throttled' => [429, MessageStatus::Deferred],
    'server error' => [503, MessageStatus::Deferred],
    'rejected' => [400, MessageStatus::Failed],
    'access revoked' => [401, MessageStatus::Failed],
]);

it('reads replies through the Gmail API into the inbox', function () {
    $provider = oauthProvider();
    $contact = Contact::factory()->consented()->create(['email' => 'jane@example.com']);
    EmailMessage::factory()->for($contact)->accepted()->create(['to_email' => 'jane@example.com', 'provider_message_id' => 'abc-123@acme.test', 'source' => MessageSource::Compose]);
    $mailbox = InboundMailbox::factory()->create(['type' => InboundMailbox::TYPE_GMAIL, 'host' => null, 'smtp_provider_id' => $provider->id]);
    $raw = "From: Jane <jane@example.com>\r\nTo: sales@acme.test\r\nSubject: Re: Hello\r\nMessage-ID: <r1@example.com>\r\nIn-Reply-To: <abc-123@acme.test>\r\nContent-Type: text/plain\r\n\r\nInterested.\r\n";
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/messages?*' => Http::response(['messages' => [['id' => 'm1']]]),
        'gmail.googleapis.com/gmail/v1/users/me/messages/m1*' => Http::response(['id' => 'm1', 'internalDate' => (string) now()->getTimestampMs(), 'raw' => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')]),
    ]);

    expect(app(InboxSynchronizer::class)->sync($mailbox))->toBe(['reply' => 1]);
    expect(Conversation::query()->sole()->contact_id)->toBe($contact->id)
        ->and($mailbox->refresh()->last_uid)->toBeGreaterThan(0);
});

it('retries later, instead of leaving the email in doubt, when the sign-in service cannot refresh the token', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response('Service unavailable', 503)]);
    $identity = SenderIdentity::factory()->for(oauthProvider(attributes: ['oauth_expires_at' => now()->subMinute()]))->create();
    $message = EmailMessage::factory()->for($identity)->create();

    app()->call([(new SendEmailMessage($message))->withFakeQueueInteractions(), 'handle']);

    expect($message->refresh()->status)->toBe(MessageStatus::Deferred);
    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'messages/send'));
});

it('keeps Bcc recipients when sending through the Gmail API', function () {
    Http::fake(['gmail.googleapis.com/gmail/v1/users/me/messages/send' => Http::response(['id' => 'g1'])]);
    $identity = SenderIdentity::factory()->for(oauthProvider())->create(['email' => 'sales@acme.test']);
    $message = EmailMessage::factory()->for($identity)->create(['cc' => ['cc@example.com'], 'bcc' => ['hidden@example.com']]);

    app()->call([new SendEmailMessage($message), 'handle']);

    expect($message->refresh()->status)->toBe(MessageStatus::Accepted);
    Http::assertSent(function (Request $request): bool {
        $mime = base64_decode(strtr($request['raw'], '-_', '+/'));

        return str_starts_with($mime, 'Bcc: hidden@example.com') && str_contains($mime, 'cc@example.com');
    });
});

it('reads a Gmail backlog oldest first across pages, and looks back a little before the last email read', function () {
    $provider = oauthProvider();
    $mailbox = InboundMailbox::factory()->create(['type' => InboundMailbox::TYPE_GMAIL, 'host' => null, 'smtp_provider_id' => $provider->id]);
    $raw = fn (string $id): string => rtrim(strtr(base64_encode("From: x@example.com\r\nTo: sales@acme.test\r\nSubject: Note\r\nMessage-ID: <{$id}@example.com>\r\n\r\nHi\r\n"), '+/', '-_'), '=');
    $read = [];
    Http::fake(function (Request $request) use ($raw, &$read) {
        if (preg_match('#/messages/(m\d)\?#', $request->url(), $match) === 1) {
            $read[] = $match[1];

            return Http::response(['id' => $match[1], 'internalDate' => (string) (1_700_000_000_000 + (int) substr($match[1], 1) * 1000), 'raw' => $raw($match[1])]);
        }

        // Newest first, two pages.
        return blank($request['pageToken'] ?? null)
            ? Http::response(['messages' => [['id' => 'm4'], ['id' => 'm3']], 'nextPageToken' => 'page-2'])
            : Http::response(['messages' => [['id' => 'm2'], ['id' => 'm1']]]);
    });

    app(InboxSynchronizer::class)->sync($mailbox);

    expect($read)->toBe(['m1', 'm2', 'm3', 'm4'])
        ->and($mailbox->refresh()->last_uid)->toBe((1_700_000_000_000 + 4000) * ApiMailboxReader::CURSOR_SCALE + 3);

    app(InboxSynchronizer::class)->sync($mailbox);

    Http::assertSent(fn (Request $request): bool => str_contains((string) ($request['q'] ?? ''), 'after:'.(1_700_000_004 - ApiMailboxReader::OVERLAP_MINUTES * 60)));
});

it('leaves an IMAP mailbox for the same address alone, and a switched-off provider off, when connecting again', function () {
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3599]),
        'gmail.googleapis.com/gmail/v1/users/me/profile' => Http::response(['emailAddress' => 'sales@acme.test']),
    ]);
    $provider = oauthProvider(attributes: ['is_enabled' => false]);
    $imap = InboundMailbox::factory()->create(['email' => 'sales@acme.test', 'type' => InboundMailbox::TYPE_IMAP]);
    $this->actingAs(User::factory()->withClientRole(SystemRole::Admin)->create());

    $this->get(route('filament.admin.mailboxes.connect', ['tenant' => Filament::getTenant(), 'provider' => 'google']));
    $state = session(OAuthMailboxController::SESSION_KEY)['state'];
    $this->get(route('filament.admin.mailboxes.oauth-callback', ['provider' => 'google', 'state' => $state, 'code' => 'code-1']))->assertRedirect();

    expect($provider->refresh())->oauth_access_token->toBe('access-2')->is_enabled->toBeFalse();
    // Unlinked, so removing the connection later cannot take the IMAP mailbox with it.
    expect($imap->refresh())->type->toBe(InboundMailbox::TYPE_IMAP)->smtp_provider_id->toBeNull();
});

it('does not fill the audit log with token refreshes', function () {
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 3600])]);
    $provider = oauthProvider(attributes: ['oauth_expires_at' => now()->subMinute()]);
    $before = AuditLog::query()->withoutGlobalScope(ClientScope::class)->count();

    app(OAuthClient::class)->accessToken($provider);

    expect(AuditLog::query()->withoutGlobalScope(ClientScope::class)->count())->toBe($before);
});
