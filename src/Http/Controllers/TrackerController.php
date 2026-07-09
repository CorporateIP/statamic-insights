<?php

namespace CorporateIp\Insights\Http\Controllers;

use Illuminate\Routing\Controller;

class TrackerController extends Controller
{
    public function __invoke()
    {
        return response(file_get_contents(__DIR__.'/../../../resources/js/tracker.js'), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // Short enough that tracker updates roll out within the hour.
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
