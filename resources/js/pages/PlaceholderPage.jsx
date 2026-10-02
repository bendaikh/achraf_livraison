import { useLocation } from 'react-router-dom';
import { getActiveModule } from '../navigation';
import { useAuth } from '../contexts/AuthContext';

const titles = {
    '/': 'Vue générale',
    '/commandes': 'Commandes',
    '/whatsapp': 'Messagerie WhatsApp',
    '/livreurs': 'Livreurs',
    '/cloture': 'Clôture du jour',
    '/utilisateurs': 'Utilisateurs',
    '/parametres': 'Paramètres',
    '/integrations/ozone': 'Ozone Delivery',
    '/integrations/shopify': 'Shopify',
};

export default function PlaceholderPage() {
    const { pathname } = useLocation();
    const { user } = useAuth();
    const module = getActiveModule(pathname, user);
    const title = titles[pathname] ?? 'Page';

    return (
        <div className="rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-16 text-center shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">
                {module?.label}
            </p>
            <h1 className="mt-2 text-2xl font-bold text-slate-900">{title}</h1>
            <p className="mt-2 text-sm font-medium text-slate-500">
                Section prête — le contenu sera ajouté ensuite.
            </p>
        </div>
    );
}
