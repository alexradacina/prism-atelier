<?php

namespace App\Http\Controllers;

use App\Services\Pixelforge;
use App\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __invoke(Request $request, ReportService $reports, Pixelforge $provider): Response
    {
        abort_unless($request->user()->is_admin, 403);
        $data = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start_date'],
        ]);
        $start = $data['start_date'] ?? now()->subDays(29)->toDateString();
        $end = $data['end_date'] ?? now()->toDateString();
        abort_if(CarbonImmutable::parse($start)->diffInDays($end, false) < 0 ||
            CarbonImmutable::parse($start)->diffInDays($end) > 91, 422, 'Choose a range of at most 92 UTC days.');
        $report = null;
        $error = null;
        try {
            $report = $reports->build($start, $end, $provider);
        } catch (ConnectionException|RequestException $exception) {
            report($exception);
            $error = 'Provider usage is unavailable. Try again shortly.';
        }

        return Inertia::render('Report', ['report' => $report, 'report_error' => $error, 'filters' => ['start_date' => $start, 'end_date' => $end]]);
    }
}
