<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiKey;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function apiKey(Request $request): ApiKey
    {
        return $request->attributes->get('api_key');
    }

    /**
     * @return array<string, mixed>
     */
    protected function meta(Request $request, array $extra = []): array
    {
        return ['request_id' => $request->attributes->get('request_id'), ...$extra];
    }
}
