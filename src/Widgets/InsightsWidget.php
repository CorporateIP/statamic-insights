<?php

namespace CorporateIp\Insights\Widgets;

use CorporateIp\Insights\Dashboard\Metrics;
use Statamic\Facades\User;
use Statamic\Widgets\VueComponent;
use Statamic\Widgets\Widget;

class InsightsWidget extends Widget
{
    protected static $handle = 'insights';

    public function component()
    {
        if (! User::current()->can('view insights')) {
            return;
        }

        $data = Metrics::make($this->config('range', '7d'));

        return VueComponent::render('insights-widget', [
            'title' => $this->config('title', __('Insights')),
            'tiles' => $data['tiles'],
            'timeseries' => $data['timeseries'],
            'url' => cp_route('insights.dashboard'),
        ]);
    }
}
