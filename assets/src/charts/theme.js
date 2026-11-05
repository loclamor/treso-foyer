import { computed, ref } from 'vue';
import { formatCompactEuro, formatMoney } from '@/utils/format';

// Mode sombre : suit la classe `app-dark` posée sur <html> par App.vue.
const dark = ref(false);
let started = false;

function start() {
    if (started || typeof document === 'undefined') return;
    started = true;
    dark.value = document.documentElement.classList.contains('app-dark');
    new MutationObserver(() => {
        dark.value = document.documentElement.classList.contains('app-dark');
    }).observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
}

// Singleton partagé : un seul observateur, tous les graphiques réagissent au bascule clair/sombre.
export function useChartTheme() {
    start();
    const mode = computed(() => (dark.value ? 'dark' : 'light'));
    return { mode };
}

// Encre / chrome par mode (dataviz : primaire, secondaire, muted, grille, axe, surface).
export function chartColors(mode) {
    const d = mode === 'dark';
    return {
        ink: d ? '#f0efec' : '#0b0b0b',
        inkSecondary: d ? '#c3c2b7' : '#52514e',
        muted: '#898781',
        grid: d ? '#2c2c2a' : '#e1e0d9',
        axis: d ? '#383835' : '#c3c2b7',
        surface: d ? '#1a1a19' : '#fcfcfb',
        others: d ? '#5c5b56' : '#b5b3ab',
    };
}

export const CHART_FONT = "Inter, system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";

// Infobulle par défaut : crosshair sur lignes/aires, une infobulle pour toutes les séries à ce X.
export function baseTooltip(colors, { trigger = 'axis', valueFormatter = (v) => formatMoney(v) } = {}) {
    return {
        trigger,
        axisPointer: trigger === 'axis' ? { type: 'cross', lineStyle: { color: colors.muted, width: 1 }, crossStyle: { color: colors.muted, width: 1 } } : undefined,
        backgroundColor: colors.surface,
        borderColor: colors.grid,
        borderWidth: 1,
        textStyle: { color: colors.ink, fontFamily: CHART_FONT, fontSize: 12 },
        valueFormatter,
    };
}

// Un seul axe Y valeur, graduations en k€ compacts, grille fine pleine (jamais de pointillés).
export function valueAxis(colors, { formatter = (v) => formatCompactEuro(v) } = {}) {
    return {
        type: 'value',
        axisLine: { show: false },
        axisTick: { show: false },
        axisLabel: { color: colors.muted, formatter, fontFamily: CHART_FONT },
        splitLine: { lineStyle: { color: colors.grid, width: 1 } },
    };
}

// Axe X catégoriel discret ; limite le nombre de libellés pour rester lisible en 375 px.
export function categoryAxis(colors, labels, { maxTicks = 6 } = {}) {
    const interval = labels.length > maxTicks ? Math.ceil(labels.length / maxTicks) - 1 : 0;
    return {
        type: 'category',
        data: labels,
        axisLine: { lineStyle: { color: colors.axis, width: 1 } },
        axisTick: { show: false },
        axisLabel: { color: colors.inkSecondary, interval, fontFamily: CHART_FONT },
    };
}

// Axe Y catégoriel (barres horizontales) : libellés en encre secondaire.
export function categoryAxisY(colors, labels) {
    return {
        type: 'category',
        data: labels,
        axisLine: { show: false },
        axisTick: { show: false },
        // Libellés longs tronqués (le nom complet reste dans l'infobulle et la vue tableau).
        axisLabel: { color: colors.inkSecondary, fontFamily: CHART_FONT, width: 170, overflow: 'truncate' },
    };
}

export function baseLegend(colors, overrides = {}) {
    return {
        bottom: 0,
        icon: 'rect',
        itemWidth: 12,
        itemHeight: 8,
        itemGap: 16,
        textStyle: { color: colors.inkSecondary, fontFamily: CHART_FONT },
        ...overrides,
    };
}

// Grille avec marge pour la légende du bas ; `containLabel` garde les graduations dans la hauteur fournie.
export function baseGrid(overrides = {}) {
    return { left: 8, right: 16, top: 16, bottom: 36, containLabel: true, ...overrides };
}

// Barres fines, extrémités arrondies 4 px, « trou » de 2 px couleur surface entre segments empilés.
export function barItemStyle(color, surface) {
    return { color, borderRadius: 4, borderColor: surface, borderWidth: 2 };
}
