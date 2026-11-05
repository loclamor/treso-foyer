import http from './http';

export async function listRules() {
    const { data } = await http.get('/rules');
    return data; // [Rule] triées priorité desc, id asc
}

// Champs de Rule avec categoryId et accountId.
export async function createRule(payload) {
    const { data } = await http.post('/rules', payload);
    return data;
}

export async function updateRule(id, payload) {
    const { data } = await http.patch(`/rules/${id}`, payload);
    return data;
}

export async function deleteRule(id) {
    await http.delete(`/rules/${id}`);
}

// Corps = règle sans id ⇒ {matchCount, conflicts, manualCount, sample:[Operation]}
export async function previewRule(payload) {
    const { data } = await http.post('/rules/preview', payload);
    return data;
}

export async function runCategorization(all) {
    const { data } = await http.post('/categorization/run', { all: !!all });
    return data;
}
