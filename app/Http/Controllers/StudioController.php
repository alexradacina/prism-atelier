<?php

namespace App\Http\Controllers;

use App\Http\Requests\QuoteRequest;
use App\Models\Generation;
use App\Services\GenerationService;
use App\Services\Money;
use App\Services\Pixelforge;
use App\Services\WalletService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class StudioController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Studio', [
            'generations' => fn () => Generation::where('user_id', $request->user()->id)->latest()->limit(6)->get()->map->display(),
        ]);
    }

    public function history(Request $request): Response
    {
        $request->validate(['status' => ['nullable', Rule::in(['queued', 'processing', 'succeeded', 'failed'])]]);

        return Inertia::render('History', [
            'filters' => ['status' => $request->query('status', '')],
            'generations' => fn () => Generation::where('user_id', $request->user()->id)
                ->when($request->filled('status'), fn ($query) => $query->where('status', $request->query('status')))
                ->latest()->paginate(12)->withQueryString()->through(fn (Generation $generation) => $generation->display()),
        ]);
    }

    public function quote(QuoteRequest $request, Pixelforge $provider, GenerationService $service): JsonResponse
    {
        try {
            $pricing = $provider->pricing();
        } catch (ConnectionException|RequestException $exception) {
            report($exception);

            return response()->json(['message' => 'Pricing is temporarily unavailable. Please try again shortly.'], 503);
        }

        return response()->json($service->quote($request->user(), $request->validated(), $pricing));
    }

    public function store(Request $request, GenerationService $service): RedirectResponse
    {
        $data = $request->validate(['quote_id' => ['required', 'uuid']]);
        $service->create($request->user(), $data['quote_id']);

        return to_route('history')->with('message', 'Your image is in the queue. We’ll update it here as it develops.');
    }

    public function credits(Request $request, WalletService $wallets): Response
    {
        return Inertia::render('Credits', [
            'purchase_id' => (string) Str::uuid(),
            'wallet' => fn () => $wallets->details($request->user()),
        ]);
    }

    public function purchase(Request $request, GenerationService $service): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'uuid'], 'amount' => ['required', Rule::in(['5', '10', '25', '50'])],
        ]);
        $service->purchase($request->user(), $data['purchase_id'], Money::parse((string) $data['amount']));

        return to_route('credits')->with('message', '$'.$data['amount'].' in demo credits added. No payment was taken.');
    }
}
