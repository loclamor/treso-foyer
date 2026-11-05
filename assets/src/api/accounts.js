import http from './http';

export async function listAccounts() {
    const { data } = await http.get('/accounts');
    return data; // [Account]
}

// payload : {alias?, label?, type?, holderIds?: [int]}
export async function updateAccount(id, payload) {
    const { data } = await http.patch(`/accounts/${id}`, payload);
    return data;
}
