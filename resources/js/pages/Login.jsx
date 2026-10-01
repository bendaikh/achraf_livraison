import { useState } from 'react';
import { Navigate, useNavigate } from 'react-router-dom';
import { Eye, EyeOff, Lock, Mail, Truck } from 'lucide-react';
import { useAuth } from '../contexts/AuthContext';
import { homePathForUser } from '../navigation';

export default function Login() {
    const { login, isAuthenticated, loading, user } = useAuth();
    const navigate = useNavigate();
    const [email, setEmail] = useState('');
    const [password, setPassword] = useState('');
    const [showPassword, setShowPassword] = useState(false);
    const [remember, setRemember] = useState(true);
    const [error, setError] = useState('');
    const [submitting, setSubmitting] = useState(false);

    if (!loading && isAuthenticated) {
        return <Navigate to={homePathForUser(user)} replace />;
    }

    async function handleSubmit(event) {
        event.preventDefault();
        setError('');
        setSubmitting(true);

        try {
            const nextUser = await login(email.trim(), password, remember);
            navigate(homePathForUser(nextUser), { replace: true });
        } catch (err) {
            const message =
                err.response?.data?.errors?.email?.[0] ||
                err.response?.data?.message ||
                'Connexion impossible. Vérifiez vos identifiants.';
            setError(message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="relative flex min-h-screen items-center justify-center overflow-hidden px-4 py-10">
            <div className="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_top,_#dbeafe_0%,_#f1f5f9_45%,_#e2e8f0_100%)]" />
            <div className="pointer-events-none absolute -left-24 top-20 h-72 w-72 rounded-full bg-blue-400/20 blur-3xl" />
            <div className="pointer-events-none absolute -right-16 bottom-10 h-80 w-80 rounded-full bg-sky-300/25 blur-3xl" />

            <div className="relative w-full max-w-md">
                <div className="mb-8 text-center">
                    <div className="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-gradient-to-br from-blue-600 to-sky-500 text-white shadow-lg shadow-blue-500/30">
                        <Truck className="h-7 w-7" strokeWidth={2.2} />
                    </div>
                    <h1 className="text-2xl font-extrabold tracking-tight text-slate-900 sm:text-3xl">
                        Lavafast
                    </h1>
                    <p className="mt-1.5 text-sm font-medium text-slate-500">
                        Connectez-vous pour accéder à la plateforme
                    </p>
                </div>

                <form
                    onSubmit={handleSubmit}
                    className="rounded-2xl border border-white/70 bg-white/90 p-6 shadow-xl shadow-slate-200/60 backdrop-blur sm:p-8"
                >
                    <h2 className="text-lg font-bold text-slate-900">Connexion</h2>
                    <p className="mt-1 text-sm text-slate-500">Admin ou livreur — même portail</p>

                    {error ? (
                        <div className="mt-4 rounded-xl border border-rose-200 bg-rose-50 px-3.5 py-2.5 text-sm font-medium text-rose-700">
                            {error}
                        </div>
                    ) : null}

                    <label className="mt-5 block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Email
                        </span>
                        <div className="relative">
                            <Mail className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type="email"
                                autoComplete="username"
                                required
                                value={email}
                                onChange={(e) => setEmail(e.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-3 text-sm text-slate-900 outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                                placeholder="vous@exemple.com"
                            />
                        </div>
                    </label>

                    <label className="mt-4 block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Mot de passe
                        </span>
                        <div className="relative">
                            <Lock className="pointer-events-none absolute left-3.5 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                type={showPassword ? 'text' : 'password'}
                                autoComplete="current-password"
                                required
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 pl-10 pr-11 text-sm text-slate-900 outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                                placeholder="••••••••"
                            />
                            <button
                                type="button"
                                onClick={() => setShowPassword((v) => !v)}
                                className="absolute right-2 top-1/2 inline-flex h-8 w-8 -translate-y-1/2 items-center justify-center rounded-lg text-slate-400 transition hover:bg-slate-100 hover:text-slate-600"
                                aria-label={showPassword ? 'Masquer' : 'Afficher'}
                            >
                                {showPassword ? <EyeOff className="h-4 w-4" /> : <Eye className="h-4 w-4" />}
                            </button>
                        </div>
                    </label>

                    <label className="mt-4 flex items-center gap-2 text-sm text-slate-600">
                        <input
                            type="checkbox"
                            checked={remember}
                            onChange={(e) => setRemember(e.target.checked)}
                            className="h-4 w-4 rounded border-slate-300 text-blue-600 focus:ring-blue-500"
                        />
                        Se souvenir de moi
                    </label>

                    <button
                        type="submit"
                        disabled={submitting}
                        className="mt-6 inline-flex h-11 w-full items-center justify-center rounded-xl bg-gradient-to-r from-blue-600 to-sky-500 text-sm font-bold text-white shadow-lg shadow-blue-500/25 transition hover:brightness-105 disabled:cursor-not-allowed disabled:opacity-60"
                    >
                        {submitting ? 'Connexion…' : 'Se connecter'}
                    </button>

                    <div className="mt-5 rounded-xl border border-dashed border-slate-200 bg-slate-50 px-3.5 py-3 text-left">
                        <div className="text-[11px] font-bold uppercase tracking-wide text-slate-400">
                            Identifiants Super Admin
                        </div>
                        <div className="mt-1.5 space-y-0.5 text-sm text-slate-700">
                            <p>
                                <span className="font-medium text-slate-500">Email :</span>{' '}
                                <span className="font-semibold">superadmin@lavfast-flow.com</span>
                            </p>
                            <p>
                                <span className="font-medium text-slate-500">Mot de passe :</span>{' '}
                                <span className="font-semibold">SuperAdmin@2026</span>
                            </p>
                        </div>
                        <button
                            type="button"
                            onClick={() => {
                                setEmail('superadmin@lavfast-flow.com');
                                setPassword('SuperAdmin@2026');
                                setError('');
                            }}
                            className="mt-2.5 text-xs font-bold text-blue-600 transition hover:text-blue-700"
                        >
                            Remplir automatiquement
                        </button>
                    </div>
                </form>
            </div>
        </div>
    );
}
