<?php

namespace App\Services\Inbox;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Message;

/**
 * Turns a raw email (RFC 822) into an InboundEmail: threading headers, sender, text, attachments, whether it is an
 * automatic reply, and the details of a delivery failure report.
 */
class MimeParser
{
    public const MAX_TEXT_BYTES = 200_000;

    public const MAX_HTML_BYTES = 1_000_000;

    public const MAX_ATTACHMENTS = 10;

    public const MAX_ATTACHMENT_BYTES = 10 * 1024 * 1024;

    private const AUTO_REPLY_SUBJECTS = '/^(auto(matic)?[ -]?(reply|response)|auto:|out of (the )?office|abwesenheit|absence|réponse automatique)/i';

    public function parse(string $raw): InboundEmail
    {
        $message = Message::fromString($this->withReadableDate($raw));
        $from = $message->getFrom()->first();
        $deliveryReport = $this->deliveryReport($message, $raw);

        return new InboundEmail(
            messageId: $this->id((string) $message->getMessageId()),
            inReplyTo: $this->id((string) $message->getInReplyTo()),
            references: $this->ids((string) $message->getReferences()),
            fromEmail: Str::limit(Str::lower(trim((string) ($from->mail ?? ''))), 250, ''),
            fromName: filled($from->personal ?? null) ? Str::limit((string) $from->personal, 250, '') : null,
            to: array_values(array_filter(array_map(fn (object $address): string => Str::lower((string) ($address->mail ?? '')), $message->getTo()->all()))),
            subject: Str::limit(trim((string) $message->getSubject()), 250, '') ?: '(no subject)',
            text: Str::limit($message->hasTextBody() ? $message->getTextBody() : strip_tags($message->getHTMLBody()), self::MAX_TEXT_BYTES, ''),
            html: $message->hasHTMLBody() ? Str::limit($message->getHTMLBody(), self::MAX_HTML_BYTES, '') : null,
            receivedAt: $this->date($message),
            isAutoReply: $deliveryReport !== null || $this->isAutoReply($message),
            deliveryReport: $deliveryReport,
            attachments: $deliveryReport === null ? $this->attachments($message) : [],
        );
    }

    /**
     * The parser refuses an email whose Date header it cannot read. The rest of the email is still useful, so such a
     * header is replaced with the current time before parsing.
     */
    private function withReadableDate(string $raw): string
    {
        $separator = str_contains($raw, "\r\n\r\n") ? "\r\n\r\n" : "\n\n";
        $headers = Str::before($raw, $separator);

        if (preg_match('/^Date:[ \t]*([^\r\n]*)/mi', $headers, $match) !== 1) {
            return $raw;
        }

        try {
            CarbonImmutable::parse(trim($match[1]));

            return $raw;
        } catch (Throwable) {
            $readable = preg_replace('/^Date:[ \t]*[^\r\n]*/mi', 'Date: '.CarbonImmutable::now()->toRfc2822String(), $headers, 1) ?? $headers;

            return $readable.$separator.Str::after($raw, $separator);
        }
    }

    private function isAutoReply(Message $message): bool
    {
        $autoSubmitted = Str::lower($this->header($message, 'auto_submitted'));
        $precedence = Str::lower($this->header($message, 'precedence'));

        return ($autoSubmitted !== '' && $autoSubmitted !== 'no')
            || $this->header($message, 'x_autoreply') !== ''
            || $this->header($message, 'x_autorespond') !== ''
            || in_array($precedence, ['auto_reply', 'bulk', 'junk'], true)
            || preg_match(self::AUTO_REPLY_SUBJECTS, trim((string) $message->getSubject())) === 1;
    }

