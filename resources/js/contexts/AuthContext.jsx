import { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import axios from 'axios';

const AuthContext = createContext(null);

function readBootUser() {
    return window.__APP__?.user ?? null;
}

export function AuthProvider({ children }) {
    const [user, setUser] = useState(() => readBootUser());
    const [loading, setLoading] = useState(false);

    const refreshUser = useCallback(async () => {
        setLoading(true);
        try {
            const { data } = await axios.get('/user');
            setUser(data.user);
            return data.user;
        } catch {
            setUser(null);
            return null;
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        // Boot payload already includes auth state from the server.
        if (window.__APP__ && Object.prototype.hasOwnProperty.call(window.__APP__, 'user')) {
            return;
        }
        refreshUser();
    }, [refreshUser]);

    const login = useCallback(async (email, password, remember = true) => {
        const { data } = await axios.post('/login', { email, password, remember });
        setUser(data.user);
        return data.user;
    }, []);

    const logout = useCallback(async () => {
        await axios.post('/logout');
        setUser(null);
    }, []);

    const updateProfile = useCallback(async (payload) => {
        const { data } = await axios.put('/profile', payload);
        setUser(data.user);
        return data;
    }, []);

    const value = useMemo(
        () => ({
            user,
            loading,
            isAuthenticated: Boolean(user),
            login,
            logout,
            updateProfile,
            refreshUser,
            // Ability check (config/permissions.php, resolved server-side).
            can: (ability) => Boolean(user && (user.role === 'superadmin' || (user.permissions || []).includes(ability))),
        }),
        [user, loading, login, logout, updateProfile, refreshUser],
    );

    return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error('useAuth must be used within AuthProvider');
    }
    return ctx;
}
