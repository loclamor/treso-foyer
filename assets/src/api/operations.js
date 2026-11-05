import http from './http';

// params : périmètre (scope) + filtres + page/perPage + sort/order (voir docs/api.md).
export async function listOperations(params) {
    const { data } = await http.get('/operations', { params });
    return data; // {items, total, page, perPage, sums:{debit, credit}}
}

export async function getOperation(id) {
    const { data } = await http.get(`/operations/${id}`);
    return data;
}

export async function patchOperation(id, payload) {
    const { data } = await http.patch(`/operations/${id}`, payload);
    return data;
}

// payload : {categoryId: int|null, scope: 'operation'|'merchant'}
export async function categorizeOperation(id, payload) {
    const { data } = await http.post(`/operations/${id}/categorize`, payload);
    return data; // {operation, updatedCount, rule}
}

export async function bulkCategorize(ids, categoryId) {
    const { data } = await http.post('/operations/bulk-categorize', { ids, categoryId });
    return data; // {updatedCount}
}

// action : 'merge' | 'distinct'
export async function resolveDuplicate(id, action) {
    const { data } = await http.post(`/operations/${id}/duplicate`, { action });
    return data; // {kept}
}
