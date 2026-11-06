<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Card from 'primevue/card';
import Skeleton from 'primevue/skeleton';
import ProgressBar from 'primevue/progressbar';
import DataTable from 'primevue/datatable';
import Column from 'primevue/column';
import Button from 'primevue/button';
import Tag from 'primevue/tag';
import { useToast } from 'primevue/usetoast';
import { useConfirm } from 'primevue/useconfirm';
import { useScopeStore } from '@/stores/scope';
import { usePeriodStore } from '@/stores/period';
import { useCategoriesStore } from '@/stores/categories';
import { getSummary } from '@/api/summary';
import { getBalance, getCategoriesStats, getMonthly, getOverview } from '@/api/stats';
import { deleteOperation } from '@/api/operations';
import { apiMessage } from '@/api/http';
import { escapeHtml, formatDate, formatDateTime, formatMoney, formatMonth, formatPercent, monthBounds } from '@/utils/format';
import { chartColors, useChartTheme, baseTooltip, baseLegend, baseGrid, valueAxis, categoryAxis, categoryAxisY, barItemStyle } from '@/charts/theme';
import { colorForCategory, foldToOthers, OTHERS_LABEL, slotColor, withAlpha } from '@/charts/palette';
import PeriodFilter from '@/components/PeriodFilter.vue';
import KpiTile from '@/components/KpiTile.vue';
import ChartCard from '@/components/ChartCard.vue';
import VChart from '@/components/VChart.vue';
import MoneyAmount from '@/components/MoneyAmount.vue';
import ManualEntryDialog from '@/components/ManualEntryDialog.vue';
import ReconcileDialog from '@/components/ReconcileDialog.vue';

const router = useRouter();
const scope = useScopeStore();
const period = usePeriodStore();
const categoriesStore = useCategoriesStore();
const toast = useToast();
const confirm = useConfirm();
const theme = useChartTheme();

// ---------- compteurs existants (summary) ----------
const summary = ref(null);
const loadingSummary = ref(true);

const percentCategorized = computed(() => {
    const s = summary.value;
    if (!s || !s.operationsCount) return 0;
    return Math.round((100 * s.categorizedCount) / s.operationsCount);
});

async function loadSummary() {
    loadingSummary.value = true;
    try {
        summary.value = await getSummary(scope.params);
    } catch (e) {
        toast.add({ severity: 'error', summary: 'Tableau de bord indisponible', detail: apiMessage(e), life: 6000 });
    } finally {
        loadingSummary.value = false;
    }
}

// Relevés mensuels manquants (trous dans la série ou relevés échus non importés).
const MISSING_SHOWN = 6;
const MISSING_REASON = {
    gap: { label: 'manquant', severity: 'danger' },
    overdue: { label: 'en retard', severity: 'warn' },
};
const missingStatements = computed(() => summary.value?.missingStatements ?? []);
// Alias affiché seulement si le périmètre contient plusieurs comptes.
const missingShowAccount = computed(() => (summary.value?.accounts?.length ?? 0) > 1);

// ---------- saisies manuelles en attente de relevé ----------
const staleEntries = computed(() => summary.value?.staleManualEntries ?? []);
const awaitingCount = computed(() => summary.value?.awaitingStatementCount ?? 0);

const entryDialogVisible = ref(false);
const reconcileVisible = ref(false);
const reconcileEntry = ref(null);

function openCreateEntry() {
    entryDialogVisible.value = true;
}

function openReconcileEntry(entry) {
    // Objet partiel (résumé) : ReconcileDialog recharge la saisie complète via GET /operations/{id}.
    reconcileEntry.value = entry;
    reconcileVisible.value = true;
}

function confirmDeleteEntry(entry) {
    confirm.require({
        header: 'Supprimer la saisie',
        message: `Supprimer la saisie « ${entry.label} » du ${formatDate(entry.spentAt)} ? Elle n'est pas encore rapprochée d'un relevé et sera définitivement supprimée.`,
        icon: 'pi pi-exclamation-triangle',
        acceptLabel: 'Supprimer',
        rejectLabel: 'Annuler',
        acceptClass: 'p-button-danger',
        accept: async () => {
            try {
                await deleteOperation(entry.id);
                toast.add({ severity: 'success', summary: 'Saisie supprimée', life: 3000 });
                loadSummary();
            } catch (e) {
                toast.add({ severity: 'error', summary: 'Suppression impossible', detail: apiMessage(e), life: 6000 });
            }
        },
    });
}

function onEntrySaved() {
    loadSummary();
}

function onReconciled() {
    loadSummary();
}

// ---------- KPI (stats/overview) ----------
const overview = ref(null);
const loadingOverview = ref(true);
const overviewError = ref('');

async function loadOverview() {
    loadingOverview.value = true;
    overviewError.value = '';
    try {
        overview.value = await getOverview(scope.params, period.params);
    } catch (e) {
        overviewError.value = apiMessage(e, 'KPIs indisponibles');
    } finally {
        loadingOverview.value = false;
    }
}

// Variation en % vs période de comparaison : {text, up} ou null.
function deltaInfo(current, previous, label) {
    if (current == null || previous == null || previous === 0) return null;
    const pct = ((current - previous) / Math.abs(previous)) * 100;
    return { text: `${pct >= 0 ? '+' : ''}${Math.round(pct)} % ${label}`, up: current >= previous };
}

// Libellé de comparaison selon la nature de comparison.current (lu côté back) :
// mois calendaires complets, « mois à date » (mêmes jours du mois en cours), ou autre.
function comparisonLabel(current) {
    if (!current?.from || !current?.to) return 'vs période précédente';
    const d1 = new Date(`${current.from}T00:00:00`);
    const d2 = new Date(`${current.to}T00:00:00`);
    const lastDayOfTo = new Date(d2.getFullYear(), d2.getMonth() + 1, 0).getDate();
    if (d1.getDate() === 1 && d2.getDate() === lastDayOfTo) return 'vs mois complets précédents';
    if (d1.getFullYear() === d2.getFullYear() && d1.getMonth() === d2.getMonth()) return 'vs mêmes jours du mois précédent';
    return 'vs période précédente';
}

