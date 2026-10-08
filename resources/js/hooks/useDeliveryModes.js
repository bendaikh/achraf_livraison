import { useEffect, useState } from 'react';
import api from '../lib/api';

let cache = null;
let pending = null;

const empty = {
    modes: [],
    can_ship: false,
    can_assign_driver: false,
    can_assign_agent: false,
    can_cancel: false,
    max_bulk: 50,
    user_id: null,
    company_id: null,
    loading: true,
};

/** GET /api/delivery-modes — local delivery + every registered carrier, once per page load. */
export default function useDeliveryModes() {
    const [state, setState] = useState(cache);

    useEffect(() => {
        if (cache) return undefined;
        pending ??= api
            .get('/delivery-modes')
            .then(({ data }) => (cache = data))
            .catch(() => (cache = { ...empty, loading: false }))
            .finally(() => {
                pending = null;
            });
        pending.then((d) => setState(d));
        return undefined;
    }, []);

    return state || empty;
}
