import { Navigate, useLocation } from 'react-router-dom';
import { useAuth } from '../../contexts/AuthContext';
import { homePathForUser } from '../../navigation';

function AuthLoading() {
    return (
        <div className="flex min-h-screen items-center justify-center text-sm font-medium text-slate-500">
            Chargement…
        </div>
    );
}

export function ProtectedRoute({ children }) {
    const { isAuthenticated, loading } = useAuth();
    const location = useLocation();

    if (loading) {
        return <AuthLoading />;
    }

    if (!isAuthenticated) {
        return <Navigate to="/login" replace state={{ from: location }} />;
    }

    return children;
}

export function GuestRoute({ children }) {
    const { isAuthenticated, loading, user } = useAuth();

    if (loading) {
        return <AuthLoading />;
    }

    if (isAuthenticated) {
        return <Navigate to={homePathForUser(user)} replace />;
    }

    return children;
}

export function AdminRoute({ children }) {
    const { user, loading } = useAuth();

    if (loading) {
        return <AuthLoading />;
    }

    if (user?.is_livreur || user?.role === 'livreur') {
        return <Navigate to="/mes-missions" replace />;
    }

    return children;
}

export function DriverRoute({ children }) {
    const { user, loading } = useAuth();

    if (loading) {
        return <AuthLoading />;
    }

    if (user?.is_livreur || user?.role === 'livreur') {
        return children;
    }

    // Admins can also open the page for preview if needed later;
    // for now keep it livreur-only and send admin home.
    return <Navigate to="/" replace />;
}

/** Page reserved to an ability (config/permissions.php); agents get a clear refusal. */
export function PermissionRoute({ ability, children }) {
    const { user, loading, can } = useAuth();
    if (loading) {
        return <AuthLoading />;
    }
    if (user?.is_livreur || user?.role === 'livreur') {
        return <Navigate to="/mes-missions" replace />;
    }
    if (!can(ability)) {
        return (
            <div className="mx-auto mt-16 max-w-md rounded-2xl border border-slate-200 bg-white p-6 text-center shadow-sm">
                <p className="text-base font-bold text-slate-900">Accès réservé</p>
                <p className="mt-1 text-sm text-slate-500">Votre rôle ne donne pas accès à cette page. Contactez votre responsable si besoin.</p>
            </div>
        );
    }
    return children;
}
