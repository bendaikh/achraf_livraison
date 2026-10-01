import axios from 'axios';

const api = axios.create({
    baseURL: '/api',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});

export function errorMessage(error, fallback = 'Une erreur est survenue.') {
    const data = error?.response?.data;
    if (data?.errors) {
        const first = Object.values(data.errors)[0];
        if (Array.isArray(first) && first[0]) return first[0];
    }
    return data?.message || fallback;
}

export function fieldErrors(error) {
    const errors = error?.response?.data?.errors || {};
    return Object.fromEntries(Object.entries(errors).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v]));
}

export default api;
