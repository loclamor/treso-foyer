// Formatage montants (centimes), dates et conversion euros ↔ centimes sans flottant.

const eur = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });

export function formatMoney(cents) {
    if (cents === null || cents === undefined || Number.isNaN(Number(cents))) return '—';
    return eur.format(Number(cents) / 100);
}

// 'YYYY-MM-DD' → 'jj/mm/aaaa'
export function formatDate(iso) {
    if (!iso) return '—';
    const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso));
    if (!m) return String(iso);
    return `${m[3]}/${m[2]}/${m[1]}`;
}

// Horodatage ISO 8601 → 'jj/mm/aaaa hh:mm' (heure locale)
export function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return String(iso);
    const pad = (n) => String(n).padStart(2, '0');
    return `${pad(d.getDate())}/${pad(d.getMonth() + 1)}/${d.getFullYear()} ${pad(d.getHours())}:${pad(d.getMinutes())}`;
}

// Classe CSS débit/crédit d'un montant en centimes.
export function amountClass(cents) {
    if (cents < 0) return 'amount-debit';
    if (cents > 0) return 'amount-credit';
    return '';
}

// '12,34' | '12.34' | '-5' → 1234 | -500 (entier, aucun flottant). null si invalide.
export function parseEuros(str) {
    if (typeof str !== 'string') return null;
    const s = str.trim().replace(/[\s ]/g, '').replace(',', '.');
    if (!/^-?\d+(\.\d{1,2})?$/.test(s)) return null;
    const neg = s.startsWith('-');
    const [intPart, decPart = ''] = (neg ? s.slice(1) : s).split('.');
    const cents = parseInt(intPart, 10) * 100 + parseInt((decPart + '00').slice(0, 2), 10);
    return neg ? -cents : cents;
}

// 1234 → '12,34' (pré-remplir une saisie en euros, sans flottant). '' si vide.
export function centsToEuros(cents) {
    if (cents === null || cents === undefined) return '';
    const abs = Math.abs(Number(cents));
    const str = `${Math.floor(abs / 100)},${String(abs % 100).padStart(2, '0')}`;
    return Number(cents) < 0 ? `-${str}` : str;
}

// Objet Date (DatePicker) → 'YYYY-MM-DD' en heure locale. null si invalide.
export function dateToIso(date) {
    if (!(date instanceof Date) || Number.isNaN(date.getTime())) return null;
    const pad = (n) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

// 'AAAA-MM-JJ' → Date locale (minuit, sans décalage de fuseau) ; null si vide ou invalide.
export function isoToDate(iso) {
    if (!iso) return null;
    const [y, m, d] = String(iso).split('-').map(Number);
    return y && m && d ? new Date(y, m - 1, d) : null;
}

// Variation en % de previous à current (base en valeur absolue : le signe suit le sens de
// l'évolution même si la base est négative) ; null si pas de base.
export function percentChange(current, previous) {
    if (current == null || !previous) return null;
    return ((current - previous) / Math.abs(previous)) * 100;
}

// k€ compact pour les axes de graphiques (centimes → « 1,2 k€ »).
const kEur = new Intl.NumberFormat('fr-FR', { notation: 'compact', maximumFractionDigits: 1 });
export function formatCompactEuro(cents) {
    if (cents === null || cents === undefined || Number.isNaN(Number(cents))) return '—';
    return `${kEur.format(Number(cents) / 100000)} k€`;
}

// 0.18 → '18 %'
export function formatPercent(rate, digits = 0) {
    if (rate === null || rate === undefined || Number.isNaN(Number(rate))) return '—';
    return `${(Number(rate) * 100).toFixed(digits).replace('.', ',')} %`;
}

// '2026-01' → 'janv. 2026'
const monthFmt = new Intl.DateTimeFormat('fr-FR', { month: 'short', year: 'numeric' });
export function formatMonth(ym) {
    const [y, m] = String(ym).split('-').map(Number);
    if (!y || !m) return String(ym);
    return monthFmt.format(new Date(y, m - 1, 1));
}

// '2026-01' → {from:'2026-01-01', to:'2026-01-31'}
export function monthBounds(ym) {
    const [y, m] = String(ym).split('-').map(Number);
    if (!y || !m) return { from: null, to: null };
    const pad = (n) => String(n).padStart(2, '0');
    const last = new Date(y, m, 0).getDate();
    return { from: `${y}-${pad(m)}-01`, to: `${y}-${pad(m)}-${pad(last)}` };
}

// Échappement HTML des données utilisateur injectées dans un formatter d'infobulle ECharts.
export function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch]));
}

// ---------- carburant (ADR 0015) : volume en mL côté API, affiché en litres ----------

const litersFmt = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// 42370 mL → '42,37 L'
export function formatLiters(ml) {
    if (ml === null || ml === undefined || Number.isNaN(Number(ml))) return '—';
    return `${litersFmt.format(Number(ml) / 1000)} L`;
}

const pricePerLiterFmt = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 3, maximumFractionDigits: 3 });
// 1.789 (euros) → '1,789 €/L'
export function formatPricePerLiter(eur) {
    if (eur === null || eur === undefined || Number.isNaN(Number(eur))) return '—';
    return `${pricePerLiterFmt.format(Number(eur))} €/L`;
}

const consumptionFmt = new Intl.NumberFormat('fr-FR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
// 5.84 → '5,84 L/100 km'
export function formatConsumption(v) {
    if (v === null || v === undefined || Number.isNaN(Number(v))) return '—';
    return `${consumptionFmt.format(Number(v))} L/100 km`;
}

// '42,37' | '42.37' | '42' → 42370 (mL entier, arrondi au mL près) ; null si vide ou invalide.
export function parseLiters(str) {
    if (typeof str !== 'string') return null;
    const s = str.trim().replace(/[\s ]/g, '').replace(',', '.');
    if (!/^\d+(\.\d+)?$/.test(s)) return null;
    return Math.round(parseFloat(s) * 1000);
}

// mL → '42,37' (pré-remplir une saisie en litres, virgule française). '' si null.
export function mlToLiters(ml) {
    if (ml === null || ml === undefined) return '';
    return litersFmt.format(Number(ml) / 1000);
}

// '1,789' | '1.789' | '1' → 1.789 (euros/L, 3 décimales max) ; null si vide ou invalide.
export function parsePricePerLiter(str) {
    if (typeof str !== 'string') return null;
    const s = str.trim().replace(/[\s ]/g, '').replace(',', '.');
    if (!/^\d+(\.\d{1,3})?$/.test(s)) return null;
    return Number(s);
}
