// The CP's active language - Statamic exposes the translator locale to JS, and
// its Localize middleware normalizes to underscores (zh_CN) while Intl wants
// BCP 47 hyphens (zh-CN).
export function cpLocale() {
    const locale = window.Statamic?.$config?.get('translationLocale') || document.documentElement.lang || 'en';

    return locale.replace(/_/g, '-');
}

let formatter = null;

// Numbers format in the CP language rather than the browser language, so digit
// grouping matches the translated labels around them. Lazy: Statamic's config
// is only populated once the CP has booted.
export function fmt(n) {
    formatter ??= new Intl.NumberFormat(cpLocale());

    return formatter.format(n);
}
