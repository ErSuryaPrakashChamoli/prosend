<?php

namespace App\Services\Providers\Brevo;

use App\Models\SmtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The parts of the Brevo API (v3) that ProSend uses. The API key is separate from the SMTP key.
 *
 * @see https://developers.brevo.com/reference/get-domain-configuration
 * @see https://developers.brevo.com/reference/get-senders
 */
class BrevoApiClient
{
    private const BASE_URL = 'https://api.brevo.com/v3';

    /**
     * A domain's verification and authentication state, or null when the domain is not added in Brevo.
     *
     * @return array{domain: string, verified: bool, authenticated: bool, dns_records: array<string, array{host_name?: string, type?: string, value?: string, status?: bool}>}|null
     *
     * @throws BrevoApiException
     */
    public function domain(SmtpProvider $provider, string $domain): ?array
    {
        $response = $this->send($provider, 'senders/domains/'.rawurlencode($domain));

        if ($response->notFound()) {
            return null;
        }

        $this->ensureSuccessful($response);

        return [
            'domain' => (string) $response->json('domain', $domain),
            'verified' => (bool) $response->json('verified'),
            'authenticated' => (bool) $response->json('authenticated'),
            'dns_records' => (array) $response->json('dns_records', []),
        ];
    }

    /**
     * Senders registered in Brevo, optionally only those on a domain.
     *
     * @return list<array{id: int, name: string, email: string, active: bool}>
     *
     * @throws BrevoApiException
     */
    public function senders(SmtpProvider $provider, ?string $domain = null): array
    {
        $response = $this->send($provider, 'senders', array_filter(['domain' => $domain]));

        $this->ensureSuccessful($response);

        return array_values(array_map(fn (array $sender): array => [
            'id' => (int) ($sender['id'] ?? 0),
            'name' => (string) ($sender['name'] ?? ''),
            'email' => (string) ($sender['email'] ?? ''),
            'active' => (bool) ($sender['active'] ?? false),
        ], (array) $response->json('senders', [])));
    }

    /**
     * @param  array<string, string>  $query
     *
     * @throws BrevoApiException
     */
    private function send(SmtpProvider $provider, string $path, array $query = []): Response
    {
        if (blank($provider->api_key)) {
            throw new BrevoApiException("Add a Brevo API key to {$provider->name} to check this with Brevo.");
        }

        try {
            return $this->request($provider)->get($path, $query);
        } catch (ConnectionException) {
            throw new BrevoApiException('Brevo could not be reached. Try again in a moment.');
        }
    }

    private function request(SmtpProvider $provider): PendingRequest
    {
        return Http::baseUrl(self::BASE_URL)
            ->withHeaders(['api-key' => $provider->api_key])
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15);
    }

    /**
     * @throws BrevoApiException
     */
    private function ensureSuccessful(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        throw new BrevoApiException(match ($response->status()) {
            401, 403 => 'Brevo rejected the API key. Check it under Infrastructure > SMTP providers.',
            429 => 'Brevo is rate limiting API requests. Try again in a minute.',
            default => 'Brevo returned an error: '.($response->json('message') ?? 'HTTP '.$response->status()),
        });
    }
}
