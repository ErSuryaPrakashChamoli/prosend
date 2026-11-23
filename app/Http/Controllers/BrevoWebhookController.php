<?php

namespace App\Http\Controllers;

use App\Enums\ProviderType;
use App\Models\SmtpProvider;
use App\Services\Tenancy\CurrentClient;
use App\Services\Webhooks\BrevoWebhookProcessor;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BrevoWebhookController extends Controller
{
    /**
     * Brevo may send a single event or a batch of events.
     */
    private const MAX_EVENTS_PER_REQUEST = 1000;

    /**
     * The secret token authorizes the request, so the provider is looked up across clients.
     */
    public function __invoke(Request $request, string $provider, string $token, BrevoWebhookProcessor $processor): Response
    {
        $provider = SmtpProvider::acrossClients()->find($provider);

        abort_unless($provider instanceof SmtpProvider && $provider->type === ProviderType::Brevo && $provider->webhookTokenMatches($token), 404);

        // Events can only touch the provider's own client, such as the suppressions they add.
        app(CurrentClient::class)->set($provider->client);

        $payload = $request->json()->all();
        $events = array_is_list($payload) ? $payload : [$payload];

        foreach (array_slice($events, 0, self::MAX_EVENTS_PER_REQUEST) as $event) {
            if (is_array($event)) {
                $processor->process($event, $provider);
            }
        }

        return response()->noContent();
    }
}