// Sous-texte du taux d'épargne : plage des mois complets réellement utilisée.
function savingsSubtext(o) {
    if (!o.savingsRateFrom || !o.savingsRateTo) return "Part des revenus non dépensée";
    const fromMonth = o.savingsRateFrom.slice(0, 7);
    const toMonth = o.savingsRateTo.slice(0, 7);
    if (fromMonth === toMonth) return `Mois complet : ${formatMonth(fromMonth)}`;
    return `Mois complets : ${formatMonth(fromMonth)} → ${formatMonth(toMonth)}`;
}

const kpis = computed(() => {
    const o = overview.value;
    if (!o) return [];
    // Deltas sur comparison (comparable vs previousAligned) : le mois en cours incomplet
    // n'y entre pas, contrairement à « previous » (déprécié).
    const cmp = o.comparison ?? null;
    const label = comparisonLabel(cmp?.current);
    const dExpense = cmp
        ? deltaInfo(cmp.current.expense, cmp.previous.expense, label)
        : deltaInfo(o.expense, o.previous?.expense, 'vs période précédente');
    const dIncome = cmp
        ? deltaInfo(cmp.current.income, cmp.previous.income, label)
        : deltaInfo(o.income, o.previous?.income, 'vs période précédente');
    const dNet = cmp
        ? deltaInfo(cmp.current.net, cmp.previous.net, label)
        : deltaInfo(o.net, o.previous?.net, 'vs période précédente');
    return [
        {
            label: 'Dépenses',
            value: formatMoney(o.expense),
            delta: dExpense,
            favorable: dExpense ? !dExpense.up : null, // moins dépenser = favorable
            subtext: `Moy. mensuelle (12 mois) : ${formatMoney(o.average12?.expense)}`,
        },
        {
            label: 'Revenus',
            value: formatMoney(o.income),
            delta: dIncome,
            favorable: dIncome ? dIncome.up : null,
            subtext: `Moy. mensuelle (12 mois) : ${formatMoney(o.average12?.income)}`,
        },
        {
            label: 'Solde net',
            value: formatMoney(o.net),
            delta: dNet,
            favorable: dNet ? dNet.up : null,
            subtext: '',
        },
        {
            label: "Taux d'épargne",
            value: formatPercent(o.savingsRate),
            delta: null,
            favorable: null,
            subtext: savingsSubtext(o),
        },
    ];
});

// ---------- dépenses mensuelles par poste (stats/monthly) ----------
const monthly = ref(null);
const prevMonthly = ref(null); // période précédente (comparaison), null si inactive
const loadingMonthly = ref(true);
const monthlyError = ref('');

async function loadMonthly() {
    loadingMonthly.value = true;
    monthlyError.value = '';
    try {
        // Graphe mensuel : le mois en cours y est estimé jusqu'à la fin du mois ⇒ la période
        // précédente va jusqu'à la FIN de son dernier mois (pas « mêmes jours », réservé aux totaux).
        const prevParams = period.previousParams
            ? { from: period.previousParams.from, to: monthBounds(period.previousParams.to.slice(0, 7)).to }
            : null;
        const [cur, prev] = await Promise.all([
            getMonthly(scope.params, period.params),
            prevParams ? getMonthly(scope.params, prevParams) : Promise.resolve(null),
        ]);
        monthly.value = cur;
        prevMonthly.value = prev;
    } catch (e) {
        monthlyError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingMonthly.value = false;
    }
}

const monthlyRoots = computed(() => {
    const m = monthly.value;
    if (!m) return [];
    const series = m.byRoot.map((r) => ({ category: r.category, values: r.values }));
    if (m.uncategorized?.some((v) => v)) series.push({ category: null, values: m.uncategorized });
    return foldToOthers(series, theme.mode.value);
});

// Période précédente : mêmes postes (repliés comme la période courante), alignés mois par mois
// (le mois i de la période précédente est dessiné à côté du mois i courant).
const PREV_ID = 'prev';
const PREV_INCOME_NAME = 'Revenus (période précédente)';
const isPrevSeriesId = (id) => typeof id === 'string' && id.startsWith(`${PREV_ID}:`);

const prevMonthlyRoots = computed(() => {
    const p = prevMonthly.value;
    if (!p) return [];
    const series = p.byRoot.map((r) => ({ category: r.category, values: r.values }));
    if (p.uncategorized?.some((v) => v)) series.push({ category: null, values: p.uncategorized });
    return foldToOthers(series, theme.mode.value);
});

// Séries barres « période précédente » : nom identique au poste courant (la légende masque
// les deux ensemble), pile distincte à gauche de la pile courante, couleur atténuée.
function previousSeries(m, c) {
    const p = prevMonthly.value;
    if (!p) return [];
    const n = m.months.length;
    const align = (values) => Array.from({ length: n }, (_, i) => values?.[i] ?? null);
    const out = prevMonthlyRoots.value.map((r) => ({
        id: `${PREV_ID}:${r.name}`,
        name: r.name,
        type: 'bar',
        stack: 'previous',
        data: align(r.values),
        barMaxWidth: 26,
        itemStyle: { ...barItemStyle(withAlpha(r.color, 0.35), c.surface) },
        emphasis: { disabled: true },
    }));
    out.push({
        id: `${PREV_ID}:income`,
        name: PREV_INCOME_NAME,
        type: 'line',
        data: align(p.income),
        symbol: 'none',
        lineStyle: { width: 1.5, type: 'dotted', color: c.muted, opacity: 0.8 },
        itemStyle: { color: c.muted },
    });
    return out;
}

// Estimations du mois en cours (mois incomplet) :
// - revenus : la ligne réelle s'arrête au mois précédent ; un segment en pointillés part de
//   son dernier point et rejoint l'estimation du mois en cours (estimate.income) ;
// - dépenses : barre fantôme en pointillés (estimate.expense) superposée à la pile du mois.
const INCOME_ESTIMATE_NAME = 'Revenus estimés';
const EXPENSE_ESTIMATE_NAME = 'Dépenses estimées';
// Séries hachurées « <Poste> (attendu) » : récurrent restant du mois en cours, par poste.
// Préfixe d'id des séries hachurées (ECharts exige des id uniques : préfixe + clé du poste).
const RECURRING_SERIES_ID = 'recurring-expected';
const isRecurringSeriesId = (id) => typeof id === 'string' && id.startsWith(`${RECURRING_SERIES_ID}:`);
const RECURRING_SUFFIX = ' (attendu)';

