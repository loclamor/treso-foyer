import http from './http';

export async function listTransfers(params) {
    const { data } = await http.get('/transfers', { params });
    return data; // paginé : {items: [InternalTransfer], total, page, perPage}
}

export async function matchTransfers() {
    const { data } = await http.post('/transfers/match');
    return data; // {matched, ambiguous}
}

export async function deleteTransfer(id) {
    await http.delete(`/transfers/${id}`);
}
