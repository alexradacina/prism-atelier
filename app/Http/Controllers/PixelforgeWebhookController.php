<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWebhook;
use App\Models\WebhookEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class PixelforgeWebhookController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $secret = config('services.pixelforge.webhook_secret');
        abort_unless(is_string($secret) && $secret !== '', 503, 'Webhook signing is not configured.');
        $signature = [];
        foreach (explode(',', (string) $request->header('Pixelforge-Signature')) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            $signature[$key] = $value;
        }
        $timestamp = $signature['t'] ?? '';
        abort_unless(ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300, 400, 'Invalid signature timestamp.');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);
        abort_unless(hash_equals($expected, $signature['v1'] ?? ''), 400, 'Invalid signature.');
        $payload = json_decode($request->getContent(), true);
        abort_unless(is_array($payload), 400, 'Invalid JSON.');
        Validator::make($payload, [
            'id' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::in(['ping', 'generation.processing', 'generation.succeeded', 'generation.failed'])],
            'data.object.id' => ['required_unless:type,ping', 'string'],
            'data.object.status' => ['required_unless:type,ping', Rule::in(['processing', 'succeeded', 'failed'])],
        ])->validate();
        DB::transaction(function () use ($payload) {
            WebhookEvent::query()->insertOrIgnore([
                'id' => $payload['id'], 'type' => $payload['type'], 'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            ProcessWebhook::dispatch($payload['id'])->afterCommit();
        });

        return response()->noContent();
    }
}