function currentIndex(m) {
    return m.estimate ? m.months.indexOf(m.estimate.month) : -1;
}

// 'AAAA-MM-JJ' → 'jj/mm' (date courte dans l'infobulle).
function shortDate(iso) {
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso ?? ''));
    return m ? `${m[3]}/${m[2]}` : String(iso ?? '');
}

function incomeEstimateSeries(m, c, idx) {
    const prevIdx = idx - 1;
    const data = m.months.map((mo, i) => {
        if (i === idx) return { value: m.estimate.income, symbolSize: 8 };
        if (i === prevIdx) return { value: m.income[prevIdx], symbolSize: 0 };
        return null;
    });
    return {
        name: INCOME_ESTIMATE_NAME,
        type: 'line',
        data,
        connectNulls: false,
        lineStyle: { width: 2, type: 'dashed', color: c.inkSecondary },
        itemStyle: { color: c.inkSecondary },
    };
}

// Part variable restante estimée : segment vide en pointillés EN HAUT de la pile du mois en cours
// (réel + hachuré récurrent + ce segment = estimate.expense). Un segment empilé plutôt qu'une barre
// superposée (barGap -100 %) : compatible avec la pile « période précédente » à côté.
function expenseEstimateSeries(m, c, idx) {
    const est = m.estimate;
    const recurringRemaining = est.recurring?.expense?.remaining ?? 0;
    const variableRemaining = Math.max(0, est.expense - est.spent - recurringRemaining);
    return {
        name: EXPENSE_ESTIMATE_NAME,
        type: 'bar',
        stack: 'depenses',
        barMaxWidth: 26,
        data: m.months.map((mo, i) => (i === idx ? variableRemaining : null)),
        itemStyle: { color: 'transparent', borderColor: c.muted, borderWidth: 1.5, borderType: 'dashed', borderRadius: 4 },
        emphasis: { disabled: true },
    };
}

// Séries hachurées « <Poste> (attendu) » : récurrent restant du mois en cours, par poste de dépense.
// Même stack que les barres réelles, placées APRÈS elles (donc au-dessus de la pile), valeur
// uniquement au mois en cours. rootId null ou racine repliée dans « Autres » ⇒ série « Autres ».
// Ces séries ne sont ni en légende ni cliquables (id RECURRING_SERIES_ID).
function recurringExpectedSeries(m, c, roots, idx) {
    const byRoot = m.estimate?.recurring?.expense?.byRoot ?? [];
    const groups = new Map(); // clé de regroupement → {name, color, remaining}
    for (const b of byRoot) {
        if (!b.remaining || b.remaining <= 0) continue;
        const rootId = b.rootId ?? null;
        const root = rootId === null
            ? roots.find((r) => r.others)
            : roots.find((r) => r.category?.id === rootId);
        let key, name, color;
        if (root) {
            key = `root:${root.category?.id ?? 'others'}`;
            name = root.name;
            color = root.color;
        } else {
            const cat = rootId === null ? null : categoriesStore.byId.get(rootId);
            const slot = Number(cat?.chartSlot);
            if (cat && Number.isInteger(slot) && slot >= 1 && slot <= 8) {
                // racine à emplacement absente du graphe (aucune dépense réelle sur la période)
                key = `root:${rootId}`;
                name = cat.name;
                color = colorForCategory(cat, theme.mode.value);
            } else {
                // sans emplacement de couleur ⇒ repliée dans « Autres », comme les barres réelles
                const others = roots.find((r) => r.others);
                key = 'root:others';
                name = others?.name ?? OTHERS_LABEL;
                color = others?.color ?? c.others;
            }
        }
        const g = groups.get(key) ?? { name, color, remaining: 0 };
        g.remaining += b.remaining;
        groups.set(key, g);
    }
    const decalColor = theme.mode.value === 'dark' ? 'rgba(255,255,255,0.5)' : 'rgba(255,255,255,0.6)';
    return [...groups.entries()].map(([key, g]) => ({
        id: `${RECURRING_SERIES_ID}:${key}`,
        name: `${g.name}${RECURRING_SUFFIX}`,
        type: 'bar',
        stack: 'depenses',
        data: m.months.map((mo, i) => (i === idx ? g.remaining : null)),
        barMaxWidth: 26,
        z: 2,
        itemStyle: {
            color: withAlpha(g.color, 0.45), // fond en opacité réduite
            borderRadius: 4,
            borderColor: c.surface,
            borderWidth: 2,
            decal: { symbol: 'rect', dashArrayX: [1, 0], dashArrayY: [3, 5], rotation: -Math.PI / 4, color: decalColor },
        },
        emphasis: { disabled: true },
    }));
}

// Infobulle du graphe mensuel : TABLEAU compact, une ligne par poste, colonnes côte à côte
// « mois courant | attendu (récurrent restant, mois en cours) | mois précédent », puis totaux,
// estimation et prochaines échéances (listes courtes). Construite depuis les données (pas
// depuis params) pour couvrir aussi les postes absents d'une des deux périodes.
const TIP_MAX_EXPENSE_ITEMS = 4;
const TIP_MAX_INCOME_ITEMS = 3;
const TIP_LABEL_MAX = 24;

function tipLabel(s) {
    const t = String(s ?? '');
    return escapeHtml(t.length > TIP_LABEL_MAX ? `${t.slice(0, TIP_LABEL_MAX - 1)}…` : t);
}

