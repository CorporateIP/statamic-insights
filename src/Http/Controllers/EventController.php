<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Models\Event;
use CorporateIp\Insights\Support\Beacon;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

class EventController extends Controller
{
    /**
     * Custom events fired via window._insights.event(name, props). Reserved
     * names (404, form:*) are recorded server-side only - a page can't forge
     * form conversions or broken-link reports.
     */
    public function store(Request $request)
    {
        if (Beacon::shouldIgnore($request)) {
            return response()->noContent();
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'path' => ['required', 'string', 'max:2048'],
            'props' => ['nullable', 'array', 'max:20'],
        ]);

        $name = trim(strip_tags($data['name']));
        $path = Beacon::path($data['path']);

        if ($name === '' || $name === '404' || Str::startsWith($name, 'form:') || Beacon::excludedPath($path)) {
            return response()->noContent();
        }

        Event::create([
            'visited_at' => now(),
            'site' => Beacon::site($request, $path),
            'name' => $name,
            'path' => $path,
            'properties' => $this->properties($data['props'] ?? null),
            'visitor_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.name'))),
            'session_id' => Beacon::uuidOrNull($request->cookie(config('insights.cookie.session_name'))),
        ]);

        return response()->noContent();
    }

    private function properties(?array $props): ?array
    {
        if (! $props) {
            return null;
        }

        $clean = collect($props)
            ->filter(fn ($value) => is_scalar($value))
            ->mapWithKeys(fn ($value, $key) => [Str::limit(strip_tags((string) $key), 64, '') => Str::limit(strip_tags((string) $value), 255, '')])
            ->filter(fn ($value, $key) => $key !== '')
            ->all();

        return $clean === [] ? null : $clean;
    }
}
