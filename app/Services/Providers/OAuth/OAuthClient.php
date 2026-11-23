<?php

namespace App\Services\Providers\OAuth;

use App\Enums\ProviderType;
use App\Models\SmtpProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * OAuth 2.0 (authorization code with PKCE) for Gmail and Microsoft 365 mailboxes, with plain HTTP calls. Tokens are
 * stored encrypted on the provider and refreshed shortly before they expire; when the account revokes access, the
 * provider is marked as failing so someone reconnects it.
 */
class OAuthClient
{
    public function isConfigured(ProviderType $type): bool
    {
        return filled($this->config($type)['client_id'] ?? null) && filled($this->config($type)['client_secret'] ?? null);
    }

    public function authorizationUrl(ProviderType $type, string $state, string $codeVerifier): string
    {
        $query = [
            'client_id' => $this->config($type)['client_id'],
            'redirect_uri' => $this->redirectUri($type),
            'response_type' => 'code',
            'scope' => implode(' ', $this->scopes($type)),
            'state' => $state,
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 'S256',
        ];

        if ($type === ProviderType::Gmail) {
            // A refresh token is only issued with offline access, and only when consent is asked again.
            $query += ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true'];
        }

        return $this->endpoint($type, 'authorize').'?'.http_build_query($query);
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    public function exchangeCode(ProviderType $type, string $code, string $codeVerifier): array
    {
        return $this->tokenRequest($type, [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->redirectUri($type),
            'code_verifier' => $codeVerifier,
        ]);
    }

    /**
     * The address of the account that signed in.
     */
    public function accountEmail(ProviderType $type, string $accessToken): string
    {
        $response = Http::withToken($accessToken)->timeout(15)->get(match ($type) {
            ProviderType::Gmail => 'https://gmail.googleapis.com/gmail/v1/users/me/profile',
            default => 'https://graph.microsoft.com/v1.0/me?$select=mail,userPrincipalName',
        });

        if (! $response->successful()) {
            throw new RuntimeException("Could not read the connected account ({$response->status()}).");
        }

        $email = $type === ProviderType::Gmail
            ? $response->json('emailAddress')
            : ($response->json('mail') ?: $response->json('userPrincipalName'));

        return Str::lower(trim((string) $email));
    }

    /**
     * A valid access token for the provider, refreshed first when it expires within a minute.
     */
    public function accessToken(SmtpProvider $provider): string
    {
        if (filled($provider->oauth_access_token) && $provider->oauth_expires_at?->isAfter(now()->addMinute())) {
            return (string) $provider->oauth_access_token;
        }

        if (blank($provider->oauth_refresh_token)) {
            $this->markDisconnected($provider, 'The connection has no refresh token. Connect the mailbox again.');
        }

        try {
            $tokens = $this->tokenRequest($provider->type, [
                'grant_type' => 'refresh_token',
                'refresh_token' => (string) $provider->oauth_refresh_token,
            ]);
        } catch (OAuthRevoked $exception) {
            $this->markDisconnected($provider, $exception->getMessage());
        }

        $provider->forceFill([
            'oauth_access_token' => $tokens['access_token'],
            // Microsoft rotates refresh tokens; Google keeps the old one.
            'oauth_refresh_token' => $tokens['refresh_token'] ?? $provider->oauth_refresh_token,
            'oauth_expires_at' => now()->addSeconds(max(60, $tokens['expires_in'])),
        ])->save();

        return $tokens['access_token'];
    }

    public function redirectUri(ProviderType $type): string
    {
        return url('/admin/oauth/'.($type === ProviderType::Gmail ? 'google' : 'microsoft').'/callback');
    }

    /**
     * @return list<string>
     */
    public function scopes(ProviderType $type): array
    {
        return match ($type) {
            ProviderType::Gmail => ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.readonly'],
            default => ['openid', 'email', 'offline_access', 'User.Read', 'Mail.Send', 'Mail.Read'],
        };
    }

    /**
     * @param  array<string, string>  $parameters
     * @return array{access_token: string, refresh_token: string|null, expires_in: int}
     */
    private function tokenRequest(ProviderType $type, array $parameters): array
    {
        $response = Http::asForm()->timeout(15)->post($this->endpoint($type, 'token'), [
            'client_id' => $this->config($type)['client_id'],
            'client_secret' => $this->config($type)['client_secret'],
            ...$parameters,
        ]);

        if ($response->json('error') === 'invalid_grant') {
            throw new OAuthRevoked('The account no longer allows ProSend access (it was revoked or the password changed). Connect the mailbox again.');
        }

        if (! $response->successful() || blank($response->json('access_token'))) {
            throw new RuntimeException('The sign-in service refused the request: '.Str::limit((string) ($response->json('error_description') ?? $response->json('error') ?? $response->status()), 200));
        }

        return [
            'access_token' => (string) $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => (int) $response->json('expires_in', 3600),
        ];
    }

    private function markDisconnected(SmtpProvider $provider, string $reason): never
    {
        $provider->recordConnectionFailure($reason);

        throw new OAuthRevoked($reason);
    }

    private function endpoint(ProviderType $type, string $kind): string
    {
        if ($type === ProviderType::Gmail) {
            return $kind === 'authorize' ? 'https://accounts.google.com/o/oauth2/v2/auth' : 'https://oauth2.googleapis.com/token';
        }

        $tenant = rawurlencode((string) ($this->config($type)['tenant'] ?? 'common'));

        return "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/{$kind}";
    }

    /**
     * @return array<string, string|null>
     */
    private function config(ProviderType $type): array
    {
        return (array) config($type === ProviderType::Gmail ? 'services.google' : 'services.microsoft');
    }
}
