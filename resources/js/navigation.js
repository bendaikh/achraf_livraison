import {
    LayoutDashboard,
    ShoppingBag,
    PhoneCall,
    MessageCircle,
    Bike,
    CalendarCheck,
    Plug,
    Users,
    Settings,
    Package,
} from 'lucide-react';

export const modules = [
    {
        id: 'dashboard',
        label: 'Tableau de bord',
        to: '/',
        icon: LayoutDashboard,
        roles: ['admin'],
        tabs: [{ to: '/', label: 'Vue générale', end: true }],
    },
    {
        id: 'commandes',
        label: 'Commandes',
        to: '/commandes',
        icon: ShoppingBag,
        roles: ['admin'],
        tabs: [{ to: '/commandes', label: 'Liste des commandes' }],
    },
    {
        id: 'confirmation',
        label: 'Confirmation',
        to: '/confirmation',
        icon: PhoneCall,
        roles: ['admin'],
        tabs: [
            { to: '/confirmation', label: 'File de confirmation' },
            { to: '/a-attribuer', label: 'À attribuer' },
        ],
    },
    {
        id: 'livreurs',
        label: 'Livreurs',
        to: '/livreurs',
        icon: Bike,
        roles: ['admin'],
        tabs: [
            { to: '/livreurs', label: 'Liste des livreurs' },
            { to: '/missions', label: 'Missions' },
        ],
    },
    {
        id: 'mes-missions',
        label: 'Mes missions',
        to: '/mes-missions',
        icon: Package,
        roles: ['livreur'],
        tabs: [{ to: '/mes-missions', label: 'Missions du jour' }],
    },
    {
        id: 'cloture',
        label: 'Clôture du jour',
        to: '/cloture',
        icon: CalendarCheck,
        roles: ['admin'],
        tabs: [{ to: '/cloture', label: 'Clôture du jour' }],
    },
    {
        id: 'whatsapp',
        label: 'WhatsApp',
        to: '/whatsapp',
        icon: MessageCircle,
        badgeKey: 'whatsapp',
        roles: ['admin'],
        tabs: [
            { to: '/whatsapp', label: 'Messagerie', end: true },
            { to: '/whatsapp/comptes', label: 'Comptes / Numéros' },
            { to: '/whatsapp/templates', label: 'Templates' },
            { to: '/whatsapp/reponses-rapides', label: 'Réponses rapides' },
        ],
    },
    {
        id: 'integrations',
        label: 'Intégrations',
        to: '/integrations/ozone',
        icon: Plug,
        roles: ['admin'],
        tabs: [
            { to: '/integrations/ozone', label: 'Ozone Delivery' },
            { to: '/integrations/speedaf', label: 'Speedaf' },
            { to: '/integrations/shopify', label: 'Shopify' },
        ],
    },
    {
        id: 'utilisateurs',
        label: 'Utilisateurs',
        to: '/utilisateurs',
        icon: Users,
        roles: ['admin'],
        tabs: [{ to: '/utilisateurs', label: 'Liste des utilisateurs' }],
    },
    {
        id: 'parametres',
        label: 'Paramètres',
        to: '/parametres',
        icon: Settings,
        roles: ['admin'],
        tabs: [
            { to: '/parametres', label: 'Société & tarifs', end: true },
            { to: '/parametres/statuts', label: 'Statuts de livraison' },
        ],
    },
];

export function modulesForUser(user) {
    if (!user) {
        return modules.filter((mod) => (mod.roles || []).includes('admin'));
    }
    if (user.is_livreur || user.role === 'livreur') {
        return modules.filter((mod) => (mod.roles || []).includes('livreur'));
    }
    return modules.filter((mod) => (mod.roles || []).includes('admin'));
}

export function getActiveModule(pathname, user) {
    const normalized = pathname === '' ? '/' : pathname;
    const list = modulesForUser(user);

    for (const mod of list) {
        for (const tab of mod.tabs) {
            if (tab.end) {
                if (normalized === tab.to) return mod;
            } else if (normalized === tab.to || normalized.startsWith(`${tab.to}/`)) {
                return mod;
            }
        }
    }

    // Fallback: Confirmation module owns /a-attribuer even if pathname matching is ambiguous
    if (normalized.startsWith('/a-attribuer')) {
        return list.find((m) => m.id === 'confirmation') || list[0];
    }

    return list[0] || modules[0];
}

export function homePathForUser(user) {
    if (user?.is_livreur || user?.role === 'livreur') {
        return '/mes-missions';
    }
    return '/';
}
