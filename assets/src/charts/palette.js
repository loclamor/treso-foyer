// Palette catégorielle validée (ne pas modifier) — couleur portée par chartSlot de la racine.
export const CATEGORICAL = {
    light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
    dark: ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767'],
};

// Racines sans chartSlot + non catégorisé ⇒ « Autres » gris, toujours en dernier.
export const OTHERS_COLOR = { light: '#b5b3ab', dark: '#5c5b56' };
export const OTHERS_LABEL = 'Autres';

// Rampe séquentielle bleue (calendrier) — étapes du palette.md, #cde2fb → #0d366b.
export const SEQUENTIAL_BLUE = [
    '#cde2fb', '#b7d3f6', '#9ec5f4', '#86b6ef', '#6da7ec', '#5598e7', '#3987e5',
    '#2a78d6', '#256abf', '#1c5cab', '#184f95', '#104281', '#0d366b',
];

// Statut critique (déficit du sankey).
export const DEFICIT_COLOR = '#d03b3b';

// Couleur d'un emplacement 1-8 (hors plage ⇒ gris « Autres »).
export function slotColor(slot, mode) {
    const palette = CATEGORICAL[mode] ?? CATEGORICAL.light;
    const n = Number(slot);
    return Number.isInteger(n) && n >= 1 && n <= 8 ? palette[n - 1] : OTHERS_COLOR[mode] ?? OTHERS_COLOR.light;
}

// Couleur d'une CategoryRef (chartSlot de la racine) ; null = non catégorisé ⇒ gris.
export function colorForCategory(categoryRef, mode) {
    return slotColor(categoryRef?.chartSlot ?? null, mode);
}

// Regroupe les séries sans emplacement en une seule série « Autres » grise, en dernier.
// series : [{category: CategoryRef|null, values: number[], …}] ⇒ [{category, name, values, color, others?}]
export function foldToOthers(series, mode, label = OTHERS_LABEL) {
    const withSlot = [];
    const rest = [];
    for (const s of series ?? []) {
        const slot = Number(s.category?.chartSlot);
        if (Number.isInteger(slot) && slot >= 1 && slot <= 8) withSlot.push({ ...s, slot });
        else rest.push(s);
    }
    withSlot.sort((a, b) => a.slot - b.slot);
    const folded = withSlot.map((s) => ({
        ...s,
        name: s.category?.name ?? label,
        color: slotColor(s.slot, mode),
    }));
    if (rest.length) {
        const values = [];
        let count = 0;
        for (const s of rest) {
            (s.values ?? []).forEach((v, i) => {
                values[i] = (values[i] ?? 0) + (v ?? 0);
            });
            count += s.count ?? 0;
        }
        folded.push({
            category: null,
            name: label,
            values,
            count,
            color: OTHERS_COLOR[mode] ?? OTHERS_COLOR.light,
            others: true,
        });
    }
    return folded;
}

// Couleur hex #rrggbb + alpha 0..1 (#rrggbbaa) : versions atténuées (période précédente, hachuré).
export function withAlpha(hex, alpha) {
    const a = Math.round(Math.max(0, Math.min(1, alpha)) * 255).toString(16).padStart(2, '0');
    return `${hex}${a}`;
}
