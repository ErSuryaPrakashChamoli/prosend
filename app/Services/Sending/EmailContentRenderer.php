<?php

namespace App\Services\Sending;

use App\Enums\EmailPurpose;
use App\Models\EmailMessage;
use Filament\Forms\Components\RichEditor\RichContentRenderer;

/**
 * Renders user-authored content safely: the HTML is sanitized and merge tag values are
 * always treated as text, so neither recipients' data nor templates can inject markup or code.
 */
class EmailContentRenderer
{
    /**
     * Merge tags available in subjects and bodies.
     *
     * @var array<string, string>
     */
    public const MERGE_TAGS = [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'company_name' => 'Company',
        'email' => 'Email address',
        'unsubscribe_url' => 'Unsubscribe link',
    ];

    public function render(EmailMessage $message): RenderedEmail
    {
        $unsubscribeUrl = $message->purpose === EmailPurpose::Marketing ? $message->unsubscribeUrl() : null;

        $campaign = $message->campaign;

        return $this->renderContent(
            subject: $message->subject,
            body: $campaign?->body ?? $message->html_body ?? '',
            variables: $this->variablesFor($message, $unsubscribeUrl),
            unsubscribeUrl: $unsubscribeUrl,
            textBody: $campaign ? $campaign->text_body : $message->text_body,
        );
    }

    /**
     * @param  string|array<string, mixed>  $body  HTML, or the editor's unsaved JSON document.
     * @param  array<string, string>  $variables
     * @param  string|null  $textBody  A hand-written plain-text version; generated from the HTML when empty.
     * @param  string|null  $missingValue  What a variable without a value becomes; null shows it as [name], for previews.
     */
    public function renderContent(string $subject, string|array $body, array $variables, ?string $unsubscribeUrl = null, ?string $textBody = null, ?string $missingValue = ''): RenderedEmail
    {
        // Typed placeholders are filled before sanitizing, so a value placed in a link or attribute
        // (such as a "javascript:" URL from contact data) is still removed by the sanitizer.
        $source = $this->replacePlaceholders(
            is_array($body) ? RichContentRenderer::make($body)->toUnsafeHtml() : $body,
            $variables,
            escape: true,
            missingValue: $missingValue,
        );

        $html = RichContentRenderer::make($source)->mergeTags($variables)->toHtml();

        // The plain-text part is never interpreted as HTML, so entities from the editor are decoded.
        $text = filled($textBody)
            ? $this->replacePlaceholders($textBody, $variables, escape: false, missingValue: $missingValue)
            : html_entity_decode(RichContentRenderer::make($source)->mergeTags($variables)->toText(), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return new RenderedEmail(
            subject: $this->replacePlaceholders($subject, $variables, escape: false, missingValue: $missingValue),
            html: $html,
            text: $text,
            unsubscribeUrl: $unsubscribeUrl,
        );
    }

    /**
     * Sample values used for previews and test emails.
     *
     * @return array<string, string>
     */
    public function sampleVariables(string $email): array
    {
        return [
            'first_name' => 'Alex',
            'last_name' => 'Taylor',
            'company_name' => 'Example Ltd',
            'email' => $email,
            'unsubscribe_url' => url('/unsubscribe/preview'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function variablesFor(EmailMessage $message, ?string $unsubscribeUrl): array
    {
        $contact = $message->contact;
        $nameParts = explode(' ', trim((string) $message->to_name), 2);

        $customAttributes = collect($contact?->custom_attributes ?? [])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && preg_match(TemplateVariables::KEY_PATTERN, $key) === 1 && is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->all();

        // Per-send variables (from the API) override the contact's attributes, but never email or unsubscribe_url.
        $sendVariables = collect($message->variables ?? [])
            ->filter(fn (mixed $value, mixed $key): bool => is_string($key) && preg_match(TemplateVariables::KEY_PATTERN, $key) === 1 && is_scalar($value))
            ->map(fn (mixed $value): string => (string) $value)
            ->except(TemplateVariables::ALWAYS_AVAILABLE)
            ->all();

        return [
            ...$customAttributes,
            ...$sendVariables,
            'first_name' => $sendVariables['first_name'] ?? (string) ($contact ? $contact->first_name : $nameParts[0]),
            'last_name' => $sendVariables['last_name'] ?? (string) ($contact ? $contact->last_name : ($nameParts[1] ?? '')),
            'company_name' => $sendVariables['company_name'] ?? (string) $contact?->company,
            'email' => $message->to_email,
            'unsubscribe_url' => (string) $unsubscribeUrl,
        ];
    }

    /**
     * Replace {{ tag }} placeholders typed as plain text, such as in subjects or pasted content.
     *
     * @param  array<string, string>  $variables
     */
    private function replacePlaceholders(string $content, array $variables, bool $escape, ?string $missingValue): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/',
            function (array $match) use ($variables, $escape, $missingValue): string {
                $value = $variables[$match[1]] ?? $missingValue ?? "[{$match[1]}]";

                return $escape ? e($value) : $value;
            },
            $content,
        ) ?? $content;
    }
}
