import { useCallback, useEffect, useRef, useState } from 'react';
import api from '../lib/api';
import { useMeta } from '../context/MetaContext';

const LAST_USER_KEY = 'lavfast:current-user';

function lsKey(userId, key) {
    return `lavfast:u${userId ?? 'anon'}:${key}`;
}

function readLocal(userId, key) {
    try {
        const raw = window.localStorage.getItem(lsKey(userId, key));
        return raw ? JSON.parse(raw) : null;
    } catch {
        return null;
    }
}

/**
 * Per-user preference stored server-side (user_preferences table, keyed by the current user)
 * and mirrored in localStorage (namespaced by user id) for instant rendering.
 */
export default function useUserPreference(key, defaults) {
    const { currentUser, loaded } = useMeta();
    const lastUser = typeof window !== 'undefined' ? window.localStorage.getItem(LAST_USER_KEY) : null;
    const [value, setValueState] = useState(() => readLocal(lastUser, key) ?? defaults);
    const [ready, setReady] = useState(false);
    const timer = useRef(null);
    const userId = currentUser?.id ?? null;

    useEffect(() => {
        if (!loaded) return;
        let cancelled = false;
        window.localStorage.setItem(LAST_USER_KEY, String(userId ?? 'anon'));
        const local = readLocal(userId, key);
        if (local) setValueState(local);
        api.get(`/preferences/${key}`)
            .then(({ data }) => {
                if (cancelled) return;
                if (data?.value) {
                    setValueState(data.value);
                    window.localStorage.setItem(lsKey(userId, key), JSON.stringify(data.value));
                }
            })
            .catch(() => {})
            .finally(() => !cancelled && setReady(true));
        return () => {
            cancelled = true;
        };
    }, [loaded, userId, key]);

    const setValue = useCallback(
        (updater) => {
            setValueState((prev) => {
                const next = typeof updater === 'function' ? updater(prev) : updater;
                window.localStorage.setItem(lsKey(userId, key), JSON.stringify(next));
                clearTimeout(timer.current);
                timer.current = setTimeout(() => {
                    api.put(`/preferences/${key}`, { value: next }).catch(() => {});
                }, 400);
                return next;
            });
        },
        [userId, key],
    );

    return [value, setValue, ready];
}
