import http from './http';

// params : périmètre (scope). ⇒ {operationsCount, categorizedCount, uncategorizedCount, ...}
export async function getSummary(params) {
    const { data } = await http.get('/summary', { params });
    return data;
}