function monthlyTooltip(c, m, prev, roots, prevRoots, expectedByName) {
    const t = baseTooltip(c);
    t.formatter = (params) => {
        const list = Array.isArray(params) ? params : [params];
        if (!list.length) return '';
        const i = list[0].dataIndex;
        const estimate = m.estimate;
        const isCurrent = !!estimate && m.months[i] === estimate.month;
        const showExpected = isCurrent && expectedByName.size > 0;
        const prevMonth = prev?.months?.[i] ?? null;
        const money = (v) => (v ? formatMoney(v) : '—');
        const dot = (color) => `<span style="display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:6px;background:${color}"></span>`;
        const td = 'style="padding:1px 0 1px 12px;text-align:right;white-space:nowrap"';
        const tdMuted = 'style="padding:1px 0 1px 12px;text-align:right;white-space:nowrap;opacity:.65"';

        // Postes : union période courante + précédente (même nom ⇒ même ligne).
        const rows = new Map();
        for (const r of roots) rows.set(r.name, { color: r.color, cur: r.values[i] ?? 0, prev: 0, exp: 0 });
        for (const r of prevRoots) {
            const row = rows.get(r.name) ?? { color: r.color, cur: 0, prev: 0, exp: 0 };
            row.prev = r.values?.[i] ?? 0;
            rows.set(r.name, row);
        }
        for (const [name, exp] of showExpected ? expectedByName : []) {
            const row = rows.get(name) ?? { color: c.others, cur: 0, prev: 0, exp: 0 };
            row.exp = exp;
            rows.set(name, row);
        }

        const head = [`<th style="text-align:left;font-weight:600">${escapeHtml(formatMonth(m.months[i]))}</th>`, `<th ${td}>Réel</th>`];
        if (showExpected) head.push(`<th ${td}>Attendu</th>`);
        if (prev) head.push(`<th ${tdMuted}>${prevMonth ? escapeHtml(formatMonth(prevMonth)) : 'Préc.'}</th>`);

        const body = [];
        for (const [name, r] of rows) {
            if (!r.cur && !r.prev && !r.exp) continue;
            const cells = [`<td style="white-space:nowrap">${dot(r.color)}${escapeHtml(name)}</td>`, `<td ${td}>${money(r.cur)}</td>`];
            if (showExpected) cells.push(`<td ${td}>${money(r.exp)}</td>`);
            if (prev) cells.push(`<td ${tdMuted}>${money(r.prev)}</td>`);
            body.push(`<tr>${cells.join('')}</tr>`);
        }
        const sep = 'style="border-top:1px solid rgba(128,128,128,.35)"';
        const totalExpected = showExpected ? [...expectedByName.values()].reduce((a, b) => a + b, 0) : 0;
        const totalCells = [`<td style="font-weight:600">Dépenses</td>`, `<td ${td}><strong>${money(m.expense[i])}</strong></td>`];
        if (showExpected) totalCells.push(`<td ${td}>${money(totalExpected)}</td>`);
        if (prev) totalCells.push(`<td ${tdMuted}>${money(prev.expense?.[i])}</td>`);
        body.push(`<tr ${sep}>${totalCells.join('')}</tr>`);
        const incomeCells = [`<td style="font-weight:600">Revenus</td>`, `<td ${td}>${money(m.income[i])}</td>`];
        if (showExpected) incomeCells.push(`<td ${td}>${money(estimate.recurring?.income?.remaining)}</td>`);
        if (prev) incomeCells.push(`<td ${tdMuted}>${money(prev.income?.[i])}</td>`);
        body.push(`<tr>${incomeCells.join('')}</tr>`);

        const parts = [`<table style="border-collapse:collapse;font-size:12px"><thead><tr>${head.join('')}</tr></thead><tbody>${body.join('')}</tbody></table>`];

        if (prev) {
            const pe = prev.expense?.[i] ?? 0;
            const ce = isCurrent ? estimate.expense : (m.expense[i] ?? 0);
            if (pe) {
                const delta = Math.round(((ce - pe) / pe) * 100);
                parts.push(`<div style="opacity:.7;margin-top:4px">Dépenses${isCurrent ? ' estimées' : ''} vs ${escapeHtml(formatMonth(prevMonth))} : ${delta >= 0 ? '+' : ''}${delta} %</div>`);
            }
        }
        if (isCurrent) {
            const variable = estimate.variable ?? {};
            parts.push(
                `<div style="margin-top:6px"><strong>Estimation fin de mois</strong> : dépenses ${formatMoney(estimate.expense)} · revenus ${formatMoney(estimate.income)}</div>`
                + `<div style="opacity:.7">dont variable restant ${formatMoney(variable.expenseRemaining ?? 0)} (moyenne ${estimate.basisMonths} mois)</div>`,
            );
            const expItems = (estimate.recurring?.expense?.items ?? []).filter((it) => !it.paid);
            const incItems = (estimate.recurring?.income?.items ?? []).filter((it) => !it.paid);
            const itemLine = (it) => `${shortDate(it.expectedDate)} ${tipLabel(it.merchant)} <strong>${formatMoney(it.amount)}</strong>`;
            if (expItems.length) {
                const more = expItems.length > TIP_MAX_EXPENSE_ITEMS ? ` · +${expItems.length - TIP_MAX_EXPENSE_ITEMS} autres` : '';
                parts.push(`<div style="margin-top:6px"><strong>Prochaines échéances</strong></div><div style="opacity:.85">${expItems.slice(0, TIP_MAX_EXPENSE_ITEMS).map(itemLine).join('<br/>')}${more}</div>`);
            }
            if (incItems.length) {
                const more = incItems.length > TIP_MAX_INCOME_ITEMS ? ` · +${incItems.length - TIP_MAX_INCOME_ITEMS} autres` : '';
                parts.push(`<div style="margin-top:6px"><strong>Revenus attendus</strong></div><div style="opacity:.85">${incItems.slice(0, TIP_MAX_INCOME_ITEMS).map(itemLine).join('<br/>')}${more}</div>`);
            }
        }
        return parts.join('');
    };
    return t;
}

