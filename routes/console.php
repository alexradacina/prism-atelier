<?php

use App\Jobs\ProcessWebhook;
use App\Jobs\SubmitGeneration;
use App\Jobs\SyncGeneration;
use App\Models\Generation;
use App\Models\User;
use App\Models\WebhookEvent;
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
        ->each(fn ($generation) => SyncGeneration::dispatch($generation->id));
    WebhookEvent::whereNull('processed_at')->where('created_at', '<', now()->subSeconds(30))
        ->each(fn ($event) => ProcessWebhook::dispatch($event->id));
    Generation::where('status', 'queued')->whereNull('provider_id')->whereNull('error_message')
        ->where('created_at', '<', now()->subMinutes(2))
        ->each(fn ($generation) => SubmitGeneration::dispatch($generation->id));
    if ($this->option('retry-submissions')) {
        Generation::whereIn('status', ['queued', 'processing'])->whereNull('provider_id')
            ->where('created_at', '<', now()->subMinutes(5))
            ->each(fn ($generation) => SubmitGeneration::dispatch($generation->id));
    }
    $this->info('Reconciliation jobs queued.');
})->purpose('Recover missed webhooks and stalled polling');
Schedule::command('prism:reconcile')->everyMinute()->withoutOverlapping();
