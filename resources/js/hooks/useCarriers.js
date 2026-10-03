import { useEffect, useState } from 'react';
import api from '../lib/api';

let cache = null;
let pending = null;

/** Registered delivery companies (GET /api/carriers), fetched once per page load. */
export default function useCarriers() {
    const [state, setState] = useState(cache);

    useEffect(() => {
        if (cache) return;
        pending ??= api
            .get('/carriers')
            .then(({ data }) => (cache = data))
            .catch(() => (cache = { carriers: [], can_ship: false, can_assign_driver: false }))
            .finally(() => (pending = null));
        pending.then((d) => setState(d));
    }, []);

    return state || { carriers: [], can_ship: false, can_assign_driver: false, loading: true };
}
