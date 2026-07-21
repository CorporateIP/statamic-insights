<?php

namespace CorporateIp\Insights\Listeners;

use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Support\Beacon;
use Statamic\Events\SubmissionCreated;

/**
 * Every Statamic form submission becomes a `form:{handle}` event - server-side,
 * so it needs no JavaScript and works behind the static cache. Goals of type
 * "form" read these rows.
 */
class RecordFormSubmission
{
    public function handle(SubmissionCreated $event): void
    {
        rescue(function () use ($event) {
            $request = request();

            if (! $request || Beacon::shouldIgnore($request)) {
                return;
            }

            // The submission POST targets Statamic's form action route; the
            // page the visitor was on is the referer.
            $path = Beacon::path((string) ($request->headers->get('referer') ?: '/'));

            if (Beacon::excludedPath($path)) {
                return;
            }

            Event::create([
                'visited_at' => now(),
                'site' => Beacon::site($request, $path),
                'name' => 'form:'.$event->submission->form()->handle(),
                'path' => $path,
                'properties' => null,
                'visitor_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.name'))),
                'session_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.session_name'))),
            ]);
        }, report: false);
    }
}
