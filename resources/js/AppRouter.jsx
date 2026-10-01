import { Suspense, lazy } from 'react';
import { Routes, Route } from 'react-router-dom';
import AppLayout from './components/layout/AppLayout';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Orders = lazy(() => import('./pages/Orders'));
const Settings = lazy(() => import('./pages/Settings'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Drivers = lazy(() => import('./pages/Drivers'));
const Missions = lazy(() => import('./pages/Missions'));
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
                    <Route path="commandes" element={<Orders />} />
                    <Route path="whatsapp" element={<PlaceholderPage />} />
                    <Route path="commandes/:id" element={<OrderDetail />} />
                    <Route path="livreurs" element={<Drivers />} />
                    <Route path="missions" element={<Missions />} />
                    <Route path="cloture" element={<PlaceholderPage />} />
                    <Route path="utilisateurs" element={<PlaceholderPage />} />
                    <Route path="parametres" element={<Settings />} />
                    <Route path="integrations/ozone" element={<PlaceholderPage />} />
                    <Route path="integrations/speedaf" element={<PlaceholderPage />} />
                    <Route path="integrations/libromart" element={<PlaceholderPage />} />
                    <Route path="*" element={<NotFound />} />
                </Route>
            </Routes>
        </Suspense>
    );
}
