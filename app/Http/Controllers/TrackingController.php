<?php

namespace App\Http\Controllers;

use App\Enums\EmailEventType;
use App\Enums\EventSource;
use App\Enums\OutreachEvent;
use App\Models\EmailMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Records opens and clicks from ProSend's own tracking. Both links are signed, which is what authorizes them, so the
 * email is looked up across clients by its random id. Tracking never gets in the recipient's way: a missing email still
 * returns the image or follows the link.
 */
class TrackingController extends Controller
{
    private const TRANSPARENT_GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, string $message): Response
    {
        if ($request->isMethod('GET')) {
            $this->record($message, EmailEventType::Opened, ['user_agent' => Str::limit((string) $request->userAgent(), 200)]);
        }

        return response(base64_decode(self::TRANSPARENT_GIF), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    public function click(Request $request, string $message): RedirectResponse
    {
        $destination = (string) $request->query('url');

        abort_unless(in_array(parse_url($destination, PHP_URL_SCHEME), ['http', 'https'], true), 404);

        // Link scanners often check with HEAD first; only real visits count.
        if ($request->isMethod('GET')) {
            $this->record($message, EmailEventType::Clicked, ['url' => Str::limit($destination, 500)]);
        }

        return redirect()->away($destination);
    }

    /**
     * @param  array<string, string>  $details
     */
    private function record(string $uuid, EmailEventType $type, array $details): void
    {
        // The links are signed but can be replayed; at most 20 events per email and kind are kept each hour. The reader is
        // never refused: the image and the redirect are returned either way.
        $limiterKey = "tracking:{$type->value}:{$uuid}";

        if (RateLimiter::tooManyAttempts($limiterKey, 20)) {
            return;
        }

        RateLimiter::hit($limiterKey, 3600);

        rescue(function () use ($uuid, $type, $details): void {
            $message = EmailMessage::acrossClients()->where('uuid', $uuid)->first();
            $event = $message?->recordEvent($type, EventSource::Tracking, $details, recipientEmail: null);

            // Automations and webhooks hear about the first open or click of each email.
            if ($event !== null && $message->events()->where('type', $type)->count() === 1) {
                $message->announce($type === EmailEventType::Opened ? OutreachEvent::EmailOpened : OutreachEvent::EmailClicked, "{$type->value}:{$message->getKey()}", $type === EmailEventType::Clicked ? ['url' => $details['url'] ?? null] : []);
            }
        });
    }
}
