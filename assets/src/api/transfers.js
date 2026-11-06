import http from './http';

// params.status : 'auto' (à vérifier) | 'confirmed' | absent (tous).
export async function listTransfers(params) {
    const { data } = await http.get('/transfers', { params });
    return data; // paginé : {items: [InternalTransfer], total, page, perPage}
}

export async function matchTransfers() {
    const { data } = await http.post('/transfers/match');
    return data; // {matched, ambiguous}
}

// Valide un virement détecté (status 'confirmed') ⇒ InternalTransfer.
export async function confirmTransfer(id) {
    const { data } = await http.post(`/transfers/${id}/confirm`);
    return data;
}

// Valide tous les virements « à vérifier » du foyer ⇒ {confirmedCount}.
export async function confirmAllTransfers() {
    const { data } = await http.post('/transfers/confirm-all');
    return data;
}

export async function deleteTransfer(id) {
    await http.delete(`/transfers/${id}`);
}
