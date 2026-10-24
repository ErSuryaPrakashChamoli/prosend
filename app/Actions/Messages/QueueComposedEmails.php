<?php

namespace App\Actions\Messages;

use App\Enums\EmailEventType;
use App\Enums\EmailPurpose;
use App\Enums\MessageSource;
use App\Enums\MessageStatus;
use App\Jobs\SendEmailMessage;
use App\Models\Contact;
use App\Models\EmailMessage;
use App\Models\EmailTemplate;
use App\Models\SenderIdentity;
use App\Models\User;
use App\Services\Sending\RecipientEligibility;
use App\Services\Sending\TemplateVariables;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Queues a composed email. Each address in "To" receives its own message so recipients never see each other.
 */
class QueueComposedEmails
{
    public function __construct(
        private RecipientEligibility $eligibility,
        private TemplateVariables $templateVariables,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  array{source?: MessageSource, api_key_id?: int, idempotency_key?: string|null, tags?: list<string>, metadata?: array<string, string>, variables?: array<string, string>, to_names?: array<string, string>, simulate?: bool}  $context
     *                                                                                                                                                                                                                                                Extra details for sends that do not come from the panel. `simulate` records the messages without sending them.
     * @return Collection<int, EmailMessage>
     *
     * @throws ValidationException
     */
    public function handle(array $data, ?User $user, array $context = []): Collection
    {
        $validated = $this->validate($data, array_keys($context['variables'] ?? []));
        $purpose = EmailPurpose::from($validated['purpose']);
        $scheduledAt = filled($validated['scheduled_at'] ?? null) ? Carbon::parse($validated['scheduled_at']) : null;
        $contacts = Contact::query()->whereIn('email', $validated['to'])->get()->keyBy('email');
        $simulate = $context['simulate'] ?? false;

        $messages = DB::transaction(fn (): Collection => collect($validated['to'])->map(function (string $email) use ($validated, $purpose, $scheduledAt, $contacts, $user, $context, $simulate): EmailMessage {
            $contact = $contacts->get($email);

            $message = EmailMessage::query()->create([
                'contact_id' => $contact?->getKey(),
                'sender_identity_id' => $validated['sender_identity_id'],
                'created_by' => $user?->getKey(),
                'api_key_id' => $context['api_key_id'] ?? null,
                'idempotency_key' => $context['idempotency_key'] ?? null,
                'purpose' => $purpose,
                'source' => $context['source'] ?? MessageSource::Compose,
                'status' => $simulate ? MessageStatus::Simulated : MessageStatus::Queued,
                'to_email' => $email,
                'to_name' => $context['to_names'][$email] ?? $contact?->full_name,
                'cc' => $validated['cc'] ?: null,
                'bcc' => $validated['bcc'] ?: null,
                'reply_to' => $validated['reply_to'] ?? null,
                'subject' => $validated['subject'],
                'html_body' => $validated['body'],
                'text_body' => $validated['text_body'],
                'email_template_id' => $validated['email_template_id'],
                'attachments' => $validated['attachments'] ?: null,
                'tags' => ($context['tags'] ?? []) ?: null,
                'metadata' => ($context['metadata'] ?? []) ?: null,
                'variables' => ($context['variables'] ?? []) ?: null,
                'scheduled_at' => $scheduledAt,
                'queued_at' => now(),
            ]);

            $message->recordEvent(EmailEventType::Queued, details: array_filter([
                'scheduled_for' => $scheduledAt?->toIso8601String(),
                'reason' => $simulate ? 'Test API key: recorded, never sent' : null,
            ]));

            return $message;
        }));

        if (! $simulate) {
            $messages->each(fn (EmailMessage $message) => SendEmailMessage::dispatch($message)->delay($scheduledAt));
        }

        return $messages;
    }

    /**
     * Validate and normalize the composed email. Addresses are lowercased and attachments resolved.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $providedVariables  Variables supplied with the send, which count as present for every recipient.
     * @return array{sender_identity_id: int, purpose: string, email_template_id: ?int, to: list<string>, cc: list<string>, bcc: list<string>, reply_to: ?string, subject: string, body: string, text_body: ?string, attachments: list<array{path: string, name: string}>, scheduled_at: ?string}
     *
     * @throws ValidationException
     */
    public function validate(array $data, array $providedVariables = []): array
    {
        foreach (['to', 'cc', 'bcc'] as $field) {
            $data[$field] = $this->normalizeAddresses($data[$field] ?? []);
        }

        $validator = Validator::make($data, [
            'sender_identity_id' => ['required', 'integer', Rule::exists(SenderIdentity::class, 'id')],
            'purpose' => ['required', Rule::enum(EmailPurpose::class)],
            'email_template_id' => ['nullable', 'integer', Rule::exists(EmailTemplate::class, 'id')],
            'to' => ['required', 'array', 'min:1', 'max:'.config('prosend.compose.max_recipients')],
            'to.*' => ['email:rfc', 'distinct'],
            'cc' => ['array', 'max:10'],
            'cc.*' => ['email:rfc', 'distinct'],
            'bcc' => ['array', 'max:10'],
            'bcc.*' => ['email:rfc', 'distinct'],
            'reply_to' => ['nullable', 'email:rfc', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:500000'],
            'text_body' => ['nullable', 'string', 'max:500000'],
            'attachments' => ['array', 'max:'.config('prosend.attachments.max_files')],
            'attachments.*' => ['string'],
            'attachment_names' => ['array'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ], [
            'to.required' => 'Add at least one recipient.',
            'to.max' => 'You can send to at most :max recipients at once. Use a campaign for larger audiences.',
            'scheduled_at.after' => 'The scheduled time must be in the future.',
        ]);

        $validator->after(fn (ValidatorInstance $validator) => $this->validateRecipients($validator, $data, $providedVariables));

        $validated = $validator->validate();

        return [
            'sender_identity_id' => (int) $validated['sender_identity_id'],
            'purpose' => $validated['purpose'] instanceof EmailPurpose ? $validated['purpose']->value : $validated['purpose'],
            'email_template_id' => isset($validated['email_template_id']) ? (int) $validated['email_template_id'] : null,
            'to' => $validated['to'],
            'cc' => $validated['cc'] ?? [],
            'bcc' => $validated['bcc'] ?? [],
            'reply_to' => $validated['reply_to'] ?? null,
            'subject' => $validated['subject'],
            'body' => $validated['body'],
            'text_body' => $validated['text_body'] ?? null,
            'attachments' => $this->resolveAttachments($validated['attachments'] ?? [], $validated['attachment_names'] ?? []),
            'scheduled_at' => $validated['scheduled_at'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $providedVariables
     */
    private function validateRecipients(ValidatorInstance $validator, array $data, array $providedVariables): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $to = $data['to'];
        $copies = [...$data['cc'], ...$data['bcc']];

        if ($copies !== [] && count($to) > 1) {
            $validator->errors()->add('cc', 'CC and BCC can only be used when sending to one recipient, because each person in "To" receives a separate email.');
        }

        $duplicates = array_keys(array_filter(array_count_values([...$to, ...$copies]), fn (int $count): bool => $count > 1));

        if ($duplicates !== []) {
            $validator->errors()->add('to', 'These addresses appear more than once: '.implode(', ', $duplicates).'.');
        }

        $senderIdentity = SenderIdentity::query()->with('smtpProvider')->find($data['sender_identity_id']);

        if ($senderIdentity && ! $senderIdentity->canSend()) {
            $validator->errors()->add('sender_identity_id', 'This sender must be verified with the provider before it can send.');
        } elseif ($senderIdentity && ! $senderIdentity->smtpProvider->is_enabled) {
            $validator->errors()->add('sender_identity_id', "The provider {$senderIdentity->smtpProvider->name} is disabled.");
        }

        $purpose = EmailPurpose::from($data['purpose'] instanceof EmailPurpose ? $data['purpose']->value : $data['purpose']);

        foreach (['to' => $to, 'cc' => $data['cc'], 'bcc' => $data['bcc']] as $field => $addresses) {
            $blocked = collect($addresses)
                ->map(fn (string $email): ?string => ($reason = $this->eligibility->blockReason($email, $purpose)) ? "{$email} ({$reason})" : null)
                ->filter();

            if ($blocked->isNotEmpty()) {
                $validator->errors()->add($field, 'These recipients cannot receive this email: '.$blocked->implode(', ').'.');
            }
        }

        $this->validateTemplate($validator, $data, $purpose, $providedVariables);

        foreach ($data['attachments'] ?? [] as $path) {
            if (! $this->isStoredAttachment((string) $path)) {
                $validator->errors()->add('attachments', 'An attachment could not be found. Upload it again.');

                break;
            }
        }
    }

    /**
     * A template must match the email type, and every recipient needs a value for each variable it requires.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $providedVariables
     */
    private function validateTemplate(ValidatorInstance $validator, array $data, EmailPurpose $purpose, array $providedVariables): void
    {
        $template = filled($data['email_template_id'] ?? null) ? EmailTemplate::query()->find($data['email_template_id']) : null;

        if ($template === null) {
            return;
        }

        if ($template->purpose !== $purpose) {
            $validator->errors()->add('email_template_id', "This is a {$template->purpose->getLabel()} template and cannot be used for {$purpose->getLabel()} email.");

            return;
        }

        $contacts = Contact::query()->whereIn('email', $data['to'])->get()->keyBy('email');

        $incomplete = collect($data['to'])
            ->map(function (string $email) use ($contacts, $template, $providedVariables): ?string {
                $missing = array_values(array_diff($this->templateVariables->missingFor($contacts->get($email), $template->required_variables ?? []), $providedVariables));

                return $missing === [] ? null : "{$email} (missing ".implode(', ', $missing).')';
            })
            ->filter();

        if ($incomplete->isNotEmpty()) {
            $validator->errors()->add('to', 'These recipients are missing values the template requires: '.$incomplete->implode(', ').'.');
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeAddresses(mixed $addresses): array
    {
        return collect(is_array($addresses) ? $addresses : [$addresses])
            ->filter(fn (mixed $email): bool => is_string($email) && trim($email) !== '')
            ->map(fn (string $email): string => Str::lower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Only files uploaded into the attachments directory may be attached, which prevents a tampered
     * form state from attaching arbitrary files from the server.
     */
    private function isStoredAttachment(string $path): bool
    {
        $directory = config('prosend.attachments.directory').'/';

        return str_starts_with($path, $directory)
            && ! str_contains($path, '..')
            && Storage::disk(config('prosend.attachments.disk'))->exists($path);
    }

    /**
     * @param  list<string>  $paths
     * @param  array<string, string>  $names
     * @return list<array{path: string, name: string}>
     */
    private function resolveAttachments(array $paths, array $names): array
    {
        return array_values(array_map(
            fn (string $path): array => ['path' => $path, 'name' => (string) ($names[$path] ?? basename($path))],
            $paths,
        ));
    }
}
