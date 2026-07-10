<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Dashboard\Metrics;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Statamic\Facades\User;

class DashboardController extends Controller
{
    public function index()
    {
        $this->authorizeView();

        return Inertia::render('insights::Dashboard', [
            'title' => __('Insights'),
            'dataUrl' => cp_route('insights.data'),
            'initial' => Metrics::cached('7d'),
        ]);
    }

    public function data(Request $request)
    {
        $this->authorizeView();

        $metrics = new Metrics($request->query('range', '7d'));

        // The realtime slice refreshes on its own 30s interval and always
        // bypasses the payload cache.
        if ($request->query('only') === 'realtime') {
            return response()->json(['realtime' => $metrics->realtime()]);
        }

        return response()->json(Metrics::cached($request->query('range', '7d')));
    }

    private function authorizeView(): void
    {
        abort_unless(User::current()->can('view insights'), 403);
    }
}