const monthlyOption = computed(() => {
    const m = monthly.value;
    if (!m) return {};
    const c = chartColors(theme.mode.value);
    const roots = monthlyRoots.value;
    const labels = m.months.map((mo) => formatMonth(mo));
    // Période précédente d'abord : sa pile se place à GAUCHE de la pile courante.
    const series = previousSeries(m, c);
    series.push(...roots.map((r) => ({
        name: r.name,
        type: 'bar',
        stack: 'depenses',
        data: r.values,
        barMaxWidth: 26,
        itemStyle: barItemStyle(r.color, c.surface),
        emphasis: { focus: 'series' },
    })));
    const idx = currentIndex(m);
    // Hachuré « (attendu) » : récurrent restant du mois en cours, au-dessus de la pile réelle.
    const recurringSeries = idx !== -1 ? recurringExpectedSeries(m, c, roots, idx) : [];
    series.push(...recurringSeries);
    // Récurrent restant par poste (nom du poste) pour la colonne « Attendu » de l'infobulle.
    const expectedByName = new Map(recurringSeries.map((rs) => [rs.name.slice(0, -RECURRING_SUFFIX.length), rs.data[idx] ?? 0]));
    // Ligne des revenus sur le MÊME axe (une seule échelle en €) ; mois en cours retiré
    // (incomplet) quand une estimation le remplace.
    series.push({
        name: 'Revenus',
        type: 'line',
        data: idx === -1 ? m.income : m.income.map((v, i) => (i === idx ? null : v)),
        symbolSize: 8,
        lineStyle: { width: 2, color: c.inkSecondary },
        itemStyle: { color: c.inkSecondary },
    });
    if (idx !== -1) {
        if (idx > 0) series.push(incomeEstimateSeries(m, c, idx));
        series.push(expenseEstimateSeries(m, c, idx));
    }
    const legend = baseLegend(c);
    // Les séries « (attendu) » n'apparaissent pas dans la légende.
    // Les postes de la période précédente partagent le nom du poste courant (une seule entrée).
    // Pastille = couleur du poste courant (sinon celle, atténuée, de la 1re série du même nom :
    // la période précédente, placée en tête pour être à gauche).
    const rootColor = new Map(roots.map((r) => [r.name, r.color]));
    legend.data = [...new Set(series.filter((s) => !isRecurringSeriesId(s.id)).map((s) => s.name))].map((name) =>
        rootColor.has(name) ? { name, itemStyle: { color: rootColor.get(name) } } : name,
    );
    return {
        tooltip: monthlyTooltip(c, m, prevMonthly.value, roots, prevMonthlyRoots.value, expectedByName),
        legend,
        grid: baseGrid(),
        xAxis: categoryAxis(c, labels),
        yAxis: valueAxis(c),
        series,
    };
});

const monthlyEmpty = computed(() => !monthly.value || !monthly.value.months?.length);

// Clic sur un segment ⇒ opérations du mois cliqué pour cette racine.
function onMonthlyClick(e) {
    if (e.componentType !== 'series' || e.seriesType !== 'bar' || !monthly.value) return;
    // la barre fantôme et les séries hachurées « (attendu) » ne sont pas cliquables
    if (e.seriesName === EXPENSE_ESTIMATE_NAME || isRecurringSeriesId(e.seriesId) || isPrevSeriesId(e.seriesId)) return;
    // les séries hachurées décalent seriesIndex : la racine se retrouve par son nom
    const root = monthlyRoots.value.find((r) => r.name === e.seriesName);
    if (!root) return;
    const month = monthly.value.months[e.dataIndex];
    const b = monthBounds(month);
    const query = { from: b.from, to: b.to };
    if (root?.category) query.categoryId = root.category.id;
    else query.uncategorized = '1';
    router.push({ name: 'operations', query });
}

const monthlyTableRows = computed(() => {
    const m = monthly.value;
    if (!m) return [];
    const idx = currentIndex(m);
    return m.months.map((mo, i) => {
        const row = { month: formatMonth(mo), Revenus: m.income[i], Dépenses: m.expense[i] };
        if (prevMonthly.value) row['Dépenses (préc.)'] = prevMonthly.value.expense[i] ?? null;
        for (const r of monthlyRoots.value) row[r.name] = r.values[i] ?? 0;
        // récurrent attendu : renseigné seulement au mois en cours (estimé)
        row['Récurrent attendu'] = i === idx ? (m.estimate?.recurring?.expense?.remaining ?? null) : null;
        return row;
    });
});

// ---------- répartition des dépenses (stats/categories) avec drill ----------
const drillParent = ref(null);
const cats = ref(null);
const prevCats = ref(null); // période précédente (comparaison), null si inactive
const loadingCats = ref(true);
const catsError = ref('');

async function loadCategories() {
    loadingCats.value = true;
    catsError.value = '';
    try {
        const opts = { direction: 'expense', parentId: drillParent.value };
        const prevParams = period.previousParams;
        const [res, prevRes] = await Promise.all([
            getCategoriesStats(scope.params, period.params, opts),
            prevParams ? getCategoriesStats(scope.params, prevParams, opts) : Promise.resolve(null),
        ]);
        cats.value = res.rows;
        prevCats.value = prevRes?.rows ?? null;
    } catch (e) {
        catsError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingCats.value = false;
    }
}

watch(drillParent, loadCategories);

const drillParentName = computed(() => {
    if (!drillParent.value) return '';
    return categoriesStore.byId.get(drillParent.value)?.name ?? '';
});

const catsOption = computed(() => {
    const items = cats.value;
    if (!items) return {};
    const c = chartColors(theme.mode.value);
    const rows = items.map((it) => ({
        name: it.category?.name ?? 'Non catégorisé',
        value: it.total,
        count: it.count,
        share: it.share,
        category: it.category,
        itemStyle: { color: colorForCategory(it.category, theme.mode.value), borderRadius: 4 },
    }));
    // Barres horizontales triées : l'axe Y affiche le plus grand en haut.
    const labels = rows.map((r) => r.name).reverse();
    // Période précédente : même poste (id de catégorie, null = non catégorisé), barre atténuée.
    const prevByKey = new Map((prevCats.value ?? []).map((it) => [it.category?.id ?? 'none', it.total]));
    const hasPrev = prevCats.value !== null;
    const prevValue = (r) => prevByKey.get(r.category?.id ?? 'none') ?? 0;
    const series = [
        {
            name: 'Dépenses',
            type: 'bar',
            data: rows.map((r) => ({ value: r.value, itemStyle: r.itemStyle, category: r.category, count: r.count, share: r.share })).reverse(),
            barMaxWidth: 22,
            label: { show: false },
            emphasis: { focus: 'series' },
        },
    ];
    if (hasPrev) {
        // Ajoutée en second : sur un axe Y catégoriel, elle se dessine juste au-dessus de la barre courante.
        series.push({
            name: 'Période précédente',
            type: 'bar',
            data: rows.map((r) => ({
                value: prevValue(r),
                category: r.category,
                itemStyle: { color: withAlpha(colorForCategory(r.category, theme.mode.value), 0.35), borderRadius: 4 },
            })).reverse(),
            barMaxWidth: 22,
            barGap: '15%',
            emphasis: { disabled: true },
        });
    }
    const tooltip = { ...baseTooltip(c), trigger: hasPrev ? 'axis' : 'item', axisPointer: { type: 'shadow' } };
    if (hasPrev) {
        const range = period.previousRange;
        tooltip.formatter = (params) => {
            const list = Array.isArray(params) ? params : [params];
            const cur = list.find((p) => p.seriesName === 'Dépenses');
            const prv = list.find((p) => p.seriesName === 'Période précédente');
            const curV = cur?.value ?? 0;
            const prvV = prv?.value ?? 0;
            const delta = prvV ? Math.round(((curV - prvV) / prvV) * 100) : null;
            const name = escapeHtml(list[0]?.axisValueLabel ?? list[0]?.name ?? '');
            return [
                `<strong>${name}</strong>`,
                `${cur?.marker ?? ''} Période : <strong>${formatMoney(curV)}</strong>`,
                `${prv?.marker ?? ''} Précédente${range ? ` (${formatDate(range.from)} → ${formatDate(range.to)})` : ''} : ${formatMoney(prvV)}`,
                delta === null ? '' : `<span style="opacity:.7">Variation : ${delta >= 0 ? '+' : ''}${delta} %</span>`,
            ].filter(Boolean).join('<br/>');
        };
    }
    return {
        tooltip,
        grid: baseGrid({ left: 8, right: 48, top: 8, bottom: 8 }),
        xAxis: valueAxis(c),
        yAxis: categoryAxisY(c, labels),
        series,
    };
});

