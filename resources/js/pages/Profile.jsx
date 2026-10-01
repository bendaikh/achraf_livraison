import { useEffect, useState } from 'react';
import { useAuth } from '../contexts/AuthContext';

export default function Profile() {
    const { user, updateProfile } = useAuth();
    const [name, setName] = useState(user?.name ?? '');
    const [email, setEmail] = useState(user?.email ?? '');
    const [currentPassword, setCurrentPassword] = useState('');
    const [password, setPassword] = useState('');
    const [passwordConfirmation, setPasswordConfirmation] = useState('');
    const [errors, setErrors] = useState({});
    const [message, setMessage] = useState('');
    const [submitting, setSubmitting] = useState(false);

    useEffect(() => {
        setName(user?.name ?? '');
        setEmail(user?.email ?? '');
    }, [user]);

    async function handleSubmit(event) {
        event.preventDefault();
        setErrors({});
        setMessage('');
        setSubmitting(true);

        try {
            const payload = { name: name.trim(), email: email.trim() };
            if (password) {
                payload.current_password = currentPassword;
                payload.password = password;
                payload.password_confirmation = passwordConfirmation;
            }

            const data = await updateProfile(payload);
            setMessage(data.message || 'Profil mis à jour.');
            setCurrentPassword('');
            setPassword('');
            setPasswordConfirmation('');
        } catch (err) {
            setErrors(err.response?.data?.errors || {});
            if (!err.response?.data?.errors) {
                setMessage(err.response?.data?.message || 'Mise à jour impossible.');
            }
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="mx-auto max-w-2xl space-y-6">
            <div>
                <h1 className="text-xl font-bold tracking-tight text-slate-900 sm:text-2xl">Mon profil</h1>
                <p className="mt-1 text-sm font-medium text-slate-500">
                    Modifiez vos informations de compte
                </p>
            </div>

            <form
                onSubmit={handleSubmit}
                className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm shadow-slate-200/40 sm:p-7"
            >
                {message ? (
                    <div
                        className={`mb-4 rounded-xl border px-3.5 py-2.5 text-sm font-medium ${
                            Object.keys(errors).length
                                ? 'border-rose-200 bg-rose-50 text-rose-700'
                                : 'border-emerald-200 bg-emerald-50 text-emerald-700'
                        }`}
                    >
                        {message}
                    </div>
                ) : null}

                <div className="grid gap-4 sm:grid-cols-2">
                    <label className="block sm:col-span-2">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Nom
                        </span>
                        <input
                            type="text"
                            required
                            value={name}
                            onChange={(e) => setName(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                        {errors.name ? (
                            <span className="mt-1 block text-xs font-medium text-rose-600">{errors.name[0]}</span>
                        ) : null}
                    </label>

                    <label className="block sm:col-span-2">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Email
                        </span>
                        <input
                            type="email"
                            required
                            value={email}
                            onChange={(e) => setEmail(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                        {errors.email ? (
                            <span className="mt-1 block text-xs font-medium text-rose-600">{errors.email[0]}</span>
                        ) : null}
                    </label>

                    <div className="sm:col-span-2">
                        <div className="mb-3 mt-2 border-t border-slate-100 pt-4 text-sm font-semibold text-slate-800">
                            Changer le mot de passe
                            <span className="ml-1 font-medium text-slate-400">(optionnel)</span>
                        </div>
                    </div>

                    <label className="block sm:col-span-2">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Mot de passe actuel
                        </span>
                        <input
                            type="password"
                            autoComplete="current-password"
                            value={currentPassword}
                            onChange={(e) => setCurrentPassword(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                        {errors.current_password ? (
                            <span className="mt-1 block text-xs font-medium text-rose-600">
                                {errors.current_password[0]}
                            </span>
                        ) : null}
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Nouveau mot de passe
                        </span>
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                        {errors.password ? (
                            <span className="mt-1 block text-xs font-medium text-rose-600">
                                {errors.password[0]}
                            </span>
                        ) : null}
                    </label>

                    <label className="block">
                        <span className="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-slate-500">
                            Confirmation
                        </span>
                        <input
                            type="password"
                            autoComplete="new-password"
                            value={passwordConfirmation}
                            onChange={(e) => setPasswordConfirmation(e.target.value)}
                            className="h-11 w-full rounded-xl border border-slate-200 bg-slate-50 px-3 text-sm outline-none transition focus:border-blue-300 focus:bg-white focus:ring-4 focus:ring-blue-500/10"
                        />
                    </label>
                </div>

                <div className="mt-6 flex items-center justify-between gap-3">
                    <div className="text-xs font-medium text-slate-400">
                        Rôle : {user?.role_label || '—'}
                    </div>
                    <button
                        type="submit"
                        disabled={submitting}
                        className="inline-flex h-11 items-center justify-center rounded-xl bg-blue-600 px-5 text-sm font-bold text-white shadow-md shadow-blue-500/20 transition hover:bg-blue-700 disabled:opacity-60"
                    >
                        {submitting ? 'Enregistrement…' : 'Enregistrer'}
                    </button>
                </div>
            </form>
        </div>
    );
}
