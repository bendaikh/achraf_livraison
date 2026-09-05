export const kpiStats = [
    {
        id: 'today',
        title: "Commandes aujourd'hui",
        value: 128,
        change: 12,
        trend: 'up',
        subtitle: 'vs hier',
        color: 'blue',
    },
    {
        id: 'delivered',
        title: 'Livrées',
        value: 96,
        change: 8,
        trend: 'up',
        subtitle: 'taux 75%',
        color: 'green',
    },
    {
        id: 'progress',
        title: 'En cours',
        value: 24,
        change: 5,
        trend: 'down',
        subtitle: 'en livraison',
        color: 'orange',
    },
    {
        id: 'canceled',
        title: 'Annulées',
        value: 8,
        change: 2,
        trend: 'down',
        subtitle: 'taux 6%',
        color: 'red',
    },
];

export const chartData = [
    { day: 'Lun', total: 95, delivered: 72, canceled: 8 },
    { day: 'Mar', total: 110, delivered: 85, canceled: 10 },
    { day: 'Mer', total: 88, delivered: 68, canceled: 6 },
    { day: 'Jeu', total: 125, delivered: 98, canceled: 12 },
    { day: 'Ven', total: 140, delivered: 110, canceled: 9 },
    { day: 'Sam', total: 118, delivered: 92, canceled: 11 },
    { day: 'Dim', total: 128, delivered: 96, canceled: 8 },
];

export const statusBreakdown = [
    { name: 'Livrées', value: 96, percent: 75, color: '#22c55e' },
    { name: 'En cours', value: 24, percent: 19, color: '#f97316' },
    { name: 'Annulées', value: 8, percent: 6, color: '#ef4444' },
];

export const topDrivers = [
    { id: 1, name: 'Yassine Alaoui', deliveries: 18, score: 98, avatar: 'YA', color: '#2563eb' },
    { id: 2, name: 'Mohamed Benali', deliveries: 15, score: 95, avatar: 'MB', color: '#0ea5e9' },
    { id: 3, name: 'Karim Tazi', deliveries: 14, score: 92, avatar: 'KT', color: '#8b5cf6' },
    { id: 4, name: 'Hassan Idrissi', deliveries: 12, score: 89, avatar: 'HI', color: '#14b8a6' },
    { id: 5, name: 'Amine Choukri', deliveries: 11, score: 87, avatar: 'AC', color: '#f59e0b' },
];

export const recentOrders = [
    {
        id: 1028,
        client: 'Fatima Zahra',
        phone: '06 12 34 56 78',
        city: 'Casablanca',
        amount: 250,
        status: 'livree',
        date: '05/09/2026 14:32',
    },
    {
        id: 1027,
        client: 'Omar Bennani',
        phone: '06 98 76 54 32',
        city: 'Rabat',
        amount: 180,
        status: 'en_cours',
        date: '05/09/2026 14:18',
    },
    {
        id: 1026,
        client: 'Sara El Amrani',
        phone: '07 11 22 33 44',
        city: 'Marrakech',
        amount: 320,
        status: 'en_attente',
        date: '05/09/2026 13:55',
    },
    {
        id: 1025,
        client: 'Youssef Kadiri',
        phone: '06 55 66 77 88',
        city: 'Fès',
        amount: 95,
        status: 'annulee',
        date: '05/09/2026 13:40',
    },
    {
        id: 1024,
        client: 'Nadia Cherkaoui',
        phone: '06 44 33 22 11',
        city: 'Tanger',
        amount: 410,
        status: 'livree',
        date: '05/09/2026 13:12',
    },
    {
        id: 1023,
        client: 'Mehdi Lahlou',
        phone: '07 99 88 77 66',
        city: 'Agadir',
        amount: 175,
        status: 'en_cours',
        date: '05/09/2026 12:48',
    },
];

export const recentActivity = [
    {
        id: 1,
        type: 'order',
        title: 'Nouvelle commande #1028',
        detail: 'Fatima Zahra · Casablanca',
        time: 'il y a 5 min',
    },
    {
        id: 2,
        type: 'delivered',
        title: 'Commande livrée #1024',
        detail: 'Nadia Cherkaoui · Tanger',
        time: 'il y a 12 min',
    },
    {
        id: 3,
        type: 'whatsapp',
        title: 'Message WhatsApp reçu',
        detail: 'Omar Bennani demande le suivi',
        time: 'il y a 18 min',
    },
    {
        id: 4,
        type: 'order',
        title: 'Nouvelle commande #1027',
        detail: 'Omar Bennani · Rabat',
        time: 'il y a 25 min',
    },
    {
        id: 5,
        type: 'canceled',
        title: 'Commande annulée #1025',
        detail: 'Youssef Kadiri · Fès',
        time: 'il y a 40 min',
    },
    {
        id: 6,
        type: 'delivered',
        title: 'Commande livrée #1021',
        detail: 'Imane Saadi · Salé',
        time: 'il y a 1 h',
    },
];

export const statusLabels = {
    livree: { label: 'Livrée', className: 'bg-emerald-50 text-emerald-700 ring-emerald-600/15' },
    en_cours: { label: 'En cours', className: 'bg-blue-50 text-blue-700 ring-blue-600/15' },
    en_attente: { label: 'En attente', className: 'bg-amber-50 text-amber-700 ring-amber-600/15' },
    annulee: { label: 'Annulée', className: 'bg-rose-50 text-rose-700 ring-rose-600/15' },
};
