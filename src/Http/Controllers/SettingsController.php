<?php

namespace CorporateIp\Insights\Http\Controllers;

use CorporateIp\Insights\Goals\Goal;
use CorporateIp\Insights\Goals\GoalRepository;
use CorporateIp\Insights\Support\Settings;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Statamic\Facades\Form;
use Statamic\Facades\User;

class SettingsController extends Controller
{
    public function index()
    {
        $this->authorizeConfigure();

        $settings = app(Settings::class);

        return Inertia::render('insights::Settings', [
            'title' => __('Insights settings'),
            'saveUrl' => cp_route('insights.settings.save'),
            'dashboardUrl' => cp_route('insights.dashboard'),
            'goals' => app(GoalRepository::class)->all()->map->toArray()->values()->all(),
            // Existing Statamic forms, so form goals are a dropdown, not a guess.
            'forms' => Form::all()->map(fn ($form) => [
                'handle' => $form->handle(),
                'title' => $form->title(),
            ])->values()->all(),
            'email' => [
                'recipients' => $settings->get('report_recipients', []),
                'weekly' => (bool) $settings->get('report_weekly', false),
                'monthly' => (bool) $settings->get('report_monthly', false),
            ],
        ]);
    }

    public function save(Request $request)
    {
        $this->authorizeConfigure();

        $data = $request->validate([
            'goals' => ['array'],
            'goals.*.handle' => ['nullable', 'string', 'max:64'],
            'goals.*.name' => ['required', 'string', 'max:100'],
            'goals.*.type' => ['required', Rule::in(Goal::TYPES)],
            'goals.*.value' => ['required', 'string', 'max:255'],
            'email.recipients' => ['array', 'max:25'],
            'email.recipients.*' => ['email'],
            'email.weekly' => ['boolean'],
            'email.monthly' => ['boolean'],
        ]);

        $repository = app(GoalRepository::class);

        $goals = collect($data['goals'] ?? [])->map(fn ($goal) => new Goal(
            handle: $goal['handle'] ?: $repository->makeHandle($goal['name']),
            name: $goal['name'],
            type: $goal['type'],
            value: $goal['value'],
        ));

        $repository->replaceAll($goals);

        $settings = app(Settings::class);
        $settings->put('report_recipients', array_values($data['email']['recipients'] ?? []));
        $settings->put('report_weekly', (bool) ($data['email']['weekly'] ?? false));
        $settings->put('report_monthly', (bool) ($data['email']['monthly'] ?? false));

        return response()->json(['goals' => $repository->all()->map->toArray()->values()->all()]);
    }

    private function authorizeConfigure(): void
    {
        abort_unless(User::current()->can('configure insights'), 403);
    }
}
