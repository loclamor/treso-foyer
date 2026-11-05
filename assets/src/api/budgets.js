import http from './http';

// Budgets (étape 9) — corps : champs de Budget avec categoryId, memberId, accountId.
export async function listBudgets() {
    const { data } = await http.get('/budgets');
    return data;
}

export async function createBudget(payload) {
    const { data } = await http.post('/budgets', payload);
    return data;
}

export async function updateBudget(id, payload) {
    const { data } = await http.patch(`/budgets/${id}`, payload);
    return data;
}

export async function deleteBudget(id) {
    await http.delete(`/budgets/${id}`);
}
