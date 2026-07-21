<?php

namespace CorporateIp\Insights\Actions;

use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\User;

/**
 * Appears on every entry (list + publish form actions) with zero setup and
 * deep-links to the dashboard filtered to that entry's URL. Opt out with
 * config insights.entry_action = false.
 */
class ViewInInsights extends Action
{
    public $icon = 'chart-monitoring-indicator';

    public static function title()
    {
        return __('View in Insights');
    }

    public function visibleTo($item)
    {
        return config('insights.entry_action', true)
            && $item instanceof Entry
            && $item->url()
            && User::current()->can('view insights');
    }

    public function visibleToBulk($items)
    {
        return false;
    }

    public function authorize($user, $item)
    {
        return $user->can('view insights');
    }

    public function run($items, $values)
    {
        //
    }

    public function redirect($items, $values)
    {
        $entry = $items->first();

        return cp_route('insights.dashboard', array_filter([
            'filter_path' => $entry->url(),
            'site' => $entry->locale(),
        ]));
    }
}
