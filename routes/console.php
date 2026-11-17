<?php

use App\Jobs\ProcessWebhook;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\ProviderLimits;
use Illuminate\Support\Facades\Artisan;
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
        ->where(fn ($query) => $query->whereNull('synced_at')->orWhere('synced_at', '<', now()->subSeconds(30)))
        ->where(fn ($query) => $query->whereNull('sync_retry_at')->orWhere('sync_retry_at', '<=', now()))
        ->each(fn ($generation) => SyncGeneration::dispatch($generation->id));
    WebhookEvent::whereNull('processed_at')->where('created_at', '<', now()->subSeconds(30))
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
                $generation->update(['submission_attempts' => 0, 'submission_retry_at' => null, 'error_message' => null]);
                SubmitGeneration::dispatch($generation->id);
            });
    }
    $this->info('Reconciliation jobs queued.');
})->purpose('Recover missed webhooks and stalled polling');
Schedule::command('prism:reconcile')->everyMinute()->withoutOverlapping();
Schedule::command('horizon:snapshot')->everyFiveMinutes()->withoutOverlapping();
