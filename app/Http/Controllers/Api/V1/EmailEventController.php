<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ScopesEmailsToApiKey;
use App\Http\Resources\Api\V1\EmailEventResource;
use App\Models\EmailMessage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EmailEventController extends ApiController
{
    use ScopesEmailsToApiKey;

    /**
     * The message's event history, oldest first.
     */
    public function index(Request $request, EmailMessage $email): AnonymousResourceCollection
    {
        $this->ensureEmailVisibleTo($this->apiKey($request), $email);

        return EmailEventResource::collection($email->events()->orderBy('occurred_at')->orderBy('id')->get())
            ->additional(['meta' => $this->meta($request)]);
    }
}
