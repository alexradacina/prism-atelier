<?php

use App\Jobs\ProcessWebhook;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\ProviderLimits;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('prism:admin {email} {--revoke}', function () {
    $user = User::where('email', $this->argument('email'))->first();
    if (! $user) {
        $this->error('Register that email in the app first.');

        return 1;
    }
    $user->forceFill(['is_admin' => ! $this->option('revoke')])->save();
    $this->info('Operator access updated.');

    return 0;
})->purpose('Grant or revoke operator report access');
Artisan::command('prism:reconcile {--retry-submissions : Safely retry unconfirmed creates with their original idempotency keys}', function () {
    Generation::whereIn('status', ['queued', 'processing'])->whereNotNull('provider_id')
        ->whereNull('sync_paused_at')
        ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subSeconds(30)))
        ->where(fn ($query) => $query->whereNull('sync_retry_at')->orWhere('sync_retry_at', '<=', now()))
        ->each(fn ($generation) => SyncGeneration::dispatch($generation->id));
    WebhookEvent::whereNull('processed_at')->where('created_at', '<', now()->subSeconds(30))
        ->where(fn ($query) => $query->where('status', 'pending')->orWhere(fn ($unmatched) => $unmatched
            ->where('status', 'unmatched')->where('processing_attempts', '<', 6)->where('next_attempt_at', '<=', now())))
        ->each(fn ($event) => ProcessWebhook::dispatch($event->id));
    Generation::where('status', 'queued')->whereNull('provider_id')->whereNull('error_message')
        ->where('created_at', '<', now()->subMinutes(2))
        ->where('submission_attempts', '<', ProviderLimits::MAX_SUBMISSION_ATTEMPTS)
        ->where(fn ($query) => $query->whereNull('submission_retry_at')->orWhere('submission_retry_at', '<=', now()))
        ->each(fn ($generation) => SubmitGeneration::dispatch($generation->id));
    if ($this->option('retry-submissions')) {
        Generation::whereIn('status', ['queued', 'processing'])->whereNull('provider_id')
            ->whereNotNull('error_message')
            ->where('created_at', '<', now()->subMinutes(5))
            ->each(function ($generation) {
                $generation->update(['submission_attempts' => 0, 'pricing_failures' => 0, 'submission_retry_at' => null, 'error_message' => null]);
                SubmitGeneration::dispatch($generation->id);
            });
    }
    $this->info('Reconciliation jobs queued.');
})->purpose('Recover missed webhooks and stalled polling');
Artisan::command('prism:resume-polling {generation : Local generation UUID}', function () {
    $resumed = DB::transaction(function () {
        $generation = Generation::query()->lockForUpdate()->find($this->argument('generation'));
        if (! $generation || $generation->terminal() || ! $generation->provider_id || ! $generation->sync_paused_at) {
            return false;
        }
        $generation->update(['sync_paused_at' => null, 'sync_error_status' => null, 'sync_retry_at' => null,
            'sync_failures' => 0, 'error_message' => null, 'waiting_message' => null]);
        SyncGeneration::dispatch($generation->id)->afterCommit();

        return true;
    });
    if (! $resumed) {
        $this->error('No paused, accepted generation was found.');

        return 1;
    }
    $this->info('Polling resumed. Existing holds and charges were preserved.');

    return 0;
})->purpose('Resume one paused generation after correcting its provider access problem');
Artisan::command('prism:webhooks {--replay= : Replay one stored, unprocessed event ID}', function () {
    if ($id = $this->option('replay')) {
        $event = WebhookEvent::find($id);
        if (! $event) {
            $this->error('Stored event not found.');

            return 1;
        }
        if ($event->processed_at) {
            $this->info('Event was already processed. No replay is needed.');
        } else {
            ProcessWebhook::dispatch($event->id, force: true);
            $this->info('Stored event queued for replay using its original event ID and payload.');
        }

        return 0;
    }
    $events = WebhookEvent::whereIn('status', ['unmatched', 'failed'])->orderBy('created_at')->get();
    $this->table(['Event ID', 'State', 'Attempts', 'Provider generation', 'Last attempt', 'Next attempt'], $events->map(fn ($event) => [
        $event->id, $event->status, $event->processing_attempts, $event->payload['data']['object']['id'] ?? '—',
        $event->last_attempted_at?->toIso8601String() ?? '—', $event->next_attempt_at?->toIso8601String() ?? 'Manual replay required',
    ])->all());

    return 0;
})->purpose('Inspect unmatched/failed webhook events or replay one without inserting another record');
Schedule::command('prism:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
