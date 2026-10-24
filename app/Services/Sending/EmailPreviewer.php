<?php

namespace App\Services\Sending;

use App\Enums\EmailPurpose;
use App\Models\SenderIdentity;

/**
 * Renders unsent content exactly as recipients will see it, using sample merge tag values.
 */
class EmailPreviewer
{
    public function __construct(private EmailContentRenderer $renderer) {}

    /**
     * @param  string|array<string, mixed>  $body
     */
    public function render(string $subject, string|array $body, EmailPurpose $purpose, ?SenderIdentity $senderIdentity, string $recipientEmail, ?string $textBody = null): RenderedEmail
    {
        $variables = $this->renderer->sampleVariables($recipientEmail);

        return $this->renderer->renderContent(
            subject: $subject,
            body: $body,
            variables: $variables,
            unsubscribeUrl: $purpose === EmailPurpose::Marketing ? $variables['unsubscribe_url'] : null,
            textBody: $textBody,
            missingValue: null,
        );
    }

    public function html(RenderedEmail $rendered, ?SenderIdentity $senderIdentity): string
    {
        return view('mail.outgoing', [
            'rendered' => $rendered,
            'senderName' => $senderIdentity?->name ?? config('app.name'),
        ])->render();
    }
}
