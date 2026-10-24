<?php

namespace App\Http\Controllers;

use App\Actions\Contacts\UnsubscribeRecipient;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UnsubscribeController extends Controller
{
    public function show(Request $request, EmailMessage $message): View
    {
        return view('unsubscribe.confirm', [
            'email' => $message->to_email,
            'senderName' => $message->senderIdentity->name,
            'actionUrl' => $request->fullUrl(),
        ]);
    }

    /**
     * Handles both the confirmation form and one-click unsubscribes sent by mail clients (RFC 8058).
     */
    public function store(EmailMessage $message, UnsubscribeRecipient $unsubscribe): View
    {
        $unsubscribe->handle($message);

        return view('unsubscribe.done', [
            'email' => $message->to_email,
            'senderName' => $message->senderIdentity->name,
        ]);
    }
}
