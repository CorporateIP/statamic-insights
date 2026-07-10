/**
 * Chart colors derived from the CP theme, so the dashboard always matches
 * Statamic's own look (light and dark). Canvas can't resolve var()/oklch()
 * directly, so we let a throwaway canvas normalize whatever the theme
 * declares into plain RGB.
 */

let cached = null;

export function primaryRgb() {
    if (cached) return cached;

    const declared = getComputedStyle(document.documentElement).getPropertyValue('--color-primary').trim() || '#7c3aed';

    const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    context.fillStyle = declared;
    context.fillRect(0, 0, 1, 1);

    const [r, g, b] = context.getImageData(0, 0, 1, 1).data;

    return (cached = [r, g, b]);
}

export function primary(alpha = 1) {
    const [r, g, b] = primaryRgb();

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

export function isDark() {
    return document.documentElement.classList.contains('dark');
}

export function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/**
 * Categorical palette for identity data (browsers, devices): distinct hues in a
 * FIXED order, slot 1 anchored to the CP primary. Slots 2-8 are a
 * colorblind-validated set (worst adjacent deutan/protan ΔE 24.2 on light,
 * 10.3 on dark - the dark column is separately chosen steps, not a flip).
 * Never cycle or re-derive hues; a 9th category folds into the same cycle.
 */
const CATEGORICAL = {
    light: ['#e34948', '#e87ba4', '#eb6834', '#2a78d6', '#1baf7a', '#eda100', '#008300'],
    dark: ['#e66767', '#d55181', '#d95926', '#3987e5', '#199e70', '#c98500', '#008300'],
};

function toRgb(color) {
    const context = document.createElement('canvas').getContext('2d', { willReadFrequently: true });
    context.fillStyle = color;
    context.fillRect(0, 0, 1, 1);

    return context.getImageData(0, 0, 1, 1).data;
}

export function palette(count) {
    const slots = [primary(), ...CATEGORICAL[isDark() ? 'dark' : 'light']];

    return Array.from({ length: count }, (_, i) => slots[i % slots.length]);
}

/** Hover: the same hue nudged toward the ink color - never a different hue. */
export function paletteHover(count) {
    const target = isDark() ? 255 : 0;

    return palette(count).map((color) => {
        const [r, g, b] = toRgb(color);
        const mix = (value) => Math.round(value + (target - value) * 0.12);

        return `rgb(${mix(r)}, ${mix(g)}, ${mix(b)})`;
    });
}

export function surfaceColor() {
    return isDark() ? 'rgb(31, 41, 55)' : '#ffffff';
}

export function tickColor() {
    return isDark() ? 'rgba(255,255,255,0.55)' : 'rgba(0,0,0,0.45)';
}

/** Shared tooltip styling that matches the CP look instead of Chart.js defaults. */
export function tooltipOptions() {
    return {
        backgroundColor: 'rgba(17, 24, 39, 0.92)',
        titleColor: 'rgba(255, 255, 255, 0.95)',
        bodyColor: 'rgba(255, 255, 255, 0.8)',
        cornerRadius: 6,
        padding: 10,
        boxPadding: 4,
        usePointStyle: true,
    };
}
