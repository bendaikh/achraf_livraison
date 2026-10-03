import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { useAuth } from './AuthContext';

const WhatsAppUnreadContext = createContext({
    unreadCount: 0,
    refreshUnread: async () => {},
});

export function WhatsAppUnreadProvider({ children }) {
    const [unreadCount, setUnreadCount] = useState(0);
    const { can } = useAuth();
    const allowed = can('whatsapp.access');

    const refreshUnread = useCallback(async () => {
        if (!allowed) return; // drivers / users without the WhatsApp module: no polling (avoids 403s)
        try {
            const { data } = await window.axios.get('/api/whatsapp/unread-count');
            setUnreadCount(Number(data.unread_count) || 0);
        } catch {
            // silent — module may be unavailable during deploy
        }
    }, [allowed]);

    useEffect(() => {
        refreshUnread();
        const timer = setInterval(refreshUnread, 15000);
        return () => clearInterval(timer);
    }, [refreshUnread]);

    return (
        <WhatsAppUnreadContext.Provider value={{ unreadCount, refreshUnread }}>
            {children}
        </WhatsAppUnreadContext.Provider>
    );
}

export function useWhatsAppUnread() {
    return useContext(WhatsAppUnreadContext);
}
