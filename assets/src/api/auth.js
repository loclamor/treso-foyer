import http from './http';

export async function login(username, password) {
    const { data } = await http.post('/login', { username, password });
    return data;
}

export async function logout() {
    await http.post('/logout');
}

export async function me() {
    const { data } = await http.get('/me');
    return data;
}

export async function health() {
    const { data } = await http.get('/health');
    return data;
}
