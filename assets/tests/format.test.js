import { describe, expect, it } from 'vitest';
import {
    centsToEuros,
    dateToIso,
    escapeHtml,
    formatDate,
    formatKg,
    formatMoney,
    formatPercent,
    formatPricePerKg,
    gToKg,
    isoToDate,
    monthBounds,
    parseEuros,
    parseKg,
    parseLiters,
    parsePricePerKg,
    parsePricePerLiter,
    percentChange,
} from '@/utils/format';

// Intl insère des espaces insécables (U+00A0 / U+202F) : on les normalise pour comparer.
const plain = (s) => s.replace(/[\u00A0\u202F]/g, ' ');

describe('parseEuros (saisie → centimes, sans flottant)', () => {
    it.each([
        ['12,34', 1234],
        ['12.34', 1234],
        ['12,5', 1250],
        ['12', 1200],
        ['-0,05', -5],
        ['1 234,56', 123456],
        ['1\u00A0234,56', 123456],
        ['  42,00  ', 4200],
        // 0,1 + 0,2 en flottant donnerait 30,000000000000004 : ici calcul entier.
        ['0,30', 30],
    ])('%s → %i', (input, cents) => {
        expect(parseEuros(input)).toBe(cents);
    });

    it.each(['', 'abc', '12,345', '1.234,56', '--1', '12,', null, 12])('rejette %s', (input) => {
        expect(parseEuros(input)).toBeNull();
    });

    it('fait l’aller-retour avec centsToEuros', () => {
        for (const cents of [0, 5, 99, 100, 123456, -1, -123456]) {
            expect(parseEuros(centsToEuros(cents))).toBe(cents);
        }
    });
});

describe('centsToEuros', () => {
    it.each([
        [0, '0,00'],
        [5, '0,05'],
        [123456, '1234,56'],
        [-1250, '-12,50'],
        [null, ''],
    ])('%s → %s', (cents, text) => {
        expect(centsToEuros(cents)).toBe(text);
    });
});

describe('formatMoney', () => {
    it('formate en euros français', () => {
        expect(plain(formatMoney(123456))).toBe('1 234,56 €');
        expect(plain(formatMoney(-500))).toBe('-5,00 €');
    });

    it.each([null, undefined, 'abc'])('%s → tiret', (value) => {
        expect(formatMoney(value)).toBe('—');
    });
});

describe('dates sans décalage de fuseau', () => {
    it('formatDate lit la chaîne ISO sans passer par Date', () => {
        expect(formatDate('2026-03-01')).toBe('01/03/2026');
        expect(formatDate('2026-03-01T23:30:00+00:00')).toBe('01/03/2026');
        expect(formatDate(null)).toBe('—');
    });

    it('isoToDate donne minuit local, dateToIso le relit à l’identique', () => {
        const d = isoToDate('2024-02-29');
        expect([d.getFullYear(), d.getMonth(), d.getDate(), d.getHours()]).toEqual([2024, 1, 29, 0]);
        expect(dateToIso(d)).toBe('2024-02-29');
    });

    it.each([null, '', 'n/a', '2024-02'])('isoToDate(%s) → null', (value) => {
        expect(isoToDate(value)).toBeNull();
    });

    it('dateToIso refuse ce qui n’est pas une date valide', () => {
        expect(dateToIso('2024-01-01')).toBeNull();
        expect(dateToIso(new Date('invalide'))).toBeNull();
    });

    it('monthBounds gère février bissextile et les mois de 30 jours', () => {
        expect(monthBounds('2024-02')).toEqual({ from: '2024-02-01', to: '2024-02-29' });
        expect(monthBounds('2025-02')).toEqual({ from: '2025-02-01', to: '2025-02-28' });
        expect(monthBounds('2026-04')).toEqual({ from: '2026-04-01', to: '2026-04-30' });
        expect(monthBounds('n/a')).toEqual({ from: null, to: null });
    });
});

describe('percentChange', () => {
    it('calcule la variation par rapport à la base', () => {
        expect(percentChange(150, 100)).toBe(50);
        expect(percentChange(50, 100)).toBe(-50);
    });

    it('garde le sens de l’évolution quand la base est négative', () => {
        // Solde -100 → -50 : amélioration, donc variation positive.
        expect(percentChange(-50, -100)).toBe(50);
        expect(percentChange(-150, -100)).toBe(-50);
    });

    it.each([[100, 0], [100, null], [null, 100]])('(%s, %s) → null', (current, previous) => {
        expect(percentChange(current, previous)).toBeNull();
    });
});

describe('escapeHtml', () => {
    it('neutralise le HTML injecté dans les infobulles', () => {
        expect(escapeHtml('<img src=x onerror="alert(1)">')).toBe('&lt;img src=x onerror=&quot;alert(1)&quot;&gt;');
        expect(escapeHtml("Café & thé l'été")).toBe('Café &amp; thé l&#39;été');
        expect(escapeHtml(null)).toBe('');
    });
});

describe('formatPercent', () => {
    it('affiche un taux en pourcentage français', () => {
        expect(formatPercent(0.9892, 1)).toBe('98,9 %');
        expect(formatPercent(0.5)).toBe('50 %');
        expect(formatPercent(null)).toBe('—');
    });
});

describe('carburant', () => {
    it('parseLiters convertit en millilitres', () => {
        expect(parseLiters('45,5')).toBe(45500);
        expect(parseLiters('40')).toBe(40000);
        expect(parseLiters('-3')).toBeNull();
        expect(parseLiters('abc')).toBeNull();
    });

    it('parsePricePerLiter accepte jusqu’à 3 décimales', () => {
        expect(parsePricePerLiter('1,859')).toBe(1.859);
        expect(parsePricePerLiter('1,8599')).toBeNull();
    });
});

describe('gaz en bouteille (ADR 0017)', () => {
    it('formatKg affiche des grammes en kg', () => {
        expect(plain(formatKg(13000))).toBe('13,00 kg');
        expect(plain(formatKg(6250))).toBe('6,25 kg');
        expect(formatKg(null)).toBe('—');
    });

    it('parseKg convertit en grammes', () => {
        expect(parseKg('13')).toBe(13000);
        expect(parseKg('13,5')).toBe(13500);
        expect(parseKg('6.25')).toBe(6250);
        expect(parseKg('-3')).toBeNull();
        expect(parseKg('abc')).toBeNull();
    });

    it('gToKg pré-remplit une saisie en kg', () => {
        expect(gToKg(13000)).toBe('13,00');
        expect(gToKg(null)).toBe('');
    });

    it('formatPricePerKg / parsePricePerKg (3 décimales max)', () => {
        expect(plain(formatPricePerKg(3.069))).toBe('3,069 €/kg');
        expect(formatPricePerKg(null)).toBe('—');
        expect(parsePricePerKg('3,069')).toBe(3.069);
        expect(parsePricePerKg('3,0699')).toBeNull();
    });
});
