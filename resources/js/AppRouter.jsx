import { Suspense, lazy } from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import AppLayout from './components/layout/AppLayout';
import { GuestRoute, ProtectedRoute, AdminRoute, DriverRoute, PermissionRoute } from './components/auth/RouteGuards';
import { useAuth } from './contexts/AuthContext';
import { homePathForUser } from './navigation';

const Dashboard = lazy(() => import('./pages/Dashboard'));
const Orders = lazy(() => import('./pages/Orders'));
const OrderDetail = lazy(() => import('./pages/OrderDetail'));
const Products = lazy(() => import('./pages/Products'));
const Centre = lazy(() => import('./pages/Centre'));
const ConfirmationCentre = lazy(() => import('./pages/ConfirmationCentre'));
const Users = lazy(() => import('./pages/Users'));
const Clients = lazy(() => import('./pages/Clients'));
const Sav = lazy(() => import('./pages/Sav'));
const SavCustody = lazy(() => import('./pages/SavCustody'));
const DriverSav = lazy(() => import('./pages/DriverSav'));
const ClientDetail = lazy(() => import('./pages/ClientDetail'));
const TeamPerformance = lazy(() => import('./pages/TeamPerformance'));
const TeamCommissions = lazy(() => import('./pages/TeamCommissions'));
const TeamServices = lazy(() => import('./pages/TeamServices'));
const Missions = lazy(() => import('./pages/Missions'));
const Closing = lazy(() => import('./pages/Closing'));
const Settings = lazy(() => import('./pages/Settings'));
const StatusSettings = lazy(() => import('./pages/StatusSettings'));
const ConfirmationStatusSettings = lazy(() => import('./pages/ConfirmationStatusSettings'));
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
const OzonIntegration = lazy(() => import('./pages/OzonIntegration'));
const SiftIntegration = lazy(() => import('./pages/SiftIntegration'));
const WhatsAppInbox = lazy(() => import('./pages/whatsapp/WhatsAppInbox'));
const WhatsAppAccounts = lazy(() => import('./pages/whatsapp/WhatsAppAccounts'));
const WhatsAppTemplates = lazy(() => import('./pages/whatsapp/WhatsAppTemplates'));
const WhatsAppQuickReplies = lazy(() => import('./pages/whatsapp/WhatsAppQuickReplies'));
const CampaignsList = lazy(() => import('./pages/campaigns/CampaignsList'));
const CampaignWizard = lazy(() => import('./pages/campaigns/CampaignWizard'));
const CampaignDetail = lazy(() => import('./pages/campaigns/CampaignDetail'));
const AutomationsList = lazy(() => import('./pages/automations/AutomationsList'));
const AutomationBuilder = lazy(() => import('./pages/automations/AutomationBuilder'));
const AutomationRuns = lazy(() => import('./pages/automations/AutomationRuns'));
const AutomationTest = lazy(() => import('./pages/automations/AutomationTest'));

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
                            <PermissionRoute ability="dashboard.view">
                                <Dashboard />
                            </PermissionRoute>
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
                            <PermissionRoute ability="dashboard.view">
                                <Centre />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="produits"
                        element={
                            <PermissionRoute ability="products.view">
                                <Products />
                            </PermissionRoute>
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
                            <PermissionRoute ability="drivers.manage">
                                <Assignment />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="livreurs"
                        element={
                            <PermissionRoute ability="drivers.manage">
                                <Drivers />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="missions"
                        element={
                            <PermissionRoute ability="drivers.manage">
                                <Missions />
                            </PermissionRoute>
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
                            <PermissionRoute ability="settings.manage">
                                <WhatsAppAccounts />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/templates"
                        element={
                            <PermissionRoute ability="whatsapp.access">
                                <WhatsAppTemplates />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/reponses-rapides"
                        element={
                            <PermissionRoute ability="whatsapp.access">
                                <WhatsAppQuickReplies />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/campagnes"
                        element={
                            <PermissionRoute ability="campaigns.view">
                                <CampaignsList />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/campagnes/new"
                        element={
                            <PermissionRoute ability="campaigns.manage">
                                <CampaignWizard />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/campagnes/:id/edit"
                        element={
                            <PermissionRoute ability="campaigns.manage">
                                <CampaignWizard />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp/campagnes/:id"
                        element={
                            <PermissionRoute ability="campaigns.view">
                                <CampaignDetail />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="whatsapp"
                        element={
                            <PermissionRoute ability="whatsapp.access">
                                <WhatsAppInbox />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="cloture"
                        element={
                            <PermissionRoute ability="closings.manage">
                                <Closing />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="utilisateurs"
                        element={
                            <PermissionRoute ability="users.manage">
                                <Users />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="parametres"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <Settings />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="parametres/statuts"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <StatusSettings />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="parametres/statuts-confirmation"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <ConfirmationStatusSettings />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="parametres/partenaires"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <LogisticsPartners />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="integrations/ozon"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <OzonIntegration />
                            </PermissionRoute>
                        }
                    />
                    <Route path="integrations/ozone" element={<Navigate to="/integrations/ozon" replace />} />
                    <Route
                        path="parametres/transporteurs/ozon"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <OzonIntegration defaultTab="villes" title="Transporteurs · Ozon Express" />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="integrations/sift"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <SiftIntegration />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="parametres/transporteurs/sift"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <SiftIntegration defaultTab="statuts" title="Transporteurs · Sift.ma" />
                            </PermissionRoute>
                        }
                    />
                    <Route path="parametres/transporteurs" element={<Navigate to="/parametres/transporteurs/ozon" replace />} />
                    <Route
                        path="integrations/speedaf"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <SpeedafIntegration />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="integrations/shopify"
                        element={
                            <PermissionRoute ability="settings.manage">
                                <ShopifyIntegration />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="automations"
                        element={
                            <PermissionRoute ability="automations.view">
                                <AutomationsList />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="automations/runs"
                        element={
                            <PermissionRoute ability="automations.view">
                                <AutomationRuns />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="automations/new"
                        element={
                            <PermissionRoute ability="automations.manage">
                                <AutomationBuilder />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="automations/:id/edit"
                        element={
                            <PermissionRoute ability="automations.manage">
                                <AutomationBuilder />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="automations/:id/test"
                        element={
                            <PermissionRoute ability="automations.test">
                                <AutomationTest />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="equipe"
                        element={
                            <AdminRoute>
                                <TeamPerformance />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="equipe/commissions"
                        element={
                            <AdminRoute>
                                <TeamCommissions />
                            </AdminRoute>
                        }
                    />
                    <Route
                        path="parametres/equipe"
                        element={
                            <PermissionRoute ability="users.manage">
                                <TeamServices />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="clients"
                        element={
                            <PermissionRoute ability="clients.view">
                                <Clients />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="clients/bloques"
                        element={
                            <PermissionRoute ability="clients.view">
                                <Clients />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="clients/segments"
                        element={
                            <PermissionRoute ability="clients.view">
                                <Clients />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="clients/groupes"
                        element={
                            <PermissionRoute ability="clients.view">
                                <Clients />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="clients/:key"
                        element={
                            <PermissionRoute ability="clients.view">
                                <ClientDetail />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="retours"
                        element={
                            <PermissionRoute ability="sav.manage">
                                <Sav />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="retours/articles"
                        element={
                            <PermissionRoute ability="sav.manage">
                                <SavCustody />
                            </PermissionRoute>
                        }
                    />
                    <Route
                        path="mes-retours"
                        element={
                            <DriverRoute>
                                <DriverSav />
                            </DriverRoute>
                        }
                    />
                    <Route path="home" element={<HomeRedirect />} />
                    <Route path="*" element={<NotFound />} />
                </Route>
            </Routes>
        </Suspense>
    );
}
