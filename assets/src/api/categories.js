import http from './http';

export async function listCategories() {
    const { data } = await http.get('/categories');
    return data; // [Category] arbre, racines triées par position
}

export async function createCategory(payload) {
    const { data } = await http.post('/categories', payload);
    return data;
}

export async function updateCategory(id, payload) {
    const { data } = await http.patch(`/categories/${id}`, payload);
    return data;
}

export async function deleteCategory(id) {
    await http.delete(`/categories/${id}`);
}
