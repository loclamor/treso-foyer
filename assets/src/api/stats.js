import http from './http';

// Endpoints « Statistiques (étape 8) » du contrat (docs/api.md).
// Chaque appel reçoit le périmètre du store scope (accountId[] / memberId) + la plage from/to.

function buildParams(scopeParams, range, extra = {}) {
    const params = { ...scopeParams, ...range, ...extra };
    for (const key of Object.keys(params)) {
        if (params[key] === null || params[key] === undefined || params[key] === '') delete params[key];
    }
    return params;
}

// {from,to,income,expense,net,savingsRate,previous,average12,…}
export async function getOverview(scopeParams, range) {
    const { data } = await http.get('/stats/overview', { params: buildParams(scopeParams, range) });
    return data;
}

// {months, income, expense, net, byRoot, uncategorized}
export async function getMonthly(scopeParams, range) {
    const { data } = await http.get('/stats/monthly', { params: buildParams(scopeParams, range) });
    return data;
}

// [{category, total, count, share, compareTotal}] — parentId null = racines, compare = 'previous'|'year'
export async function getCategoriesStats(scopeParams, range, { direction = 'expense', parentId = null, compare = null } = {}) {
    const { data } = await http.get('/stats/categories', {
        params: buildParams(scopeParams, range, { direction, parentId, compare }),
    });
    return data;
}

// [{key, merchant, total, count, category}]
export async function getMerchantsStats(scopeParams, range, { direction = 'expense', limit = 15 } = {}) {
    const { data } = await http.get('/stats/merchants', {
        params: buildParams(scopeParams, range, { direction, limit }),
    });
    return data;
}

// {dates, total, accounts:[{account, values}]}
export async function getBalance(scopeParams, range, granularity = 'month') {
    const { data } = await http.get('/stats/balance', {
        params: buildParams(scopeParams, range, { granularity }),
    });
    return data;
}

// {nodes, links} — flux revenus → hub → postes (+ épargne / déficit)
export async function getSankey(scopeParams, range) {
    const { data } = await http.get('/stats/sankey', { params: buildParams(scopeParams, range) });
    return data;
}

// [{date, expense, count}]
export async function getCalendar(scopeParams, range) {
    const { data } = await http.get('/stats/calendar', { params: buildParams(scopeParams, range) });
    return data;
}

// {category, months, values, counts}
export async function getCategoryTrend(scopeParams, range, categoryId) {
    const { data } = await http.get('/stats/category-trend', {
        params: buildParams(scopeParams, range, { categoryId }),
    });
    return data;
}
