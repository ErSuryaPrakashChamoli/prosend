<?php

namespace App\Actions\Api;

use App\Actions\Messages\QueueComposedEmails;
use App\Enums\ApiScope;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Enums\TemplateStatus;
use App\Exceptions\ApiException;
use App\Jobs\SendEmailMessage;
use App\Models\ApiKey;
use App\Models\ApiKeyDailyUsage;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use finfo;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

/**
 * Sends email for an API key through the same validation and pipeline as the panel's Compose page,
 * after the checks only an API key needs: allowed sender, marketing scope and daily send limit.
 */
class SendEmailsViaApi
{
    /**
     * The types libmagic may detect for each declared attachment type. It reports CSV as plain text,
     * and older versions report Word and Excel files as ZIP archives.
     *
     * @var array<string, list<string>>
     */
    private const DETECTED_TYPES = [
        'text/plain' => ['text/plain', 'text/csv', 'application/csv'],
        'text/csv' => ['text/csv', 'text/plain', 'application/csv'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    ];

    public function __construct(private QueueComposedEmails $queueComposedEmails) {}

    /**
     * Messages an earlier attempt of the same request already created, for example before the server stopped.
     * Any that never got a send job are queued again; the job's atomic claim makes a duplicate job harmless.
     *
     * @return EloquentCollection<int, EmailMessage>
     */
    public function recoverEarlierAttempt(ApiKey $apiKey, ?string $idempotencyKey): EloquentCollection
    {
        if ($idempotencyKey === null) {
            return new EloquentCollection;
        }

        $messages = EmailMessage::query()
            ->whereBelongsTo($apiKey)
            ->where('idempotency_key', $idempotencyKey)
            ->where('created_at', '>=', now()->subDay())
            ->with('senderIdentity')
            ->orderBy('id')
            ->get();

        $messages
            ->filter(fn (EmailMessage $message): bool => $message->status === MessageStatus::Queued)
            ->each(fn (EmailMessage $message) => SendEmailMessage::dispatch($message)->delay($message->scheduled_at?->isFuture() ? $message->scheduled_at : null));

        return $messages;
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
        $template = $this->template($input);

        // Every address gets its own copy, so CC and BCC count toward the daily limit too.
        $emails = $recipients->count() + count($input['cc'] ?? []) + count($input['bcc'] ?? []);

        if (! ApiKeyDailyUsage::reserveEmails($apiKey, $emails)) {
            throw new ApiException(429, 'daily_send_limit_reached', "This API key may send {$apiKey->daily_send_limit} emails per day, counting every To, CC and BCC address.", headers: ['Retry-After' => (int) now()->diffInSeconds(now()->addDay()->startOfDay(), true)]);
        }

        $attachments = ['attachments' => [], 'attachment_names' => []];

        try {
            $attachments = $this->storeAttachments($input['attachments'] ?? []);

            $messages = $this->queueComposedEmails->handle($this->composedEmail($input, $senderIdentity, $purpose, $template, $recipients, $attachments), null, [
                'source' => MessageSource::Api,
                'api_key_id' => $apiKey->getKey(),
                'idempotency_key' => $idempotencyKey,
                'tags' => $input['tags'] ?? [],
                'metadata' => array_map(fn (mixed $value): string => (string) $value, $input['metadata'] ?? []),
                'variables' => array_map(fn (mixed $value): string => (string) $value, array_filter($input['variables'] ?? [], fn (mixed $value): bool => $value !== null)),
                'to_names' => $recipients->filter()->all(),
                'simulate' => $apiKey->isTest(),
            ]);
        } catch (Throwable $exception) {
            ApiKeyDailyUsage::releaseEmails($apiKey, $emails);
            $this->deleteAttachments($attachments['attachments']);

            if ($exception instanceof ValidationException) {
                throw new ApiException(422, $this->errorCode($exception), $exception->validator->errors()->first(), $exception->errors());
            }

            throw $exception;
        }

        return $messages->each->load('senderIdentity');
    }

    /**
     * The send in the shape the panel's Compose page submits.
     *
     * @param  array<string, mixed>  $input
     * @param  Collection<string, ?string>  $recipients
     * @param  array{attachments: list<string>, attachment_names: array<string, string>}  $attachments
     * @return array<string, mixed>
     */
    private function composedEmail(array $input, SenderIdentity $senderIdentity, EmailPurpose $purpose, ?EmailTemplate $template, Collection $recipients, array $attachments): array
    {
        return [
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
            ...$attachments,
        ];
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
        $paths = [];
        $names = [];

        try {
            foreach ($attachments as $index => $attachment) {
                [$path, $name] = $this->storeAttachment($attachment, $index);
                $paths[] = $path;
                $names[$path] = $name;
            }
        } catch (Throwable $exception) {
            $this->deleteAttachments($paths);

            throw $exception;
        }

        return ['attachments' => $paths, 'attachment_names' => $names];
    }

    /**
     * The content must really be the declared type, as Compose checks uploads. The file name always ends in
     * an extension of that type, so a PDF named "invoice.exe" is sent as "invoice.exe.pdf" and opens as a PDF.
     *
     * @param  array{filename: string, content: string, content_type: string}  $attachment
     * @return array{0: string, 1: string} The stored path and the name the recipient sees.
     *
     * @throws ApiException
     */
    private function storeAttachment(array $attachment, int $index): array
    {
        $contents = base64_decode($attachment['content'], true);

        if ($contents === false) {
            throw new ApiException(422, 'validation_failed', 'An attachment is not valid base64.', ["attachments.{$index}.content" => ['Not valid base64.']]);
        }

        if (strlen($contents) > config('prosend.attachments.max_file_size_kb') * 1024) {
            throw new ApiException(422, 'validation_failed', 'An attachment is too large.', ["attachments.{$index}.content" => ['Larger than '.config('prosend.attachments.max_file_size_kb').' KB.']]);
        }

        $declaredType = $attachment['content_type'];
        $detectedType = (new finfo(FILEINFO_MIME_TYPE))->buffer($contents) ?: 'application/octet-stream';

        if (! in_array($detectedType, self::DETECTED_TYPES[$declaredType] ?? [$declaredType], true)) {
            throw new ApiException(422, 'validation_failed', "An attachment's content does not match its content_type.", ["attachments.{$index}.content" => ["The content is {$detectedType}, not {$declaredType}."]]);
        }

        $extensions = MimeTypes::getDefault()->getExtensions($declaredType) ?: ['bin'];
        $givenExtension = Str::lower(pathinfo($attachment['filename'], PATHINFO_EXTENSION));
        $extension = in_array($givenExtension, $extensions, true) ? $givenExtension : $extensions[0];
        $baseName = in_array($givenExtension, $extensions, true) ? pathinfo($attachment['filename'], PATHINFO_FILENAME) : $attachment['filename'];

        $path = config('prosend.attachments.directory').'/'.Str::uuid7().'-'.Str::slug(Str::limit($baseName, 100, '')).'.'.$extension;
        Storage::disk(config('prosend.attachments.disk'))->put($path, $contents);

        return [$path, "{$baseName}.{$extension}"];
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
