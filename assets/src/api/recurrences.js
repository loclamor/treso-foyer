import http from './http';

// Récurrences (séries détectées automatiquement après chaque import) — contrat docs/api.md.
// Montants en centimes, dates 'AAAA-MM-JJ'. Le périmètre foyer/personne/compte est passé
// comme pour les stats : params = {...scope.params, status?, direction?}.

// params : {status?: 'detected,confirmed'|… (liste séparée par des virgules), direction?: 'expense'|'income'}
// ⇒ {items: [Recurrence], counts: {detected, confirmed, ignored, ended}}
export async function listRecurrences(params = {}) {
    const { data } = await http.get('/recurrences', { params });
    return data;
}

// PATCH partiel : {status?, expectedAmount?, amountTolerance?, frequency?, activeMonths?}.
// Modifier montant/fréquence/mois d'une série « détectée » la passe automatiquement en « confirmée ».
export async function updateRecurrence(id, patch) {
    const { data } = await http.patch(`/recurrences/${id}`, patch);
    return data; // Recurrence
}

// Relance la détection ⇒ {created, updated, ended, removed, linked}
export async function detectRecurrences() {
    const { data } = await http.post('/recurrences/detect');
    return data;
}
