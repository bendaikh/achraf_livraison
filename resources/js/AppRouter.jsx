import { Suspense, lazy } from 'react';
import { Routes, Route } from 'react-router-dom';
import AppLayout from './components/layout/AppLayout';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const PlaceholderPage = lazy(() => import('./pages/PlaceholderPage'));
const NotFound = lazy(() => import('./pages/NotFound'));

function PageFallback() {
    return (
        <div className="flex min-h-[50vh] items-center justify-center text-sm font-medium text-slate-500">
            Chargement…
        </div>
    );
}

export default function AppRouter() {
    return (
        <Suspense fallback={<PageFallback />}>
            <Routes>
                <Route element={<AppLayout />}>
                    <Route index element={<Dashboard />} />
                    <Route path="commandes" element={<PlaceholderPage />} />
                    <Route path="whatsapp" element={<PlaceholderPage />} />
                    <Route path="livreurs" element={<PlaceholderPage />} />
                    <Route path="cloture" element={<PlaceholderPage />} />
                    <Route path="utilisateurs" element={<PlaceholderPage />} />
                    <Route path="parametres" element={<PlaceholderPage />} />
                    <Route path="integrations/ozone" element={<PlaceholderPage />} />
                    <Route path="integrations/speedaf" element={<PlaceholderPage />} />
                    <Route path="integrations/libromart" element={<PlaceholderPage />} />
                    <Route path="*" element={<NotFound />} />
                </Route>
            </Routes>
        </Suspense>
    );
}
