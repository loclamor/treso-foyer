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
