<?php

namespace App\Services\Inbox;

use App\Enums\ProviderType;
use App\Models\InboundMailbox;
use App\Models\SmtpProvider;
use App\Services\Inbox\Contracts\MailboxReader;
use App\Services\Providers\OAuth\OAuthClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads a Gmail or Microsoft 365 inbox through its API, as raw MIME, through the mailbox's OAuth connection. Nothing is
 * marked as read.
 *
 * The cursor is the arrival time of the last email processed, in milliseconds times CURSOR_SCALE (to keep emails that
 * arrived in the same millisecond apart). Emails are always read oldest first, so the cursor never moves past one that
 * was not read yet. Each sync looks back OVERLAP_MINUTES before the cursor, because a search index can list an email
 * a little after it arrived; emails read twice are recognised by their Message-ID and change nothing.
 */
class ApiMailboxReader implements MailboxReader
{
    public const FIRST_SYNC_DAYS = 14;

    public const CURSOR_SCALE = 1000;

    public const OVERLAP_MINUTES = 2;

    /**
     * Gmail lists newest first, so every email since the cursor is listed to find the oldest. A backlog beyond this many
     * emails is read from the newest MAX_LISTED.
     */
    public const MAX_LISTED = 10_000;

    public function __construct(private OAuthClient $oauth) {}

    public function fetchNew(InboundMailbox $mailbox, int $limit): MailboxBatch
    {
        $provider = $this->provider($mailbox);
        $token = $this->oauth->accessToken($provider);
        $since = $mailbox->last_uid !== null
            ? Carbon::createFromTimestampMs(intdiv($mailbox->last_uid, self::CURSOR_SCALE))->subMinutes(self::OVERLAP_MINUTES)
            : now()->subDays(self::FIRST_SYNC_DAYS);

        $raw = $provider->type === ProviderType::Gmail
            ? $this->fromGmail($token, $since, $limit)
            : $this->fromGraph($token, $since, $limit);

        return new MailboxBatch($raw, 1);
    }

    public function testConnection(InboundMailbox $mailbox): void
    {
        $provider = $this->provider($mailbox);
        $this->oauth->accountEmail($provider->type, $this->oauth->accessToken($provider));
    }

    /**
     * @return array<int, string>
     */
    private function fromGmail(string $token, Carbon $since, int $limit): array
    {
        $ids = [];
        $pageToken = null;

        do {
            $list = $this->ok(Http::withToken($token)->timeout(20)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages', array_filter([
                'q' => 'in:inbox after:'.$since->getTimestamp(),
                'maxResults' => 500,
                'pageToken' => $pageToken,
            ])));

            foreach ((array) $list->json('messages', []) as $item) {
                $ids[] = (string) $item['id'];
            }

            $pageToken = $list->json('nextPageToken');
        } while (filled($pageToken) && count($ids) < self::MAX_LISTED);

        $raw = [];

        foreach (array_slice(array_reverse($ids), 0, $limit) as $index => $id) {
            $message = $this->ok(Http::withToken($token)->timeout(20)->get('https://gmail.googleapis.com/gmail/v1/users/me/messages/'.rawurlencode($id), ['format' => 'raw']));
            $raw[(int) $message->json('internalDate') * self::CURSOR_SCALE + $index] = (string) base64_decode(strtr((string) $message->json('raw'), '-_', '+/'));
        }

        return $raw;
    }

    /**
     * @return array<int, string>
     */
    private function fromGraph(string $token, Carbon $since, int $limit): array
    {
        $list = $this->ok(Http::withToken($token)->timeout(20)->get('https://graph.microsoft.com/v1.0/me/mailFolders/inbox/messages', [
            '$filter' => 'receivedDateTime ge '.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            '$orderby' => 'receivedDateTime asc',
            '$select' => 'id,receivedDateTime',
            '$top' => $limit,
        ]));

        $raw = [];

        foreach (array_slice((array) $list->json('value', []), 0, $limit) as $index => $item) {
            $mime = $this->ok(Http::withToken($token)->timeout(20)->get('https://graph.microsoft.com/v1.0/me/messages/'.rawurlencode((string) $item['id']).'/$value'));
            $raw[Carbon::parse((string) $item['receivedDateTime'])->getTimestampMs() * self::CURSOR_SCALE + $index] = $mime->body();
        }

        return $raw;
    }

    private function provider(InboundMailbox $mailbox): SmtpProvider
    {
        $provider = $mailbox->smtp_provider_id === null ? null : SmtpProvider::acrossClients()->where('client_id', $mailbox->client_id)->find($mailbox->smtp_provider_id);

        if ($provider === null || ! $provider->type->usesOAuth()) {
            throw new RuntimeException('This mailbox is not connected to a Gmail or Microsoft 365 account.');
        }

        return $provider;
    }

    private function ok(Response $response): Response
    {
        if (! $response->successful()) {
            throw new RuntimeException("The mailbox API answered {$response->status()}.");
        }

        return $response;
    }
}