const catsEmpty = computed(() => !cats.value || !cats.value.length);

function onCatsClick(e) {
    const item = e.data;
    if (!item || e.seriesName === 'Période précédente') return;
    const b = period.range;
    if (!drillParent.value && item.category) {
        // racine ⇒ détail des sous-catégories
        drillParent.value = item.category.id;
        return;
    }
    const query = { from: b.from, to: b.to };
    if (item.category) query.categoryId = item.category.id;
    else query.uncategorized = '1';
    router.push({ name: 'operations', query });
}

// ---------- solde (stats/balance, granularité mois) ----------
const balance = ref(null);
const loadingBalance = ref(true);
const balanceError = ref('');

async function loadBalance() {
    loadingBalance.value = true;
    balanceError.value = '';
    try {
        balance.value = await getBalance(scope.params, period.params, 'month');
    } catch (e) {
        balanceError.value = apiMessage(e, 'Graphique indisponible');
    } finally {
        loadingBalance.value = false;
    }
}

const balanceOption = computed(() => {
    const b = balance.value;
    if (!b) return {};
    const c = chartColors(theme.mode.value);
    const labels = b.dates.map((d) => formatDate(d));
    const series = [
        {
            name: 'Solde total',
            type: 'line',
            data: b.total,
            connectNulls: false,
            symbolSize: 8,
            lineStyle: { width: 2, color: slotColor(1, theme.mode.value) },
            itemStyle: { color: slotColor(1, theme.mode.value) },
        },
    ];
    (b.accounts ?? []).forEach((a, i) => {
        const color = i < 7 ? slotColor(i + 2, theme.mode.value) : c.others;
        series.push({
            name: a.account.alias,
            type: 'line',
            data: a.values,
            connectNulls: false,
            symbolSize: 6,
            lineStyle: { width: 2, type: 'dashed', color },
            itemStyle: { color },
        });
    });
    return {
        tooltip: baseTooltip(c),
        legend: baseLegend(c, { bottom: 26 }),
        grid: baseGrid({ bottom: 68, top: 8 }),
        xAxis: categoryAxis(c, labels),
        yAxis: valueAxis(c),
        series,
        dataZoom: [
            { type: 'inside' },
            {
                type: 'slider',
                bottom: 0,
                height: 14,
                borderColor: c.grid,
                backgroundColor: c.surface,
                fillerColor: c.grid,
                handleStyle: { color: c.inkSecondary },
                moveHandleStyle: { color: c.inkSecondary },
                labelFormatter: () => '',
            },
        ],
    };
});

const balanceEmpty = computed(() => !balance.value || !balance.value.dates?.length);

const balanceTableRows = computed(() => {
    const b = balance.value;
    if (!b) return [];
    return b.dates.map((d, i) => {
        const row = { date: formatDate(d), 'Solde total': b.total[i] };
        for (const a of b.accounts ?? []) row[a.account.alias] = a.values[i];
        return row;
    });
});

// ---------- chargement global ----------
function loadStats() {
    loadOverview();
    loadMonthly();
    loadCategories();
    loadBalance();
}

watch(() => [scope.params, period.params], loadStats, { deep: true });
// Case « Comparer » seule : seuls les graphes comparés se rechargent.
watch(() => period.compareActive, () => {
    loadMonthly();
    loadCategories();
});
onMounted(() => {
    loadSummary();
    loadStats();
});
</script>

