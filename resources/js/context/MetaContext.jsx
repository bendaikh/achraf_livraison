import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../lib/api';
import { useAuth } from '../contexts/AuthContext';

const MetaContext = createContext(null);

/**
 * Loads reference data (active delivery statuses, categories, mission types, drivers…)
 * from /api/meta. No status list is hard-coded in the frontend.
 */
export function MetaProvider({ children }) {
    const { user } = useAuth();
    // Admin reference data only: the driver space (Mes missions) and the login page don't need it.
    const canLoad = Boolean(user) && !(user.is_livreur || user.role === 'livreur');
    const [meta, setMeta] = useState(null);
    const [error, setError] = useState(null);

    const reload = useCallback(async () => {
        if (!canLoad) {
            setMeta(null);
            return;
        }
        try {
            const { data } = await api.get('/meta');
            setMeta(data);
            setError(null);
        } catch (e) {
            setError(e);
        }
    }, [canLoad]);

    useEffect(() => {
        reload();
    }, [reload]);

    const value = useMemo(() => {
        const m = meta || {};
        const byValue = (list) => Object.fromEntries((list || []).map((i) => [i.value, i]));
        return {
            loaded: !!meta,
            error,
            reload,
            statuses: m.statuses || [],
            statusCategories: m.status_categories || [],
            confirmationStatuses: m.confirmation_statuses || [],
            missionTypes: m.mission_types || [],
            missionStatuses: m.mission_statuses || [],
            paymentMethods: m.payment_methods || [],
            requiredFieldCatalog: m.required_field_catalog || [],
            statusMissionTypes: m.status_mission_types || [],
            drivers: m.drivers || [],
            defaultTariffs: m.default_tariffs || null,
            currentUser: m.current_user || null,
            users: m.users || [],
            services: m.services || [],
            confirmationMap: byValue(m.confirmation_statuses),
            missionTypeMap: byValue(m.mission_types),
            missionStatusMap: byValue(m.mission_statuses),
            categoryMap: byValue(m.status_categories),
            paymentMap: byValue(m.payment_methods),
        };
    }, [meta, error, reload]);

    return <MetaContext.Provider value={value}>{children}</MetaContext.Provider>;
}

export function useMeta() {
    const ctx = useContext(MetaContext);
    if (!ctx) throw new Error('useMeta must be used within MetaProvider');
    return ctx;
}
