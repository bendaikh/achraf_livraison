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
    Boxes,
    LayoutGrid,
    UsersRound,
    Contact,
    Undo2,
} from 'lucide-react';

export const modules = [
    {
        id: 'dashboard',
        label: 'Tableau de bord',
        to: '/',
        icon: LayoutDashboard,
        roles: ['admin'],
        ability: 'dashboard.view',
        tabs: [{ to: '/', label: 'Vue générale', end: true }],
    },
    {
        id: 'centre',
        label: 'Centre',
        to: '/centre',
        icon: LayoutGrid,
        roles: ['admin'],
        ability: 'dashboard.view',
        tabs: [{ to: '/centre', label: 'Centre de travail' }],
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
        id: 'produits',
        label: 'Produits',
        to: '/produits',
        icon: Boxes,
        roles: ['admin'],
        ability: 'products.view',
        tabs: [{ to: '/produits', label: 'Catalogue Shopify' }],
    },
    {
        id: 'confirmation',
        label: 'Confirmation',
        to: '/confirmation',
        icon: PhoneCall,
        roles: ['admin'],
        tabs: [
            { to: '/confirmation', label: 'File de confirmation' },
            { to: '/a-attribuer', label: 'À attribuer', ability: 'drivers.manage' },
        ],
    },
    {
        id: 'clients',
        label: 'Clients',
        to: '/clients',
        icon: Contact,
        roles: ['admin'],
        ability: 'clients.view',
        tabs: [
            { to: '/clients', label: 'Tous les clients', end: true },
            { to: '/clients/bloques', label: 'Bloqués' },
            { to: '/clients/segments', label: 'Segments' },
            { to: '/clients/groupes', label: 'Groupes' },
        ],
    },
    {
        id: 'retours',
        label: 'Retours & échanges',
        to: '/retours',
        icon: Undo2,
        roles: ['admin'],
        ability: 'sav.manage',
        tabs: [
            { to: '/retours', label: 'Demandes', end: true },
            { to: '/retours/articles', label: 'Articles chez les livreurs' },
        ],
    },
    {
        id: 'livreurs',
        label: 'Livreurs',
        to: '/livreurs',
        icon: Bike,
        roles: ['admin'],
        ability: 'drivers.manage',
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
        tabs: [
            { to: '/mes-missions', label: 'Missions du jour' },
            { to: '/mes-retours', label: 'Retours & échanges' },
        ],
    },
    {
        id: 'cloture',
        label: 'Clôture du jour',
        to: '/cloture',
        icon: CalendarCheck,
        roles: ['admin'],
        ability: 'closings.manage',
        tabs: [{ to: '/cloture', label: 'Clôture du jour' }],
    },
    {
        id: 'whatsapp',
        label: 'WhatsApp',
        to: '/whatsapp',
        icon: MessageCircle,
        badgeKey: 'whatsapp',
        roles: ['admin'],
        ability: 'whatsapp.access',
        tabs: [
            { to: '/whatsapp', label: 'Messagerie', end: true },
            { to: '/whatsapp/comptes', label: 'Comptes / Numéros', ability: 'settings.manage' },
            { to: '/whatsapp/templates', label: 'Templates' },
            { to: '/whatsapp/reponses-rapides', label: 'Réponses rapides' },
        ],
    },
    {
        id: 'integrations',
        label: 'Intégrations',
        to: '/integrations/ozon',
        icon: Plug,
        roles: ['admin'],
        ability: 'settings.manage',
        tabs: [
            { to: '/integrations/ozon', label: 'Ozon Express' },
            { to: '/integrations/speedaf', label: 'Speedaf' },
            { to: '/integrations/shopify', label: 'Shopify' },
        ],
    },
    {
        id: 'equipe',
        label: 'Équipe',
        to: '/equipe',
        icon: UsersRound,
        roles: ['admin'],
        tabs: [
            { to: '/equipe', label: 'Performance', end: true },
            { to: '/equipe/commissions', label: 'Commissions' },
        ],
    },
    {
        id: 'utilisateurs',
        label: 'Utilisateurs',
        to: '/utilisateurs',
        icon: Users,
        roles: ['admin'],
        ability: 'users.manage',
        tabs: [{ to: '/utilisateurs', label: 'Liste des utilisateurs' }],
    },
    {
        id: 'parametres',
        label: 'Paramètres',
        to: '/parametres',
        icon: Settings,
        roles: ['admin'],
        ability: 'settings.manage',
        tabs: [
            { to: '/parametres', label: 'Société & tarifs', end: true },
            { to: '/parametres/statuts', label: 'Statuts de livraison' },
            { to: '/parametres/partenaires', label: 'Partenaires logistiques' },
            { to: '/parametres/transporteurs/ozon', label: 'Transporteurs' },
            { to: '/parametres/equipe', label: 'Équipe & rémunération' },
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
    const can = (ability) => user.role === 'superadmin' || (user.permissions || []).includes(ability);
    return modules
        .filter((mod) => (mod.roles || []).includes('admin') && (!mod.ability || can(mod.ability)))
        .map((mod) => (mod.tabs.some((t) => t.ability) ? { ...mod, tabs: mod.tabs.filter((t) => !t.ability || can(t.ability)) } : mod));
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

    // Detail pages (e.g. /clients/2126…) belong to the module whose root prefixes them.
    const owner = list.find((m) => m.to !== '/' && normalized.startsWith(`${m.to}/`));
    if (owner) return owner;

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
    if (user && user.role !== 'superadmin' && !(user.permissions || []).includes('dashboard.view')) {
        return '/confirmation';
    }
    return '/';
}
