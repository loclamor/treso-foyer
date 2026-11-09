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

// ---------- saisies manuelles (section 8 de la spec saisie manuelle) ----------

// payload : {accountId, spentAt:'AAAA-MM-JJ', amount (centimes signés, ≠ 0), label,
//             categoryId?, vehicleId?, note?}
export async function createOperation(payload) {
    const { data } = await http.post('/operations', payload);
    return data; // 201 {operation, reconciled} (reconciled=true si absorbée immédiatement par une ligne de relevé)
}

// 204 ; 400 not_a_manual_entry si l'opération n'est pas une saisie en attente.
export async function deleteOperation(id) {
    await http.delete(`/operations/${id}`);
}

// Candidats de rapprochement manuel ⇒ liste d'Operation (ou {items}). 400 si pas en attente.
export async function reconciliationCandidates(id) {
    const { data } = await http.get(`/operations/${id}/reconciliation-candidates`);
    return data;
}

// Appariement manuel avec la ligne de relevé operationId ⇒ 200 {operation: ligne du relevé}.
export async function reconcileOperation(id, operationId) {
    const { data } = await http.post(`/operations/${id}/reconcile`, { operationId });
    return data; // {operation}
}

// ---------- pleins de carburant (ADR 0015) ----------

// payload : {volumeMl: int > 0, fullTank?: bool (défaut true), odometerKm?: int ≥ 0|null}
// ⇒ 200 {operation, warning?} (remplace le plein existant ; 400 not_a_debit sur un crédit).
export async function setOperationFuel(id, payload) {
    const { data } = await http.put(`/operations/${id}/fuel`, payload);
    return data; // {operation, warning?}
}

// ⇒ 200 {operation} avec fuel: null.
export async function clearOperationFuel(id) {
    const { data } = await http.delete(`/operations/${id}/fuel`);
    return data; // {operation}
}
