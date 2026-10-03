import { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import AppLayout from './components/layout/AppLayout';
import { GuestRoute, ProtectedRoute, AdminRoute, DriverRoute } from './components/auth/RouteGuards';
import { useAuth } from './contexts/AuthContext';
import { homePathForUser } from './navigation';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Orders = lazy(() => import('./pages/Orders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Products = lazy(() => import('./pages/Products'));
const Centre = lazy(() => import('./pages/Centre'));
const ConfirmationCentre = lazy(() => import('./pages/ConfirmationCentre'));
const Missions = lazy(() => import('./pages/Missions'));
const Closing = lazy(() => import('./pages/Closing'));
const Settings = lazy(() => import('./pages/Settings'));
const StatusSettings = lazy(() => import('./pages/StatusSettings'));
const LogisticsPartners = lazy(() => import('./pages/LogisticsPartners'));
const Confirmation = lazy(() => import('./pages/Confirmation'));
const Assignment = lazy(() => import('./pages/Assignment'));
const Drivers = lazy(() => import('./pages/Drivers'));
const DriverMissions = lazy(() => import('./pages/DriverMissions'));
const PlaceholderPage = lazy(() => import('./pages/PlaceholderPage'));
const NotFound = lazy(() => import('./pages/NotFound'));
const Login = lazy(() => import('./pages/Login'));
const Profile = lazy(() => import('./pages/Profile'));
const ShopifyIntegration = lazy(() => import('./pages/ShopifyIntegration'));
const SpeedafIntegration = lazy(() => import('./pages/SpeedafIntegration'));
const WhatsAppInbox = lazy(() => import('./pages/whatsapp/WhatsAppInbox'));
const WhatsAppAccounts = lazy(() => import('./pages/whatsapp/WhatsAppAccounts'));
const WhatsAppTemplates = lazy(() => import('./pages/whatsapp/WhatsAppTemplates'));
const WhatsAppQuickReplies = lazy(() => import('./pages/whatsapp/WhatsAppQuickReplies'));

function PageFallback() {
    return (
        <div className="flex min-h-[50vh] items-center justify-center text-sm font-medium text-slate-500">
            Chargement…
        </div>
    );
}

function HomeRedirect() {
    const { user } = useAuth();
    return <Navigate to={homePathForUser(user)} replace />;
}

export default function AppRouter() {
    return (
        <Suspense fallback={<PageFallback />}>
            <Routes>
                <Route
                    path="/login"
                    element={
                        <GuestRoute>
                            <Login />
                        </GuestRoute>
                    }
                />

                <Route
                    element={
                        <ProtectedRoute>
                            <AppLayout />
                        </ProtectedRoute>
                    }
                >
                    <Route
                        index
                        element={
                            <AdminRoute>
                                <Dashboard />
                            </AdminRoute>
                        }
                    />
                    <Route path="profil" element={<Profile />} />
                    <Route
                        path="commandes"
                        element={
                            <AdminRoute>
                                <Orders />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="commandes/:id"
                        element={
                            <AdminRoute>
                                <OrderDetail />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="centre"
                        element={
                            <AdminRoute>
                                <Centre />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="produits"
                        element={
                            <AdminRoute>
                                <Products />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="confirmation"
                        element={
                            <AdminRoute>
                                <Confirmation />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="confirmation/:id"
                        element={
                            <AdminRoute>
                                <ConfirmationCentre />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="a-attribuer"
                        element={
                            <AdminRoute>
                                <Assignment />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="livreurs"
                        element={
                            <AdminRoute>
                                <Drivers />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="missions"
                        element={
                            <AdminRoute>
                                <Missions />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="mes-missions"
                        element={
                            <DriverRoute>
                                <DriverMissions />
                            </DriverRoute>
                        }
                    />
                    <Route
                        path="whatsapp/comptes"
                        element={
                            <AdminRoute>
                                <WhatsAppAccounts />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="whatsapp/templates"
                        element={
                            <AdminRoute>
                                <WhatsAppTemplates />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="whatsapp/reponses-rapides"
                        element={
                            <AdminRoute>
                                <WhatsAppQuickReplies />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="whatsapp"
                        element={
                            <AdminRoute>
                                <WhatsAppInbox />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="cloture"
                        element={
                            <AdminRoute>
                                <Closing />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="utilisateurs"
                        element={
                            <AdminRoute>
                                <PlaceholderPage />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="parametres"
                        element={
                            <AdminRoute>
                                <Settings />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="parametres/statuts"
                        element={
                            <AdminRoute>
                                <StatusSettings />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="parametres/partenaires"
                        element={
                            <AdminRoute>
                                <LogisticsPartners />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="integrations/ozone"
                        element={
                            <AdminRoute>
                                <PlaceholderPage />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="integrations/speedaf"
                        element={
                            <AdminRoute>
                                <SpeedafIntegration />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="integrations/shopify"
                        element={
                            <AdminRoute>
                                <ShopifyIntegration />
                            </AdminRoute>
                        }
                    />
                    <Route path="home" element={<HomeRedirect />} />
                    <Route path="*" element={<NotFound />} />
                </Route>
            </Routes>
        </Suspense>
    );
}
