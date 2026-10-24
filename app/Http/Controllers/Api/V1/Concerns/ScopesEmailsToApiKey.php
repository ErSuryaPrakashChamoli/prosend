<?php

namespace App\Http\Controllers\Api\V1\Concerns;

use App\Enums\ApiScope;
use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Builder;

/**
 * A key sees only the email it sent, unless it has the emails:read_all scope.
 */
trait ScopesEmailsToApiKey
{
    /**
     * @return Builder<EmailMessage>
     */
    protected function emailsVisibleTo(ApiKey $apiKey): Builder
    {
        return EmailMessage::query()->when(
            ! $apiKey->hasScope(ApiScope::EmailsReadAll),
            fn (Builder $query) => $query->whereBelongsTo($apiKey),
        );
    }

    /**
     * Another key's email answers 404, never 403, so a key cannot learn which ids exist.
     */
    protected function ensureEmailVisibleTo(ApiKey $apiKey, EmailMessage $email): void
    {
        if (! $apiKey->hasScope(ApiScope::EmailsReadAll) && $email->api_key_id !== $apiKey->getKey()) {
            throw ApiException::notFound();
        }
    }
}
