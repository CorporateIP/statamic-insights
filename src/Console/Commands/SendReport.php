<?php

namespace CorporateIp\Insights\Console\Commands;

use CorporateIp\Insights\Dashboard\Metrics;
use CorporateIp\Insights\Mail\InsightsReport;
use CorporateIp\Insights\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the periodic stats digest to the recipients configured in the CP
 * (Insights settings). Scheduled by the addon; the command itself checks the
 * matching toggle, so an unticked frequency simply sends nothing.
 */
class SendReport extends Command
{
    protected $signature = 'insights:send-report {period : weekly or monthly}';

    protected $description = 'Send the periodic Insights email report';

    public function handle(Settings $settings): int
    {
        $period = $this->argument('period');

        if (! in_array($period, ['weekly', 'monthly'], true)) {
            $this->components->error('Period must be weekly or monthly.');

            return self::FAILURE;
        }

        if (! $settings->get("report_{$period}", false)) {
            $this->components->info("The {$period} report is disabled.");

            return self::SUCCESS;
        }

        $recipients = array_filter((array) $settings->get('report_recipients', []));

        if ($recipients === []) {
            $this->components->warn('No report recipients configured.');

            return self::SUCCESS;
        }

        // Weekly: the last 7 full days. Monthly: the previous calendar month.
        $metrics = $period === 'monthly'
            ? new Metrics(
                'custom',
                from: now()->subMonthNoOverflow()->startOfMonth()->toDateString(),
                to: now()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            )
            : new Metrics(
                'custom',
                from: today()->subDays(7)->toDateString(),
                to: today()->subDay()->toDateString(),
            );

        $payload = $metrics->payload();

        $locale = app()->getLocale();
        $label = sprintf(
            '%s – %s',
            Carbon::parse($payload['range']['from'])->locale($locale)->translatedFormat('j M Y'),
            Carbon::parse($payload['range']['to'])->locale($locale)->translatedFormat('j M Y'),
        );

        foreach ($recipients as $recipient) {
            Mail::to($recipient)->send(new InsightsReport($period, $label, $payload));
        }

        $this->components->info(sprintf('%s report sent to %d recipient(s).', ucfirst($period), count($recipients)));

        return self::SUCCESS;
    }
}
