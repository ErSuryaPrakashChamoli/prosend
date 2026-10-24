<?php

namespace App\Actions\Api;

use App\Actions\Messages\QueueComposedEmails;
use App\Enums\ApiScope;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\TemplateStatus;
use App\Exceptions\ApiException;
use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Sends email for an API key through the same validation and pipeline as the panel's Compose page,
 * after the checks only an API key needs: allowed sender, marketing scope and daily send limit.
 */
class SendEmailsViaApi
{
    public function __construct(private QueueComposedEmails $queueComposedEmails) {}

    /**
     * Messages already created for this key and idempotency key, when an earlier attempt of the same request got that far.
     *
     * @return EloquentCollection<int, EmailMessage>
     */
    public function previouslyQueued(ApiKey $apiKey, ?string $idempotencyKey): EloquentCollection
    {
        if ($idempotencyKey === null) {
            return new EloquentCollection;
        }

        return EmailMessage::query()
            ->whereBelongsTo($apiKey)
            ->where('idempotency_key', $idempotencyKey)
            ->where('created_at', '>=', now()->subDay())
            ->with('senderIdentity')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $input  Validated request data.
     * @return Collection<int, EmailMessage>
     *
     * @throws ApiException
     */
    public function handle(array $input, ApiKey $apiKey, ?string $idempotencyKey): Collection
    {
        $purpose = EmailPurpose::from($input['purpose']);

        if ($purpose === EmailPurpose::Marketing && ! $apiKey->hasScope(ApiScope::EmailsSendMarketing)) {
            throw ApiException::forbidden('missing_scope', 'This API key needs the emails:send_marketing scope to send marketing email.');
        }

        $senderIdentity = SenderIdentity::query()->where('email', Str::lower($input['from']))->first();

        if ($senderIdentity === null || ! $apiKey->allowsSender($senderIdentity)) {
            throw ApiException::forbidden('sender_not_allowed', "This API key may not send from {$input['from']}.");
        }

        $recipients = collect($input['to'])->mapWithKeys(fn (array $to): array => [Str::lower(trim($to['email'])) => $to['name'] ?? null]);

        if ($apiKey->daily_send_limit !== null && $apiKey->emailsQueuedToday() + $recipients->count() > $apiKey->daily_send_limit) {
            throw new ApiException(429, 'daily_send_limit_reached', "This API key may queue {$apiKey->daily_send_limit} emails per day.", headers: ['Retry-After' => (int) now()->diffInSeconds(now()->addDay()->startOfDay(), true)]);
        }

        $template = $this->template($input);

        $data = [
            'sender_identity_id' => $senderIdentity->getKey(),
            'purpose' => $purpose->value,
            'email_template_id' => $template?->getKey(),
            'to' => $recipients->keys()->all(),
            'cc' => $input['cc'] ?? [],
            'bcc' => $input['bcc'] ?? [],
            'reply_to' => $input['reply_to'] ?? null,
            'subject' => $input['subject'] ?? $template?->subject,
            'body' => $input['html'] ?? $template?->body,
            'text_body' => $input['text'] ?? $template?->text_body,
            'scheduled_at' => $input['scheduled_at'] ?? null,
            ...$this->storeAttachments($input['attachments'] ?? []),
        ];

        try {
            $messages = $this->queueComposedEmails->handle($data, null, [
                'source' => MessageSource::Api,
                'api_key_id' => $apiKey->getKey(),
                'idempotency_key' => $idempotencyKey,
                'tags' => $input['tags'] ?? [],
                'metadata' => array_map(fn (mixed $value): string => (string) $value, $input['metadata'] ?? []),
                'variables' => array_map(fn (mixed $value): string => (string) $value, array_filter($input['variables'] ?? [], fn (mixed $value): bool => $value !== null)),
                'to_names' => $recipients->filter()->all(),
                'simulate' => $apiKey->isTest(),
            ]);
        } catch (ValidationException $exception) {
            $this->deleteAttachments($data['attachments']);

            throw new ApiException(422, $this->errorCode($exception), $exception->validator->errors()->first(), $exception->errors());
        }

        ApiKeyDailyUsage::add($apiKey, ['emails_queued' => $messages->count()]);

        return $messages->each->load('senderIdentity');
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ApiException
     */
    private function template(array $input): ?EmailTemplate
    {
        if (blank($input['template_id'] ?? null)) {
            return null;
        }

        $template = EmailTemplate::query()->find($input['template_id']);

        if ($template === null || $template->status !== TemplateStatus::Active) {
            throw new ApiException(422, 'template_not_available', 'The template does not exist or is not active.', ['template_id' => ['The template does not exist or is not active.']]);
        }

        return $template;
    }

    /**
     * Attachments are stored like Compose uploads, then attached when the email is sent.
     *
     * @param  list<array{filename: string, content: string, content_type: string}>  $attachments
     * @return array{attachments: list<string>, attachment_names: array<string, string>}
     *
     * @throws ApiException
     */
    private function storeAttachments(array $attachments): array
    {
        $disk = Storage::disk(config('prosend.attachments.disk'));
        $paths = [];
        $names = [];

        foreach ($attachments as $index => $attachment) {
            $contents = base64_decode($attachment['content'], true);

            if ($contents === false) {
                $this->deleteAttachments($paths);

                throw new ApiException(422, 'validation_failed', 'An attachment is not valid base64.', ["attachments.{$index}.content" => ['Not valid base64.']]);
            }

            if (strlen($contents) > config('prosend.attachments.max_file_size_kb') * 1024) {
                $this->deleteAttachments($paths);

                throw new ApiException(422, 'validation_failed', 'An attachment is too large.', ["attachments.{$index}.content" => ['Larger than '.config('prosend.attachments.max_file_size_kb').' KB.']]);
            }

            $path = config('prosend.attachments.directory').'/'.Str::uuid7().'-'.Str::slug(pathinfo($attachment['filename'], PATHINFO_FILENAME)).'.'.Str::lower(pathinfo($attachment['filename'], PATHINFO_EXTENSION) ?: 'bin');
            $disk->put($path, $contents);
            $paths[] = $path;
            $names[$path] = $attachment['filename'];
        }

        return ['attachments' => $paths, 'attachment_names' => $names];
    }

    /**
     * @param  list<string>|list<array{path: string, name: string}>  $attachments
     */
    private function deleteAttachments(array $attachments): void
    {
        $paths = array_map(fn (string|array $attachment): string => is_array($attachment) ? $attachment['path'] : $attachment, $attachments);

        if ($paths !== []) {
            Storage::disk(config('prosend.attachments.disk'))->delete($paths);
        }
    }

    private function errorCode(ValidationException $exception): string
    {
        $errors = $exception->validator->errors();

        foreach (['to', 'cc', 'bcc'] as $field) {
            if (str_contains((string) $errors->first($field), 'cannot receive this email')) {
                return 'recipient_not_eligible';
            }
        }

        return $errors->has('to') && str_contains((string) $errors->first('to'), 'missing values the template requires')
            ? 'template_variables_missing'
            : 'validation_failed';
    }
}