    /**
     * A delivery status notification: multipart/report with a delivery-status part, or a recognisable bounce from a
     * mail server without one.
     */
    private function deliveryReport(Message $message, string $raw): ?DeliveryReport
    {
        $contentType = Str::lower($this->header($message, 'content_type'));
        $statusPart = null;
        $originalHeaders = null;

        foreach ($message->getAttachments() as $attachment) {
            $type = Str::lower((string) ($attachment->getContentType() ?? ''));

            if (str_contains($type, 'delivery-status')) {
                $statusPart = (string) $attachment->getContent();
            } elseif (str_contains($type, 'rfc822')) {
                $originalHeaders ??= (string) $attachment->getContent();
            }
        }

        $from = Str::lower((string) ($message->getFrom()->first()->mail ?? ''));
        // Delivery reports come from mail systems: a daemon or postmaster address, or an empty return path. Anything
        // else, including a report a person built by hand, is treated as ordinary email.
        $fromMailSystem = Str::startsWith($from, ['mailer-daemon@', 'mail-daemon@', 'postmaster@'])
            || preg_match('/^Return-Path:[ \t]*<[ \t]*>[ \t]*\r?$/mi', Str::before($raw, "\r\n\r\n")) === 1;
        $looksLikeBounce = preg_match('/undeliver|delivery status|failure|returned|could not be delivered/i', (string) $message->getSubject()) === 1;

        if (! $fromMailSystem || ($statusPart === null && ! str_contains($contentType, 'report-type=delivery-status') && ! $looksLikeBounce)) {
            return null;
        }

        $statusPart ??= $raw;
        $status = $this->field($statusPart, 'Status');
        $action = Str::lower((string) $this->field($statusPart, 'Action'));

        // Reports of successful delivery, relaying or expansion are not bounces.
        $isFailure = in_array($action, ['failed', 'delayed'], true) || ($status !== null && preg_match('/^[45]\./', $status) === 1);

        if (! $isFailure && ($action !== '' || $status !== null)) {
            return null;
        }
        $recipient = $this->field($statusPart, 'Final-Recipient') ?? $this->field($statusPart, 'Original-Recipient');
        $ownId = $this->id((string) $message->getMessageId());

        return new DeliveryReport(
            // 5.x.x and "failed" are permanent; 4.x.x and "delayed" are temporary. Without a status, a failure is assumed.
            permanent: $status !== null ? str_starts_with($status, '5') : $action !== 'delayed',
            status: $status,
            recipient: $recipient === null ? null : Str::lower(trim(Str::afterLast($recipient, ';'))),
            originalMessageId: $this->originalMessageId($originalHeaders ?? $raw, $ownId),
            diagnostic: $this->field($statusPart, 'Diagnostic-Code'),
        );
    }

    /**
     * The Message-ID of the email that bounced: from the returned headers, never the report's own.
     */
    private function originalMessageId(string $text, ?string $ownId): ?string
    {
        preg_match_all('/^Message-ID:\s*<([^>\s]+)>/mi', $text, $matches);

        foreach ($matches[1] as $id) {
            if ($id !== $ownId) {
                return $id;
            }
        }

        return null;
    }

    private function field(string $text, string $name): ?string
    {
        return preg_match('/^'.preg_quote($name, '/').':\s*(.+)$/mi', $text, $match) === 1 ? trim($match[1]) : null;
    }

    /**
     * @return list<array{name: string, mime: string, content: string}>
     */
    private function attachments(Message $message): array
    {
        return $message->getAttachments()
            ->filter(fn (Attachment $attachment): bool => strlen((string) $attachment->getContent()) <= self::MAX_ATTACHMENT_BYTES)
            ->take(self::MAX_ATTACHMENTS)
            ->map(fn (Attachment $attachment): array => [
                'name' => Str::limit(basename(str_replace('\\', '/', (string) ($attachment->getName() ?: 'attachment'))), 150, ''),
                'mime' => (string) ($attachment->getMimeType() ?: 'application/octet-stream'),
                'content' => (string) $attachment->getContent(),
            ])
            ->values()
            ->all();
    }

    private function date(Message $message): CarbonImmutable
    {
        try {
            $date = $message->getDate()?->first();

            return $date === null ? CarbonImmutable::now() : CarbonImmutable::instance($date)->min(CarbonImmutable::now());
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function header(Message $message, string $name): string
    {
        try {
            return trim((string) $message->getHeader()?->get($name)?->toString());
        } catch (Throwable) {
            return '';
        }
    }

    private function id(string $value): ?string
    {
        $id = trim($value, " \t\r\n<>");

        return $id === '' ? null : Str::limit($id, 250, '');
    }

    /**
     * @return list<string>
     */
    private function ids(string $value): array
    {
        return array_values(array_filter(array_map(fn (string $id): ?string => $this->id($id), preg_split('/[\s,]+/', $value) ?: [])));
    }
}
