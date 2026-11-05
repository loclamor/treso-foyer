import http from './http';

// params : périmètre + uncategorized=1 + q + from/to + page/perPage + sort=count|total
export async function listMerchants(params) {
    const { data } = await http.get('/merchants', { params });
    return data; // paginé : {items: [MerchantGroup], total, page, perPage}
}

// payload : {key, direction, categoryId, createRule: true}
export async function categorizeMerchant(payload) {
    const { data } = await http.post('/merchants/categorize', payload);
    return data; // {updatedCount, rule}
}
