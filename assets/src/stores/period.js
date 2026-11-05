import { defineStore } from 'pinia';
import { computed, ref } from 'vue';

// Plage de dates partagée tableau de bord / statistiques, synchronisée avec la query string
// (?period=last12 ou ?from=…&to=… pour « Personnalisé »).

const pad = (n) => String(n).padStart(2, '0');
function iso(d) {
    return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}
function firstOfMonth(d) {
    return new Date(d.getFullYear(), d.getMonth(), 1);
}
function today() {
    const d = new Date();
    return new Date(d.getFullYear(), d.getMonth(), d.getDate());
}

export const PRESET_KEYS = ['month', 'lastMonth', 'last3', 'last6', 'last12', 'year', 'lastYear', 'all', 'custom'];

export const usePeriodStore = defineStore('period', () => {
    const preset = ref('last12'); // défaut : 12 derniers mois
    const custom = ref({ from: null, to: null }); // 'YYYY-MM-DD'

    // Plage résolue {from, to} en ISO (dates locales).
    const range = computed(() => {
        const t = today();
        switch (preset.value) {
            case 'month':
                return { from: iso(firstOfMonth(t)), to: iso(t) };
            case 'lastMonth': {
                const from = new Date(t.getFullYear(), t.getMonth() - 1, 1);
                const to = new Date(t.getFullYear(), t.getMonth(), 0);
                return { from: iso(from), to: iso(to) };
            }
            case 'last3':
                return { from: iso(firstOfMonth(new Date(t.getFullYear(), t.getMonth() - 2))), to: iso(t) };
            case 'last6':
                return { from: iso(firstOfMonth(new Date(t.getFullYear(), t.getMonth() - 5))), to: iso(t) };
            case 'year':
                return { from: `${t.getFullYear()}-01-01`, to: iso(t) };
            case 'lastYear':
                return { from: `${t.getFullYear() - 1}-01-01`, to: `${t.getFullYear() - 1}-12-31` };
            case 'all':
                return { from: '1900-01-01', to: iso(t) };
            case 'custom':
                return { from: custom.value.from, to: custom.value.to };
            case 'last12':
            default:
                return { from: iso(firstOfMonth(new Date(t.getFullYear(), t.getMonth() - 11))), to: iso(t) };
        }
    });

    // Params pour les appels API stats (from/to inclus).
    const params = computed(() => {
        const r = range.value;
        const p = {};
        if (r.from) p.from = r.from;
        if (r.to) p.to = r.to;
        return p;
    });

    // Query string reflétant la sélection.
    const query = computed(() =>
        preset.value === 'custom'
            ? { from: custom.value.from, to: custom.value.to }
            : { period: preset.value },
    );

    function setPreset(p) {
        if (PRESET_KEYS.includes(p)) preset.value = p;
    }

    function setCustom(from, to) {
        custom.value = { from, to };
        preset.value = 'custom';
    }

    // Applique la query string de la route (une seule fois au montage du filtre).
    function applyQuery(q) {
        if (q.from && q.to) {
            custom.value = { from: String(q.from), to: String(q.to) };
            preset.value = 'custom';
        } else if (q.period && PRESET_KEYS.includes(String(q.period))) {
            preset.value = String(q.period);
        }
    }

    return { preset, custom, range, params, query, setPreset, setCustom, applyQuery };
});
