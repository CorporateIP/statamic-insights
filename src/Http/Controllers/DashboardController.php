<?php

namespace CorporateIp\Insights\Http\Controllers;

use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Statamic\Facades\User;

class DashboardController extends Controller
{
    public function index()
    {
        abort_unless(User::current()->can('view insights'), 403);

        return Inertia::render('insights::Dashboard', [
            'title' => __('Insights'),
            // Placeholder until the tracking pipeline (stage B) and the real
            // queries (stage C) land.
            'stats' => [
                'tiles' => [
                    ['label' => __('Pageviews (7d)'), 'value' => '—'],
                    ['label' => __('Unique visitors (7d)'), 'value' => '—'],
                    ['label' => __('Sessions (7d)'), 'value' => '—'],
                    ['label' => __('Visitors right now'), 'value' => '—'],
                ],
            ],
        ]);
    }
}
