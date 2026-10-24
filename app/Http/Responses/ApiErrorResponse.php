<?php

namespace App\Http\Responses;

use App\Exceptions\ApiException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\PostTooLargeException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Turns any exception raised during an API request into the one error envelope the API documents:
 * {"error": {"code", "message", "fields"?, "request_id"}}. Internal details never reach the client.
 */
class ApiErrorResponse
{
    public static function fromThrowable(Throwable $exception, Request $request): JsonResponse
    {
        return match (true) {
            $exception instanceof ApiException => static::make($request, $exception->status, $exception->errorCode, $exception->getMessage(), $exception->fields, $exception->headers),
            $exception instanceof ValidationException => static::make($request, 422, 'validation_failed', $exception->validator->errors()->first(), $exception->errors()),
            $exception instanceof AuthenticationException => static::make($request, 401, 'unauthenticated', 'A valid API key is required.'),
            $exception instanceof AuthorizationException, $exception instanceof AccessDeniedHttpException => static::make($request, 403, 'forbidden', 'This key is not allowed to do that.'),
            $exception instanceof ModelNotFoundException, $exception instanceof NotFoundHttpException => static::fromThrowable(ApiException::notFound(), $request),
            $exception instanceof ThrottleRequestsException => static::make($request, 429, 'rate_limited', 'Too many requests. Wait before retrying.', headers: $exception->getHeaders()),
            $exception instanceof PostTooLargeException => static::make($request, 413, 'payload_too_large', 'The request body is too large.'),
            $exception instanceof HttpExceptionInterface => static::make($request, $exception->getStatusCode(), 'http_error', Response::$statusTexts[$exception->getStatusCode()] ?? 'Request failed.', headers: $exception->getHeaders()),
            default => static::make($request, 500, 'server_error', 'Something went wrong on our side. It is safe to retry with the same Idempotency-Key.'),
        };
    }

    /**
     * @param  array<string, list<string>>  $fields
     * @param  array<string, string|int>  $headers
     */
    public static function make(Request $request, int $status, string $code, string $message, array $fields = [], array $headers = []): JsonResponse
    {
        return response()->json([
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'fields' => $fields === [] ? null : $fields,
                'request_id' => $request->attributes->get('request_id'),
            ], fn (mixed $value): bool => $value !== null),
        ], $status, $headers);
    }
}
