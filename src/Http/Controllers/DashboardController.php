<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Dashboard\Metrics;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Statamic\Facades\User;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeView();

        return Inertia::render('insights::Dashboard', [
            'title' => __('Insights'),
            'dataUrl' => cp_route('insights.data'),
            'exportUrl' => cp_route('insights.export'),
            'settingsUrl' => User::current()->can('configure insights') ? cp_route('insights.settings') : null,
            // Query params flow into the initial payload so deep links work
            // (the per-entry "View in Insights" action filters by path).
            'initial' => Metrics::fromRequest($request)->cachedPayload(),
        ]);
    }

    public function data(Request $request)
    {
        $this->authorizeView();

        $metrics = Metrics::fromRequest($request);

        // The realtime slice refreshes on its own 30s interval and always
        // bypasses the payload cache.
        if ($request->query('only') === 'realtime') {
            return response()->json(['realtime' => $metrics->realtime()]);
        }

        return response()->json($metrics->cachedPayload());
    }

    public function export(Request $request)
    {
        $this->authorizeView();

        $dataset = (string) $request->query('dataset');

        $columns = [
            'pages' => ['path', 'title', 'visitors', 'views'],
            'referrers' => ['domain', 'views'],
            'campaigns' => ['campaign', 'source', 'visitors', 'views'],
            'devices' => ['label', 'visitors', 'count'],
            'browsers' => ['label', 'visitors', 'count'],
            'os' => ['label', 'visitors', 'count'],
            'countries' => ['code', 'visitors', 'views'],
            'goals' => ['handle', 'name', 'type', 'conversions', 'visitors', 'rate'],
            'timeseries' => null, // label/views pairs, special-cased below
        ];

        abort_unless(array_key_exists($dataset, $columns), 422, 'Unknown dataset.');

        $payload = Metrics::fromRequest($request)->cachedPayload();

        $rows = $dataset === 'timeseries'
            ? collect($payload['timeseries']['labels'])
                ->map(fn ($label, $i) => ['bucket' => $label, 'views' => $payload['timeseries']['views'][$i]])
                ->all()
            : $payload[$dataset];

        $header = $dataset === 'timeseries' ? ['bucket', 'views'] : $columns[$dataset];

        $filename = sprintf('insights-%s-%s-%s.csv', $dataset, $payload['range']['from'], $payload['range']['to']);

        return response()->streamDownload(function () use ($rows, $header) {
            $out = fopen('php://output', 'w');

            fputcsv($out, $header);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($column) => $row[$column] ?? '', $header));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=utf-8']);
    }

    private function authorizeView(): void
    {
        abort_unless(User::current()->can('view insights'), 403);
    }
}