<template>
    <div>
        <div class="page-header">
            <h1>Tableau de bord</h1>
            <Button label="Saisir une dépense" icon="pi pi-plus" @click="openCreateEntry" />
        </div>

        <PeriodFilter />

        <div v-if="loadingOverview && !overview" class="kpi-grid">
            <Card v-for="i in 4" :key="i"><Skeleton height="4.5rem" /></Card>
        </div>
        <div v-else-if="overviewError" class="kpi-grid">
            <Card>
                <template #content>
                    <p class="text-muted">{{ overviewError }}</p>
                    <Button label="Réessayer" icon="pi pi-refresh" size="small" outlined severity="secondary" @click="loadOverview" />
                </template>
            </Card>
        </div>
        <div v-else-if="overview" class="kpi-grid">
            <KpiTile
                v-for="k in kpis"
                :key="k.label"
                :label="k.label"
                :value="k.value"
                :subtext="k.subtext"
                :delta-text="k.delta?.text ?? ''"
                :delta-up="k.delta?.up ?? true"
                :favorable="k.favorable"
            />
        </div>

        <div class="charts-grid">
            <ChartCard
                class="span-2"
                title="Dépenses mensuelles par poste"
                subtitle="Barres empilées par catégorie racine, ligne des revenus sur le même axe — clic sur un segment pour voir les opérations du mois"
                aria-label="Barres empilées des dépenses mensuelles par poste de dépense, avec la ligne des revenus sur le même axe en euros"
                :loading="loadingMonthly"
                :error="monthlyError"
                :empty="monthlyEmpty"
                :height="360"
                @retry="loadMonthly"
            >
                <VChart :option="monthlyOption" aria-label="Dépenses mensuelles par poste et revenus" @click="onMonthlyClick" />
                <template #table>
                    <DataTable :value="monthlyTableRows" size="small" scrollable scroll-height="320px">
                        <template #empty>Aucune donnée</template>
                        <Column field="month" header="Mois" />
                        <Column v-for="r in monthlyRoots" :key="r.name" :field="r.name" :header="r.name" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data[r.name]) }}</template>
                        </Column>
                        <Column field="Dépenses" header="Dépenses" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data['Dépenses']) }}</template>
                        </Column>
                        <Column v-if="prevMonthly" field="Dépenses (préc.)" header="Dépenses (préc.)" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data['Dépenses (préc.)']) }}</template>
                        </Column>
                        <Column field="Récurrent attendu" header="Récurrent attendu" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data['Récurrent attendu'] !== null && data['Récurrent attendu'] !== undefined">{{ formatMoney(data['Récurrent attendu']) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                        <Column field="Revenus" header="Revenus" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data['Revenus']) }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Répartition des dépenses"
                :subtitle="drillParent ? `Sous-catégories de « ${drillParentName} »` : 'Clic sur un poste pour voir ses sous-catégories'"
                aria-label="Barres horizontales des dépenses par poste, triées du plus grand au plus petit"
                :loading="loadingCats"
                :error="catsError"
                :empty="catsEmpty"
                :height="340"
                @retry="loadCategories"
            >
                <template #actions>
                    <Button
                        v-if="drillParent"
                        label="Toutes"
                        icon="pi pi-arrow-left"
                        text
                        size="small"
                        severity="secondary"
                        :aria-label="'Retour à toutes les catégories'"
                        @click="drillParent = null"
                    />
                    <span v-if="drillParent" class="text-muted breadcrumb">/ {{ drillParentName }}</span>
                </template>
                <VChart :option="catsOption" aria-label="Répartition des dépenses par poste" @click="onCatsClick" />
                <template #table>
                    <DataTable :value="cats ?? []" size="small" scrollable scroll-height="320px">
                        <template #empty>Aucune donnée</template>
                        <Column header="Poste">
                            <template #body="{ data }">
                                <span class="cat-dot" :style="{ background: colorForCategory(data.category, theme.mode.value) }" aria-hidden="true" />
                                {{ data.category?.name ?? 'Non catégorisé' }}
                            </template>
                        </Column>
                        <Column field="total" header="Dépenses" class="text-right">
                            <template #body="{ data }">{{ formatMoney(data.total) }}</template>
                        </Column>
                        <Column field="share" header="Part" class="text-right">
                            <template #body="{ data }">{{ formatPercent(data.share) }}</template>
                        </Column>
                        <Column field="count" header="Nb opérations" class="text-right">
                            <template #body="{ data }">{{ data.count.toLocaleString('fr-FR') }}</template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>

            <ChartCard
                class="span-2"
                title="Solde"
                subtitle="Solde de fin de mois reconstitué — zoom avec le curseur du bas"
                aria-label="Courbe du solde de fin de mois, total et par compte si plusieurs comptes dans le périmètre"
                :loading="loadingBalance"
                :error="balanceError"
                :empty="balanceEmpty"
                :height="320"
                @retry="loadBalance"
            >
                <VChart :option="balanceOption" aria-label="Évolution du solde" />
                <template #table>
                    <DataTable :value="balanceTableRows" size="small" scrollable scroll-height="300px">
                        <template #empty>Aucune donnée</template>
                        <Column field="date" header="Date" />
                        <Column field="Solde total" header="Solde total" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data['Solde total'] !== null">{{ formatMoney(data['Solde total']) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                        <Column v-for="a in balance?.accounts ?? []" :key="a.account.id" :field="a.account.alias" :header="a.account.alias" class="text-right">
                            <template #body="{ data }">
                                <span v-if="data[a.account.alias] !== null && data[a.account.alias] !== undefined">{{ formatMoney(data[a.account.alias]) }}</span>
                                <span v-else class="text-muted">—</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </ChartCard>
        </div>

        <div v-if="loadingSummary" class="cards-grid section">
            <Card v-for="i in 4" :key="i"><Skeleton height="4rem" /></Card>
        </div>

        <template v-else-if="summary">
            <div class="cards-grid section">
                <Card>
                    <template #title>Opérations</template>
                    <template #content>
                        <p class="big-number">{{ summary.operationsCount.toLocaleString('fr-FR') }}</p>
                        <p class="text-muted">du {{ formatDate(summary.firstDate) }} au {{ formatDate(summary.lastDate) }}</p>
                    </template>
                </Card>
                <Card>
                    <template #title>Catégorisées</template>
                    <template #content>
                        <p class="big-number">{{ percentCategorized }} %</p>
                        <ProgressBar :value="percentCategorized" :show-value="false" />
                        <p class="text-muted">
                            {{ summary.categorizedCount.toLocaleString('fr-FR') }} catégorisées ·
                            <RouterLink :to="{ name: 'to-categorize' }">{{ summary.uncategorizedCount.toLocaleString('fr-FR') }} à catégoriser</RouterLink>
                        </p>
                    </template>
                </Card>
                <Card v-if="(summary.vehicleUnassignedCount ?? 0) > 0">
                    <template #title>Véhicules à affecter</template>
                    <template #content>
                        <p class="big-number">{{ summary.vehicleUnassignedCount.toLocaleString('fr-FR') }}</p>
                        <p>
                            <RouterLink :to="{ name: 'operations', query: { vehicleUnassigned: '1' } }">
                                opération(s) de catégorie véhicule sans véhicule
                            </RouterLink>
                        </p>
                    </template>
                </Card>
                <Card>
                    <template #title>Doublons probables</template>
                    <template #content>
                        <p class="big-number">{{ summary.probableDuplicatesCount }}</p>
                        <p><RouterLink :to="{ name: 'duplicates' }">Vérifier les doublons</RouterLink></p>
                    </template>
                </Card>
                <Card>
                    <template #title>Virements à vérifier</template>
                    <template #content>
                        <p class="big-number">{{ summary.pendingTransfersCount }}</p>
                        <p><RouterLink :to="{ name: 'transfers', query: { status: 'auto' } }">Voir les virements à vérifier</RouterLink></p>
                    </template>
                </Card>
                <Card>
                    <template #title>Relevés manquants</template>
                    <template #content>
                        <p class="big-number">{{ missingStatements.length }}</p>
                        <p v-if="!missingStatements.length" class="text-muted">
                            <i class="pi pi-check-circle missing-ok" aria-hidden="true" /> Série de relevés complète
                        </p>
                        <template v-else>
                            <ul class="missing-list" aria-label="Relevés mensuels manquants">
                                <li v-for="m in missingStatements.slice(0, MISSING_SHOWN)" :key="`${m.account.id}-${m.month}`">
                                    <span class="missing-month">{{ formatMonth(m.month) }}</span>
                                    <span v-if="missingShowAccount" class="text-muted"> · {{ m.account.alias }}</span>
                                    <Tag :value="MISSING_REASON[m.reason]?.label ?? m.reason" :severity="MISSING_REASON[m.reason]?.severity ?? 'secondary'" class="missing-tag" />
                                </li>
                            </ul>
                            <p v-if="missingStatements.length > MISSING_SHOWN" class="text-muted">
                                et {{ missingStatements.length - MISSING_SHOWN }} autre(s)
                            </p>
                            <p class="text-muted missing-hint">À déposer dans <code>data/inbox/</code>, puis ranger et importer.</p>
                        </template>
                    </template>
                </Card>
            </div>

            <Card v-if="staleEntries.length" class="section">
                <template #title>Saisies sans relevé depuis plus de 15 jours</template>
                <template #content>
                    <ul class="stale-list" aria-label="Saisies manuelles en attente de relevé depuis plus de 15 jours">
                        <li v-for="e in staleEntries" :key="e.id">
                            <span class="stale-date">{{ formatDate(e.spentAt) }}</span>
                            <span class="stale-label" :title="e.label">{{ e.label }}</span>
                            <MoneyAmount :amount="e.amount" />
                            <span class="text-muted">{{ e.account?.alias ?? e.account?.numberMasked }}</span>
                            <Tag :value="`${e.ageDays} j`" severity="danger" class="stale-age" v-tooltip.top="'Saisie non rapprochée depuis ce nombre de jours'" />
                            <span class="stale-actions">
                                <Button
                                    icon="pi pi-link"
                                    size="small"
                                    text
                                    severity="secondary"
                                    aria-label="Rapprocher avec le relevé"
                                    v-tooltip.top="'Rapprocher avec une ligne du relevé'"
                                    @click="openReconcileEntry(e)"
                                />
                                <Button
                                    icon="pi pi-trash"
                                    size="small"
                                    text
                                    severity="danger"
                                    aria-label="Supprimer la saisie"
                                    v-tooltip.top="'Supprimer la saisie'"
                                    @click="confirmDeleteEntry(e)"
                                />
                            </span>
                        </li>
                    </ul>
                    <p class="text-muted stale-total">
                        {{ awaitingCount.toLocaleString('fr-FR') }} saisie(s) en attente de relevé au total —
                        <RouterLink :to="{ name: 'operations', query: { awaitingStatement: '1' } }">voir toutes les saisies en attente</RouterLink>
                    </p>
                </template>
            </Card>

            <Card class="section">
                <template #title>Dernier import</template>
                <template #content>
                    <p v-if="summary.lastImportAt">{{ formatDateTime(summary.lastImportAt) }}</p>
                    <p v-else class="text-muted">Aucun import pour le moment.</p>
                </template>
            </Card>

            <Card class="section">
                <template #title>Comptes</template>
                <template #content>
                    <DataTable :value="summary.accounts" size="small">
                        <template #empty>Aucun compte</template>
                        <Column field="alias" header="Alias" />
                        <Column field="label" header="Libellé" />
                        <Column field="numberMasked" header="Numéro" />
                        <Column header="Solde" class="text-right">
                            <template #body="{ data }">
                                <MoneyAmount v-if="data.balance" :amount="data.balance.amount" />
                                <span v-else class="text-muted">—</span>
                                <span v-if="data.balance" class="text-muted solde-date">au {{ formatDate(data.balance.date) }}</span>
                            </template>
                        </Column>
                    </DataTable>
                </template>
            </Card>
        </template>

        <ManualEntryDialog v-model:visible="entryDialogVisible" :entry="null" @saved="onEntrySaved" />
        <ReconcileDialog v-model:visible="reconcileVisible" :entry="reconcileEntry" @done="onReconciled" />
    </div>
