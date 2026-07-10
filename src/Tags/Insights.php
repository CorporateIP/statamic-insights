<?php

namespace CorporateIp\Insights\Tags;

use Statamic\Tags\Tags;

class Insights extends Tags
{
    /**
     * {{ insights:tracker consent_getter="cookieConsent" }}
     *
     * Renders the tracker script tag. `consent_getter` names a window function
     * that returns true|'accepted' while cookies are allowed - the tracker
     * checks it on every pageview and only then uses visitor/session cookies.
     */
    public function tracker(): string
    {
        $getter = $this->params->get('consent_getter', config('insights.consent_js_getter'));

        $attributes = $getter ? ' data-consent-getter="'.e($getter).'"' : '';

        return '<script src="/!/statamic-insights/tracker.js" defer'.$attributes.'></script>';
    }
}
