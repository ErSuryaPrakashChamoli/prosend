<?php

namespace App\Http\Controllers;

use App\Actions\Contacts\UnsubscribeRecipient;
use App\Models\EmailMessage;
use App\Services\Tenancy\CurrentClient;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(Request $request, string $message): View
    {
        $message = $this->messageFor($message);

        return view('unsubscribe.confirm', [
            'email' => $message->to_email,
            'senderName' => $message->senderIdentity->name,
            'actionUrl' => $request->fullUrl(),
        ]);
    }

    /**
     * Handles both the confirmation form and one-click unsubscribes sent by mail clients (RFC 8058).
     */
    public function store(string $message, UnsubscribeRecipient $unsubscribe): View
    {
        $message = $this->messageFor($message);

        $unsubscribe->handle($message);

        return view('unsubscribe.done', [
            'email' => $message->to_email,
            'senderName' => $message->senderIdentity->name,
        ]);
    }

    /**
     * The link is signed and names the email by its random id, which is what authorizes it, so the email is looked
     * up across clients. Everything after that belongs to the client that sent it, including the suppression.
     */
    private function messageFor(string $uuid): EmailMessage
    {
        $message = EmailMessage::acrossClients()->where('uuid', $uuid)->firstOrFail();

        app(CurrentClient::class)->set($message->client);

        return $message;
    }
}
