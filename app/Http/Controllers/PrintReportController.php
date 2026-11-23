<?php

namespace App\Http\Controllers;

use App\Enums\Permission;
use App\Models\Client;
use App\Services\Analytics\OutreachReport;
use App\Services\Tenancy\CurrentClient;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A report as a plain page for printing or saving as PDF from the browser. Reached from the Reports page, inside the
 * panel, so the person is signed in and the client comes from the URL.
 */
class PrintReportController extends Controller
{
    public function __invoke(Request $request, OutreachReport $report, CurrentClient $currentClient): View
    {
        $user = $request->user();
        abort_unless($user?->hasPermission(Permission::ViewReports) && $user->hasPermission(Permission::ExportReports), 403);

        $validated = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'grouping' => ['required', 'in:'.implode(',', array_keys(OutreachReport::GROUPINGS))],
            'campaign_ids' => ['array'],
            'campaign_ids.*' => ['integer'],
        ]);

        $acrossClients = $validated['grouping'] === 'client';
        abort_if($acrossClients && ! $user->isSuperAdmin(), 403);

        /** @var Client $client */
        $client = Filament::getTenant() ?? $currentClient->get();

        return view('reports.print', [
            'client' => $client,
            'filters' => $validated,
            'grouping' => OutreachReport::GROUPINGS[$validated['grouping']],
            'columns' => OutreachReport::COLUMNS,
            'rows' => $report->rows($validated['grouping'], $validated, $acrossClients),
            'totals' => $report->withRates($report->totals($validated, $acrossClients)),
        ]);
    }
}
