<?php

namespace CorporateIp\Insights\Tests;

use CorporateIp\Insights\Dashboard\Metrics;
use Illuminate\Foundation\Testing\RefreshDatabase;

class TranslationsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every language Statamic ships itself. English needs no file: the keys
     * ARE the English strings.
     */
    private const LOCALES = [
        'ar', 'az', 'cs', 'da', 'de', 'de_CH', 'es', 'et', 'fa', 'fr', 'hu',
        'id', 'it', 'ja', 'ms', 'nb', 'nl', 'pl', 'pt', 'pt_BR', 'ru', 'sl',
        'sv', 'tr', 'uk', 'vi', 'zh_CN', 'zh_TW',
    ];

    /**
     * The full set of translatable UI strings. Add here when adding a __()
     * string to the dashboard/widget - the tests below force every locale
     * file to match this list exactly.
     */
    private const KEYS = [
        'Insights',
        'View Insights',
        'Pageviews',
        'Pageviews (7d)',
        'Unique visitors',
        'Sessions',
        'Active now',
        'Visitors',
        'Views',
        'Today',
        '7 days',
        '30 days',
        '90 days',
        'Top pages',
        'Page',
        'Sources',
        'Referrers',
        'Campaigns',
        'Campaign',
        'Source',
        'Technology',
        'Devices',
        'Browsers',
        'Countries',
        'View dashboard',
        'Being viewed right now',
        'No data yet.',
        'No visits recorded yet',
        'Data appears here as soon as the tracker on the website registers its first pageviews.',
        'No external referrers in this period.',
        'No UTM-tagged visits yet. Add ?utm_campaign=… to newsletter and social links to see them here.',
        'No data yet - run insights:geo-update to enable country stats.',
        'views',
        'visitors',
        'No consented visitors in the window; showing anonymous pageviews.',
        '6 months',
        '12 months',
        'All time',
        'Custom range',
        'To',
        'All sites',
        'Bounce rate',
        'Avg. visit duration',
        'Operating systems',
        'Goals',
        'Goal',
        'Conversions',
        'Conversion rate',
        'Export CSV',
        'Filter by this value',
        'Remove filter',
        'Referrer',
        'Country',
        'Device',
        'Browser',
        'OS',
        'Not available for aggregated ranges.',
        'Sum of daily unique visitors - repeat visitors count once per day.',
        'Aggregated daily data - bounce rate and visit duration need raw pageviews.',
        'Filters read raw pageviews only - the range was limited to the retention window.',
        'Insights settings',
        'Page visit',
        'Custom event',
        'Form submission',
        'Back to dashboard',
        'Conversions to count: a page being visited, a custom event, or a Statamic form being submitted.',
        'No goals defined yet.',
        'Add goal',
        'Email reports',
        'A stats digest sent to the addresses below - recipients do not need a CP account.',
        'Recipients',
        'One email address per line',
        'Weekly (Monday)',
        'Monthly (1st)',
        'View in Insights',
        'Configure Insights',
        'Monthly Insights report',
        'Weekly Insights report',
    ];

    /**
     * Strings Statamic core already ships translations for - the addon uses
     * them but deliberately does NOT duplicate them (duplicating would risk
     * overriding core's established wording in 28 languages).
     */
    private const CORE_PROVIDED = [
        'Save',
        'Saved',
        'Name',
        'Remove',
        'Something went wrong',
        'From',
        'Apply',
    ];

    private function translations(string $locale): array
    {
        $path = __DIR__."/../lang/{$locale}.json";

        $this->assertFileExists($path, "Missing translation file for locale {$locale}");

        $translations = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($translations, "{$locale}.json must decode to an object");

        return $translations;
    }

    public function test_every_statamic_locale_has_a_complete_translation_file(): void
    {
        foreach (self::LOCALES as $locale) {
            $translations = $this->translations($locale);

            $this->assertSame(
                self::KEYS,
                array_keys($translations),
                "{$locale}.json keys must exactly match the canonical key list (same order)",
            );

            foreach ($translations as $key => $value) {
                $this->assertIsString($value, "{$locale}.json: value for '{$key}' must be a string");
                $this->assertNotSame('', trim($value), "{$locale}.json: empty translation for '{$key}'");
            }
        }
    }

    public function test_technical_tokens_survive_translation(): void
    {
        $tokens = [
            'No UTM-tagged visits yet. Add ?utm_campaign=… to newsletter and social links to see them here.' => '?utm_campaign=',
            'No data yet - run insights:geo-update to enable country stats.' => 'insights:geo-update',
        ];

        foreach (self::LOCALES as $locale) {
            $translations = $this->translations($locale);

            foreach ($tokens as $key => $token) {
                $this->assertStringContainsString(
                    $token,
                    $translations[$key],
                    "{$locale}.json: translation for '{$key}' lost the literal token '{$token}'",
                );
            }
        }
    }

    public function test_every_hardcoded_translation_call_uses_a_known_key(): void
    {
        $files = array_merge(
            glob(__DIR__.'/../resources/js/**/*.vue'),
            glob(__DIR__.'/../src/*.php'),
            glob(__DIR__.'/../src/**/*.php'),
        );

        foreach ($files as $file) {
            // Comments may cite __() calls without translating anything.
            $contents = preg_replace(['!/\*.*?\*/!s', '![ \t]*//[^\n]*!'], '', file_get_contents($file));

            preg_match_all("/__\\('([^']+)'\\)/u", $contents, $matches);

            foreach ($matches[1] as $key) {
                $this->assertContains(
                    $key,
                    [...self::KEYS, ...self::CORE_PROVIDED],
                    basename($file)." translates '{$key}' but it is missing from the canonical key list (and therefore from every lang file)",
                );
            }
        }
    }

    public function test_core_provided_keys_actually_exist_in_statamic(): void
    {
        $core = json_decode(file_get_contents(__DIR__.'/../vendor/statamic/cms/lang/nl.json'), true);

        foreach (self::CORE_PROVIDED as $key) {
            $this->assertArrayHasKey(
                $key,
                $core,
                "Statamic core no longer translates '{$key}' - ship it in the addon lang files instead",
            );
        }
    }

    public function test_json_translations_resolve_for_php_and_the_cp_dictionary(): void
    {
        app()->setLocale('nl');

        // PHP-side __(): would have stayed English before lang/ was registered
        // as a JSON path (Statamic only auto-registers namespaced PHP files).
        $this->assertSame('Paginaweergaven', __('Pageviews'));

        // CP JS dictionary: Translator::toJson flattens the JSON group as '*'.
        $this->assertSame('Paginaweergaven', app('translator')->toJson()['*.Pageviews'] ?? null);
    }

    public function test_timeseries_labels_and_cache_follow_the_app_locale(): void
    {
        app()->setLocale('en');
        $english = Metrics::cached('7d')['timeseries']['labels'];

        app()->setLocale('ru');
        $russian = Metrics::cached('7d')['timeseries']['labels'];

        // Month abbreviations translate (ru is Cyrillic year-round, so this
        // assertion cannot rot with the calendar).
        $this->assertMatchesRegularExpression('/[а-я]/u', implode(' ', $russian));
        $this->assertDoesNotMatchRegularExpression('/[а-я]/u', implode(' ', $english));

        // And the two locales must not share a cache entry.
        $this->assertNotSame($english, $russian);
    }
}
