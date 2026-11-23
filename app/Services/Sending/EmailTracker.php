<?php

namespace App\Services\Sending;

use App\Models\Campaign;
use App\Models\EmailMessage;
use Illuminate\Support\Facades\URL;

/**
 * Adds ProSend's own open and click tracking to campaign email, so mailboxes without provider webhooks (plain SMTP)
 * still report engagement. Click links are signed over their destination, so they can never be used to redirect
 * someone elsewhere. Opens are approximate: some mail apps load images automatically, others block them.
 */
class EmailTracker
{
    public function instrument(RenderedEmail $rendered, EmailMessage $message, Campaign $campaign): RenderedEmail
    {
        if (! $campaign->track_opens && ! $campaign->track_clicks) {
            return $rendered;
        }

        $html = $rendered->html;

        if ($campaign->track_clicks) {
            $html = $this->trackLinks($html, $message, $rendered->unsubscribeUrl);
        }

        if ($campaign->track_opens) {
            $pixel = '<img src="'.e(URL::signedRoute('track.open', ['message' => $message->uuid])).'" width="1" height="1" alt="" style="display:block;border:0;width:1px;height:1px;">';
            $html = str_contains($html, '</body>') ? str_replace('</body>', $pixel.'</body>', $html) : $html.$pixel;
        }

        return new RenderedEmail(
            subject: $rendered->subject,
            html: $html,
            text: $rendered->text,
            unsubscribeUrl: $rendered->unsubscribeUrl,
        );
    }

    /**
     * Point every web link at the click endpoint, except the unsubscribe link, which must always work directly.
     */
    private function trackLinks(string $html, EmailMessage $message, ?string $unsubscribeUrl): string
    {
        return preg_replace_callback(
            '/(<a\b[^>]*?\bhref=)(["\'])(https?:\/\/[^"\']+)\2/i',
            function (array $match) use ($message, $unsubscribeUrl): string {
                $destination = html_entity_decode($match[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if ($destination === $unsubscribeUrl) {
                    return $match[0];
                }

                $tracked = URL::signedRoute('track.click', ['message' => $message->uuid, 'url' => $destination]);

                return $match[1].$match[2].e($tracked).$match[2];
            },
            $html,
        ) ?? $html;
    }
}
