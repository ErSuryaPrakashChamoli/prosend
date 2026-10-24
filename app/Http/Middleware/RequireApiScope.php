<?php

namespace App\Http\Middleware;

use App\Enums\ApiScope;
use App\Exceptions\ApiException;
use App\Models\ApiKey;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $apiKey = $request->attributes->get('api_key');

        if (! $apiKey instanceof ApiKey || ! $apiKey->hasScope(ApiScope::from($scope))) {
            throw ApiException::forbidden('missing_scope', "This API key needs the {$scope} scope.");
        }

        return $next($request);
    }
}
