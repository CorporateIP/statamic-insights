/*! Statamic Insights tracker - first-party, no third-party requests. */
(function () {
    'use strict';

    // Headless browsers announce themselves here; don't count them.
    if (navigator.webdriver) return;

    var script = document.currentScript || {};
    var ds = script.dataset || {};
    var endpoint = ds.endpoint || '/!/statamic-insights/hit';
    var getter = ds.consentGetter; // name of a window function returning true|'accepted' when cookies are allowed
    var ID_COOKIE = '_insights_id';
    var SESSION_COOKIE = '_insights_s';
    var consentOverride = null;

    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (c) {
            var r = (Math.random() * 16) | 0;
            return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
        });
    }

    function getCookie(name) {
        var m = document.cookie.match('(?:^|; )' + name + '=([^;]*)');
        return m ? decodeURIComponent(m[1]) : null;
    }

    function setCookie(name, value, minutes) {
        document.cookie = name + '=' + encodeURIComponent(value) + '; Max-Age=' + minutes * 60 + '; Path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    }

    function dropCookie(name) {
        document.cookie = name + '=; Max-Age=0; Path=/';
    }

    function consentGranted() {
        if (consentOverride !== null) return consentOverride;
        if (getter && typeof window[getter] === 'function') {
            var v = window[getter]();
            return v === true || v === 'accepted';
        }
        return false;
    }

    // With consent: ensure the visitor cookie (~13 months) + a rolling 30-minute
    // session cookie. Without consent (or after withdrawal): remove both - the
    // beacon then counts the pageview anonymously.
    function syncCookies() {
        if (consentGranted()) {
            if (!getCookie(ID_COOKIE)) setCookie(ID_COOKIE, uuid(), 395 * 24 * 60);
            setCookie(SESSION_COOKIE, getCookie(SESSION_COOKIE) || uuid(), 30);
        } else {
            dropCookie(ID_COOKIE);
            dropCookie(SESSION_COOKIE);
        }
    }

    function hit() {
        syncCookies();
        try {
            fetch(endpoint, {
                method: 'POST',
                keepalive: true,
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    path: location.pathname + location.search,
                    referrer: document.referrer || null,
                }),
            });
        } catch (e) {
            /* analytics must never break the page */
        }
    }

    // Public API for cookie banners: window._insights.consent(true|false)
    window._insights = {
        consent: function (granted) {
            consentOverride = !!granted;
            syncCookies();
        },
    };

    // Chrome prerenders pages the visitor may never open (e.g. from search
    // results); only count once the page is actually shown.
    if (document.prerendering) {
        document.addEventListener('prerenderingchange', hit, { once: true });
    } else {
        hit();
    }
})();
