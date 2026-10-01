import axios from 'axios';

const csrf = typeof document !== 'undefined' ? document.head.querySelector('meta[name="csrf-token"]')?.content : null;

// Same session auth as the rest of the SPA (cookies + CSRF).
const api = axios.create({
    baseURL: '/api',
    withCredentials: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    },
});

api.interceptors.response.use(
    (response) => response,
    (error) => {
        // Session expired: back to the login screen.
        if (error?.response?.status === 401 && typeof window !== 'undefined' && window.location.pathname !== '/login') {
            window.location.assign('/login');
        }
        return Promise.reject(error);
    },
);

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
