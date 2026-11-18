import http from './http';

// Périodes taguées (vacances, week-end, sortie, autre).
// La liste renvoie les périodes qui chevauchent [from, to].
// Corps d'écriture : {type, label, startDate, endDate} ('AAAA-MM-JJ') ; PATCH partiel accepté.

export async function listTaggedPeriods(range) {
    const { data } = await http.get('/tagged-periods', { params: range });
    return data;
}

export async function createTaggedPeriod(payload) {
    const { data } = await http.post('/tagged-periods', payload);
    return data;
}

export async function updateTaggedPeriod(id, payload) {
    const { data } = await http.patch(`/tagged-periods/${id}`, payload);
    return data;
}

export async function deleteTaggedPeriod(id) {
    await http.delete(`/tagged-periods/${id}`);
}
