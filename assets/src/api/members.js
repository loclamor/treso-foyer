import http from './http';

export async function listMembers() {
    const { data } = await http.get('/members');
    return data; // [Member]
}
