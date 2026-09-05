import {
    LayoutDashboard,
    ShoppingBag,
    MessageCircle,
    Bike,
    CalendarCheck,
    Plug,
    Users,
    Settings,
} from 'lucide-react';

export const modules = [
    {
        id: 'dashboard',
        label: 'Tableau de bord',
        to: '/',
        icon: LayoutDashboard,
        tabs: [{ to: '/', label: 'Vue générale', end: true }],
    },
    {
        id: 'commandes',
        label: 'Commandes',
        to: '/commandes',
        icon: ShoppingBag,
        tabs: [{ to: '/commandes', label: 'Liste des commandes' }],
    },
    {
        id: 'livreurs',
        label: 'Livreurs',
        to: '/livreurs',
        icon: Bike,
        tabs: [{ to: '/livreurs', label: 'Liste des livreurs' }],
    },
    {
        id: 'cloture',
        label: 'Clôture du jour',
        to: '/cloture',
        icon: CalendarCheck,
        tabs: [{ to: '/cloture', label: 'Clôture du jour' }],
    },
    {
        id: 'whatsapp',
        label: 'WhatsApp',
        to: '/whatsapp',
        icon: MessageCircle,
        badge: 12,
        tabs: [{ to: '/whatsapp', label: 'Messagerie' }],
    },
    {
        id: 'integrations',
        label: 'Intégrations',
        to: '/integrations/ozone',
        icon: Plug,
        tabs: [
            { to: '/integrations/ozone', label: 'Ozone Delivery' },
            { to: '/integrations/speedaf', label: 'Speedaf' },
            { to: '/integrations/libromart', label: 'Libromart' },
        ],
    },
    {
        id: 'utilisateurs',
        label: 'Utilisateurs',
        to: '/utilisateurs',
        icon: Users,
        tabs: [{ to: '/utilisateurs', label: 'Liste des utilisateurs' }],
    },
    {
        id: 'parametres',
        label: 'Paramètres',
        to: '/parametres',
        icon: Settings,
        tabs: [{ to: '/parametres', label: 'Paramètres' }],
    },
];

export function getActiveModule(pathname) {
    const normalized = pathname === '' ? '/' : pathname;

    for (const mod of modules) {
        for (const tab of mod.tabs) {
            if (tab.end) {
                if (normalized === tab.to) return mod;
            } else if (
                normalized === tab.to ||
                normalized.startsWith(`${tab.to}/`)
            ) {
                return mod;
            }
        }
    }

    return modules[0];
}
