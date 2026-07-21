<x-mail::message>
# {{ $period === 'monthly' ? __('Monthly Insights report') : __('Weekly Insights report') }}

{{ $label }}

<x-mail::table>
| | |
|:--|--:|
| {{ __('Pageviews') }} | **{{ number_format($payload['tiles']['pageviews']['value']) }}** |
| {{ __('Unique visitors') }} | **{{ number_format($payload['tiles']['visitors']['value']) }}** |
| {{ __('Sessions') }} | **{{ number_format($payload['tiles']['sessions']['value']) }}** |
</x-mail::table>

@if (count($payload['pages']))
## {{ __('Top pages') }}

<x-mail::table>
| {{ __('Page') }} | {{ __('Views') }} |
|:--|--:|
@foreach (array_slice($payload['pages'], 0, 5) as $page)
| {{ $page['title'] ?? $page['path'] }} | {{ number_format($page['views']) }} |
@endforeach
</x-mail::table>
@endif

@if (count($payload['referrers']))
## {{ __('Referrers') }}

<x-mail::table>
| {{ __('Source') }} | {{ __('Views') }} |
|:--|--:|
@foreach (array_slice($payload['referrers'], 0, 5) as $referrer)
| {{ $referrer['domain'] }} | {{ number_format($referrer['views']) }} |
@endforeach
</x-mail::table>
@endif

@if (count($payload['goals']))
## {{ __('Goals') }}

<x-mail::table>
| {{ __('Goal') }} | {{ __('Conversions') }} |
|:--|--:|
@foreach ($payload['goals'] as $goal)
| {{ $goal['name'] }} | {{ number_format($goal['conversions']) }} |
@endforeach
</x-mail::table>
@endif

<x-mail::button :url="cp_route('insights.dashboard')">
{{ __('View dashboard') }}
</x-mail::button>

{{ config('app.name') }}
</x-mail::message>