</template>

<style scoped>
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 1rem;
    flex-wrap: wrap;
}
.kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(13rem, 1fr));
    gap: 1rem;
    margin-bottom: 1rem;
}
.charts-grid {
    display: grid;
    /* 2 colonnes (1 sur petit écran) : jamais de carte isolée sur un tiers de largeur. */
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 1rem;
}
.span-2 {
    grid-column: 1 / -1;
}
.cards-grid {
    display: grid;
    /* 12rem : les 5 cartes tiennent sur une ligne en 1280 px. */
    grid-template-columns: repeat(auto-fit, minmax(12rem, 1fr));
    gap: 1rem;
}
.section {
    margin-top: 1rem;
}
.big-number {
    font-size: 1.6rem;
    font-weight: 700;
    margin: 0 0 0.25rem;
}
.solde-date {
    margin-left: 0.4rem;
    font-size: 0.8rem;
}
.missing-ok {
    color: var(--p-green-500);
}
.missing-list {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
}
.missing-list li {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.15rem 0;
}
.missing-month::first-letter {
    text-transform: uppercase;
}
.missing-tag {
    margin-left: auto;
    font-size: 0.7rem;
}
.missing-hint {
    font-size: 0.8rem;
}
.stale-list {
    list-style: none;
    margin: 0 0 0.5rem;
    padding: 0;
}
.stale-list li {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.3rem 0;
    flex-wrap: wrap;
}
.stale-date {
    font-weight: 600;
    white-space: nowrap;
}
.stale-label {
    flex: 1 1 auto;
    min-width: 8rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}
.stale-age {
    font-size: 0.7rem;
}
.stale-actions {
    display: flex;
    gap: 0.15rem;
}
.stale-total {
    font-size: 0.85rem;
    margin-bottom: 0;
}
.breadcrumb {
    font-size: 0.85rem;
    white-space: nowrap;
}
@media (max-width: 900px) {
    .charts-grid {
        grid-template-columns: minmax(0, 1fr);
    }
}
</style>
