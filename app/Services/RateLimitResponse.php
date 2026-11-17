<?php

namespace App\Services;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RateLimitResponse
{
    public function __invoke(ThrottleRequestsException $exception, Request $request): Response
    {
        $headers = $exception->getHeaders();
        $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));
        $wait = $seconds === 1 ? '1 second' : $seconds.' seconds';
        [$route, $field, $message] = match ($request->path()) {
            'login' => ['login', 'email', 'Please wait '.$wait.' before trying to sign in again. This short pause helps protect your account.'],
            'register' => ['register', 'email', 'Account creation is temporarily paused. Please try again in '.$wait.'.'],
            'quotes' => ['studio', 'quote_id', 'You’re updating your idea a little quickly. Please wait '.$wait.', then try your estimate again.'],
            'generations' => ['studio', 'quote_id', 'You’ve sent several image requests in a short time. Please wait '.$wait.' before trying again. This request wasn’t added to your queue and no credits were reserved for it.'],
            'credits' => ['credits', 'amount', 'Please wait '.$wait.' before adding more credits. This top-up wasn’t processed.'],
            default => ['home', 'rate_limit', 'Please give us '.$wait.' before trying again.'],
        };
        if (! $request->header('X-Inertia') && ($request->expectsJson() || $request->is('api/*') || ! $request->isMethod('POST'))) {
            return response()->json(['message' => $message, 'retry_after' => $seconds], 429, $headers);
        }

        return to_route($route, status: 303)->withErrors([$field => $message])->withHeaders($headers);
    }
}
