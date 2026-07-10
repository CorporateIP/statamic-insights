<?php

namespace CorporateIp\Insights\Console\Commands;

use CorporateIp\Insights\Support\Geo;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Downloads/refreshes the local IP→country database (DB-IP Country Lite,
 * CC-BY-4.0 - "IP Geolocation by DB-IP", https://db-ip.com). Schedule monthly:
 * the file is republished at the start of every month.
 */
class GeoUpdate extends Command
{
    protected $signature = 'insights:geo-update';

    protected $description = 'Download or refresh the local IP-to-country database used for Insights country stats';

    public function handle(): int
    {
        $path = config('insights.geo.database_path');

        // The current month's file appears on the 1st; fall back to last month's.
        foreach ([now(), now()->subMonth()] as $month) {
            $url = sprintf('https://download.db-ip.com/free/dbip-country-lite-%s.mmdb.gz', $month->format('Y-m'));

            $this->components->task("Downloading {$url}", function () use ($url, $path) {
                $response = Http::timeout(120)->get($url);

                if ($response->failed()) {
                    return false;
                }

                $database = gzdecode($response->body());

                if ($database === false || $database === '') {
                    return false;
                }

                File::ensureDirectoryExists(dirname($path));
                File::put($path, $database);

                return true;
            });

            if (is_file($path) && filemtime($path) >= now()->subMinutes(5)->getTimestamp()) {
                Geo::flush();
                $this->components->info(sprintf('Country database updated (%s). IP Geolocation by DB-IP (db-ip.com), CC-BY-4.0.', $month->format('Y-m')));

                return self::SUCCESS;
            }
        }

        $this->components->error('Could not download the country database. Country stats will be empty until this succeeds.');

        return self::FAILURE;
    }
}
