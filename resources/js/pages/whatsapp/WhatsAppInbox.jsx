import { useCallback, useEffect, useRef, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import {
    ArrowLeft,
    CheckCheck,
    Check,
    Paperclip,
    Phone,
    Search,
    Send,
    UserPlus,
    XCircle,
    FileText,
    Zap,
} from 'lucide-react';
import { useWhatsAppUnread } from '../../contexts/WhatsAppUnreadContext';
import { formatConversationTime, formatMessageTime, statusLabel } from './helpers';
import { formatMoney, telUrl } from '../confirmationHelpers';

const FILTERS = [
    { value: 'all', label: 'Toutes' },
    { value: 'unread', label: 'Non lues' },
    { value: 'assigned', label: 'Assignées' },
    { value: 'pending', label: 'En attente' },
    { value: 'resolved', label: 'Traitées' },
];

function StatusTicks({ status }) {
    if (status === 'failed') {
        return <XCircle className="h-3.5 w-3.5 text-rose-500" />;
    }
    if (status === 'read') {
        return <CheckCheck className="h-3.5 w-3.5 text-sky-500" />;
    }
    if (status === 'delivered') {
        return <CheckCheck className="h-3.5 w-3.5 text-slate-400" />;
    }
    if (status === 'sent' || status === 'pending') {
        return <Check className="h-3.5 w-3.5 text-slate-400" />;
    }
    return null;
}

export default function WhatsAppInbox() {
    const [searchParams, setSearchParams] = useSearchParams();
    const { refreshUnread } = useWhatsAppUnread();
    const [conversations, setConversations] = useState([]);
    const [filter, setFilter] = useState('all');
    const [search, setSearch] = useState('');
    const [debouncedSearch, setDebouncedSearch] = useState('');
    const [selectedId, setSelectedId] = useState(null);
    const [conversation, setConversation] = useState(null);
    const [messages, setMessages] = useState([]);
    const [body, setBody] = useState('');
    const [loadingList, setLoadingList] = useState(true);
    const [loadingThread, setLoadingThread] = useState(false);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState('');
    const [attachOpen, setAttachOpen] = useState(false);
    const [quickReplies, setQuickReplies] = useState([]);
    const [templates, setTemplates] = useState([]);
    const [picker, setPicker] = useState(null);
    const [templateVars, setTemplateVars] = useState([]);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const messagesEndRef = useRef(null);
    const fileInputRef = useRef(null);
    const [mediaType, setMediaType] = useState('image');

    useEffect(() => {
        const t = setTimeout(() => setDebouncedSearch(search.trim()), 250);
        return () => clearTimeout(t);
    }, [search]);

    useEffect(() => {
        const c = searchParams.get('c');
        if (c) setSelectedId(Number(c));
        const q = searchParams.get('search');
        if (q) setSearch(q);
    }, [searchParams]);

    const loadList = useCallback(async () => {
        try {
            const { data } = await window.axios.get('/api/whatsapp/conversations', {
                params: {
                    filter,
                    search: debouncedSearch || undefined,
                },
            });
            setConversations(data.conversations || []);
        } catch (err) {
            setError(err.response?.data?.message || 'Impossible de charger les conversations.');
        } finally {
            setLoadingList(false);
        }
    }, [filter, debouncedSearch]);

    useEffect(() => {
        setLoadingList(true);
        loadList();
        const timer = setInterval(loadList, 8000);
        return () => clearInterval(timer);
    }, [loadList]);

    const openConversation = useCallback(
        async (id) => {
            if (!id) return;
            setLoadingThread(true);
            setError('');
            try {
                const { data } = await window.axios.get(`/api/whatsapp/conversations/${id}`);
                setConversation(data.conversation);
                setMessages(data.messages || []);
                setSelectedId(id);
                setSearchParams({ c: String(id) }, { replace: true });
                refreshUnread();
                loadList();
            } catch (err) {
                setError(err.response?.data?.message || 'Impossible d’ouvrir la conversation.');
            } finally {
                setLoadingThread(false);
            }
        },
        [setSearchParams, refreshUnread, loadList]
    );

    useEffect(() => {
        if (selectedId) openConversation(selectedId);
    }, [selectedId]); // eslint-disable-line react-hooks/exhaustive-deps

    useEffect(() => {
        messagesEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    async function sendText(text) {
        const content = (text ?? body).trim();
        if (!content || !selectedId) return;
        setSending(true);
        setError('');
        try {
            const { data } = await window.axios.post(`/api/whatsapp/conversations/${selectedId}/messages`, {
                body: content,
            });
            setMessages((prev) => [...prev, data.message]);
            setBody('');
            setPicker(null);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Envoi impossible.');
        } finally {
            setSending(false);
        }
    }

    async function handleAssign() {
        if (!selectedId) return;
        try {
            const { data } = await window.axios.post(`/api/whatsapp/conversations/${selectedId}/assign`, {});
            setConversation(data.conversation);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Assignation impossible.');
        }
    }

    async function handleResolve() {
        if (!selectedId) return;
        try {
            const { data } = await window.axios.post(`/api/whatsapp/conversations/${selectedId}/resolve`);
            setConversation(data.conversation);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Action impossible.');
        }
    }

    async function handleReopen() {
        if (!selectedId) return;
        try {
            const { data } = await window.axios.post(`/api/whatsapp/conversations/${selectedId}/reopen`);
            setConversation(data.conversation);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Action impossible.');
        }
    }

    async function openQuickReplies() {
        setAttachOpen(false);
        const { data } = await window.axios.get('/api/whatsapp/quick-replies', { params: { active_only: 1 } });
        setQuickReplies(data.quick_replies || []);
        setPicker('quick');
    }

    async function openTemplates() {
        setAttachOpen(false);
        const { data } = await window.axios.get('/api/whatsapp/templates', {
            params: { account_id: conversation?.account?.id },
        });
        setTemplates((data.templates || []).filter((t) => t.is_approved));
        setPicker('templates');
        setSelectedTemplate(null);
    }

    async function sendTemplate() {
        if (!selectedTemplate || !selectedId) return;
        setSending(true);
        try {
            const { data } = await window.axios.post(
                `/api/whatsapp/conversations/${selectedId}/messages/template`,
                {
                    template_id: selectedTemplate.id,
                    variables: templateVars,
                }
            );
            setMessages((prev) => [...prev, data.message]);
            setPicker(null);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Envoi template impossible.');
        } finally {
            setSending(false);
        }
    }

    function pickMedia(type) {
        setMediaType(type);
        setAttachOpen(false);
        setTimeout(() => fileInputRef.current?.click(), 50);
    }

    async function onFileChange(event) {
        const file = event.target.files?.[0];
        event.target.value = '';
        if (!file || !selectedId) return;
        setSending(true);
        try {
            const form = new FormData();
            form.append('file', file);
            form.append('type', mediaType);
            const { data } = await window.axios.post(
                `/api/whatsapp/conversations/${selectedId}/messages/media`,
                form
            );
            setMessages((prev) => [...prev, data.message]);
            loadList();
        } catch (err) {
            setError(err.response?.data?.message || 'Envoi média impossible.');
        } finally {
            setSending(false);
        }
    }

    const showThread = Boolean(selectedId);
    const callHref = conversation ? telUrl(conversation.contact_phone) : null;

    return (
        <div className="-mx-3 -mb-4 flex h-[calc(100dvh-8.5rem)] flex-col overflow-hidden border-y border-slate-200 bg-white sm:-mx-6 sm:h-[calc(100dvh-9.5rem)] sm:rounded-2xl sm:border">
            {error ? (
                <div className="border-b border-rose-100 bg-rose-50 px-4 py-2 text-xs font-medium text-rose-700">
                    {error}
                </div>
            ) : null}

            <div className="flex min-h-0 flex-1">
                {/* Conversation list */}
                <aside
                    className={[
                        'flex w-full shrink-0 flex-col border-slate-200 md:w-[340px] md:border-r lg:w-[380px]',
                        showThread ? 'hidden md:flex' : 'flex',
                    ].join(' ')}
                >
                    <div className="border-b border-slate-100 p-3">
                        <div className="relative">
                            <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
                            <input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Nom, téléphone, commande…"
                                className="h-10 w-full rounded-xl border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none focus:border-emerald-300 focus:bg-white focus:ring-4 focus:ring-emerald-500/10"
                            />
                        </div>
                        <div className="mt-2 flex gap-1 overflow-x-auto pb-1">
                            {FILTERS.map((f) => (
                                <button
                                    key={f.value}
                                    type="button"
                                    onClick={() => setFilter(f.value)}
                                    className={[
                                        'shrink-0 rounded-lg px-2.5 py-1 text-xs font-bold transition',
                                        filter === f.value
                                            ? 'bg-emerald-600 text-white'
                                            : 'bg-slate-100 text-slate-600 hover:bg-slate-200',
                                    ].join(' ')}
                                >
                                    {f.label}
                                </button>
                            ))}
                        </div>
                    </div>

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        {loadingList && conversations.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-slate-500">Chargement…</p>
                        ) : conversations.length === 0 ? (
                            <p className="px-4 py-8 text-center text-sm text-slate-500">
                                Aucune conversation. Connectez un numéro puis attendez les messages.
                            </p>
                        ) : (
                            conversations.map((c) => {
                                const active = c.id === selectedId;
                                const unread = c.unread_count > 0;
                                return (
                                    <button
                                        key={c.id}
                                        type="button"
                                        onClick={() => setSelectedId(c.id)}
                                        className={[
                                            'flex w-full items-start gap-3 border-b border-slate-50 px-3 py-3 text-left transition',
                                            active ? 'bg-emerald-50/80' : 'hover:bg-slate-50',
                                            unread ? 'bg-white' : '',
                                        ].join(' ')}
                                    >
                                        <div className="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-800">
                                            {c.initials}
                                        </div>
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <p
                                                    className={`truncate text-sm ${unread ? 'font-bold text-slate-900' : 'font-semibold text-slate-800'}`}
                                                >
                                                    {c.contact_name}
                                                </p>
                                                <span className="shrink-0 text-[11px] font-medium text-slate-400">
                                                    {formatConversationTime(c.last_message_at)}
                                                </span>
                                            </div>
                                            <p className="truncate text-xs font-medium text-slate-500">
                                                {c.contact_phone}
                                            </p>
                                            <div className="mt-0.5 flex items-center gap-2">
                                                <p
                                                    className={`min-w-0 flex-1 truncate text-xs ${unread ? 'font-semibold text-slate-700' : 'text-slate-500'}`}
                                                >
                                                    {c.last_message_preview || '—'}
                                                </p>
                                                {unread ? (
                                                    <span className="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[10px] font-bold text-white">
                                                        {c.unread_count}
                                                    </span>
                                                ) : null}
                                            </div>
                                            {c.account ? (
                                                <p className="mt-1 truncate text-[10px] font-semibold uppercase tracking-wide text-slate-400">
                                                    via {c.account.name}
                                                </p>
                                            ) : null}
                                        </div>
                                    </button>
                                );
                            })
                        )}
                    </div>
                </aside>

                {/* Thread */}
                <section
                    className={[
                        'min-w-0 flex-1 flex-col bg-[#eef3f0]',
                        showThread ? 'flex' : 'hidden md:flex',
                    ].join(' ')}
                >
                    {!selectedId ? (
                        <div className="flex flex-1 items-center justify-center p-6 text-sm font-medium text-slate-500">
                            Sélectionnez une conversation
                        </div>
                    ) : loadingThread && !conversation ? (
                        <div className="flex flex-1 items-center justify-center text-sm text-slate-500">
                            Chargement…
                        </div>
                    ) : (
                        <>
                            <header className="flex items-center gap-3 border-b border-slate-200 bg-white px-3 py-2.5 sm:px-4">
                                <button
                                    type="button"
                                    className="inline-flex h-9 w-9 items-center justify-center rounded-lg text-slate-600 hover:bg-slate-100 md:hidden"
                                    onClick={() => {
                                        setSelectedId(null);
                                        setConversation(null);
                                        setSearchParams({}, { replace: true });
                                    }}
                                >
                                    <ArrowLeft className="h-5 w-5" />
                                </button>
                                <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 text-sm font-bold text-emerald-800">
                                    {conversation?.initials}
                                </div>
                                <div className="min-w-0 flex-1">
                                    <p className="truncate text-sm font-bold text-slate-900">
                                        {conversation?.contact_name}
                                    </p>
                                    <p className="truncate text-xs font-medium text-slate-500">
                                        {conversation?.contact_phone}
                                        {conversation?.account
                                            ? ` · via ${conversation.account.name}`
                                            : ''}
                                    </p>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    {callHref ? (
                                        <a
                                            href={callHref}
                                            className="inline-flex h-9 items-center gap-1.5 rounded-lg bg-blue-600 px-3 text-xs font-bold text-white"
                                        >
                                            <Phone className="h-3.5 w-3.5" />
                                            <span className="hidden sm:inline">Appeler</span>
                                        </a>
                                    ) : null}
                                    <button
                                        type="button"
                                        onClick={handleAssign}
                                        className="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-slate-200 bg-white text-slate-600"
                                        title="Assigner"
                                    >
                                        <UserPlus className="h-4 w-4" />
                                    </button>
                                    {conversation?.status === 'resolved' ? (
                                        <button
                                            type="button"
                                            onClick={handleReopen}
                                            className="hidden h-9 items-center rounded-lg border border-slate-200 bg-white px-3 text-xs font-bold text-slate-700 sm:inline-flex"
                                        >
                                            Rouvrir
                                        </button>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={handleResolve}
                                            className="hidden h-9 items-center rounded-lg border border-emerald-200 bg-emerald-50 px-3 text-xs font-bold text-emerald-800 sm:inline-flex"
                                        >
                                            Traité
                                        </button>
                                    )}
                                </div>
                            </header>

                            <div className="flex min-h-0 flex-1">
                                <div className="flex min-w-0 flex-1 flex-col">
                                    <div className="min-h-0 flex-1 space-y-2 overflow-y-auto px-3 py-4 sm:px-5">
                                        {messages.map((m) => {
                                            const mine = m.direction === 'outbound';
                                            return (
                                                <div
                                                    key={m.id}
                                                    className={`flex ${mine ? 'justify-end' : 'justify-start'}`}
                                                >
                                                    <div
                                                        className={[
                                                            'max-w-[85%] rounded-2xl px-3 py-2 text-sm shadow-sm sm:max-w-[70%]',
                                                            mine
                                                                ? 'rounded-br-md bg-[#d9fdd3] text-slate-900'
                                                                : 'rounded-bl-md bg-white text-slate-900',
                                                        ].join(' ')}
                                                    >
                                                        {m.type === 'image' && m.has_media ? (
                                                            <img
                                                                src={`/api/whatsapp/messages/${m.id}/media`}
                                                                alt=""
                                                                className="mb-1 max-h-56 rounded-lg"
                                                            />
                                                        ) : null}
                                                        {m.type === 'audio' && m.has_media ? (
                                                            <audio
                                                                controls
                                                                className="mb-1 max-w-full"
                                                                src={`/api/whatsapp/messages/${m.id}/media`}
                                                            />
                                                        ) : null}
                                                        {m.type === 'document' && m.has_media ? (
                                                            <a
                                                                href={`/api/whatsapp/messages/${m.id}/media`}
                                                                className="mb-1 inline-flex items-center gap-1 font-semibold text-emerald-800 underline"
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                <FileText className="h-4 w-4" />
                                                                {m.media_filename || 'Document'}
                                                            </a>
                                                        ) : null}
                                                        {m.type === 'location' ? (
                                                            <p className="font-medium">
                                                                📍 {m.location_name || `${m.latitude}, ${m.longitude}`}
                                                            </p>
                                                        ) : null}
                                                        {m.body ? (
                                                            <p className="whitespace-pre-wrap break-words font-medium leading-relaxed">
                                                                {m.body}
                                                            </p>
                                                        ) : null}
                                                        <div
                                                            className={`mt-1 flex items-center gap-1.5 text-[10px] font-medium ${mine ? 'justify-end text-slate-500' : 'text-slate-400'}`}
                                                        >
                                                            {mine && m.sent_by ? (
                                                                <span>{m.sent_by.name}</span>
                                                            ) : null}
                                                            <span>
                                                                {formatMessageTime(m.meta_timestamp || m.created_at)}
                                                            </span>
                                                            {mine ? <StatusTicks status={m.status} /> : null}
                                                        </div>
                                                        {m.status === 'failed' && m.error_message ? (
                                                            <p className="mt-1 text-[10px] font-medium text-rose-600">
                                                                {m.error_message}
                                                            </p>
                                                        ) : null}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                        <div ref={messagesEndRef} />
                                    </div>

                                    {picker === 'quick' ? (
                                        <div className="border-t border-slate-200 bg-white p-3">
                                            <p className="mb-2 text-xs font-bold text-slate-500">Réponses rapides</p>
                                            <div className="max-h-40 space-y-1 overflow-y-auto">
                                                {quickReplies.map((r) => (
                                                    <button
                                                        key={r.id}
                                                        type="button"
                                                        onClick={() => sendText(r.body)}
                                                        className="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50"
                                                    >
                                                        <span className="font-bold text-slate-800">{r.title}</span>
                                                        <span className="mt-0.5 block truncate text-xs text-slate-500">
                                                            {r.body}
                                                        </span>
                                                    </button>
                                                ))}
                                            </div>
                                            <button
                                                type="button"
                                                className="mt-2 text-xs font-bold text-slate-500"
                                                onClick={() => setPicker(null)}
                                            >
                                                Fermer
                                            </button>
                                        </div>
                                    ) : null}

                                    {picker === 'templates' ? (
                                        <div className="border-t border-slate-200 bg-white p-3">
                                            {!selectedTemplate ? (
                                                <>
                                                    <p className="mb-2 text-xs font-bold text-slate-500">
                                                        Templates approuvés Meta
                                                    </p>
                                                    <div className="max-h-48 space-y-1 overflow-y-auto">
                                                        {templates.length === 0 ? (
                                                            <p className="text-sm text-slate-500">
                                                                Aucun template APPROVED. Synchronisez dans Templates.
                                                            </p>
                                                        ) : (
                                                            templates.map((t) => (
                                                                <button
                                                                    key={t.id}
                                                                    type="button"
                                                                    onClick={() => {
                                                                        setSelectedTemplate(t);
                                                                        setTemplateVars(
                                                                            Array.from(
                                                                                { length: t.variables_count || 0 },
                                                                                () => ''
                                                                            )
                                                                        );
                                                                    }}
                                                                    className="block w-full rounded-lg px-3 py-2 text-left text-sm hover:bg-slate-50"
                                                                >
                                                                    <span className="font-bold text-slate-800">
                                                                        {t.name}
                                                                    </span>
                                                                    <span className="mt-0.5 block text-xs text-slate-500">
                                                                        {t.language} · {t.body_text}
                                                                    </span>
                                                                </button>
                                                            ))
                                                        )}
                                                    </div>
                                                </>
                                            ) : (
                                                <div className="space-y-2">
                                                    <p className="text-xs font-bold text-slate-500">
                                                        {selectedTemplate.name} — renseigner les variables
                                                    </p>
                                                    {templateVars.map((v, i) => (
                                                        <input
                                                            key={i}
                                                            className="h-10 w-full rounded-lg border border-slate-200 px-3 text-sm"
                                                            placeholder={`{{${i + 1}}}`}
                                                            value={v}
                                                            onChange={(e) => {
                                                                const next = [...templateVars];
                                                                next[i] = e.target.value;
                                                                setTemplateVars(next);
                                                            }}
                                                        />
                                                    ))}
                                                    <p className="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">
                                                        Aperçu :{' '}
                                                        {templateVars.reduce(
                                                            (text, val, i) =>
                                                                text.replaceAll(`{{${i + 1}}}`, val || `{{${i + 1}}}`),
                                                            selectedTemplate.body_text || ''
                                                        )}
                                                    </p>
                                                    <div className="flex gap-2">
                                                        <button
                                                            type="button"
                                                            disabled={sending}
                                                            onClick={sendTemplate}
                                                            className="h-9 rounded-lg bg-emerald-600 px-3 text-xs font-bold text-white"
                                                        >
                                                            Envoyer
                                                        </button>
                                                        <button
                                                            type="button"
                                                            onClick={() => setSelectedTemplate(null)}
                                                            className="h-9 rounded-lg border px-3 text-xs font-bold text-slate-600"
                                                        >
                                                            Retour
                                                        </button>
                                                    </div>
                                                </div>
                                            )}
                                            <button
                                                type="button"
                                                className="mt-2 text-xs font-bold text-slate-500"
                                                onClick={() => setPicker(null)}
                                            >
                                                Fermer
                                            </button>
                                        </div>
                                    ) : null}

                                    <div className="relative border-t border-slate-200 bg-white p-2 sm:p-3">
                                        {attachOpen ? (
                                            <div className="absolute bottom-full left-2 mb-2 grid w-56 grid-cols-3 gap-2 rounded-2xl border border-slate-200 bg-white p-3 shadow-xl">
                                                {[
                                                    { t: 'image', label: 'Photo' },
                                                    { t: 'image', label: 'Caméra', capture: true },
                                                    { t: 'video', label: 'Vidéo' },
                                                    { t: 'document', label: 'Document' },
                                                    { t: 'template', label: 'Template' },
                                                    { t: 'quick', label: 'Rapide' },
                                                    { t: 'audio', label: 'Audio' },
                                                ].map((item) => (
                                                    <button
                                                        key={item.label}
                                                        type="button"
                                                        onClick={() => {
                                                            if (item.t === 'template') openTemplates();
                                                            else if (item.t === 'quick') openQuickReplies();
                                                            else pickMedia(item.t);
                                                        }}
                                                        className="flex flex-col items-center gap-1 rounded-xl bg-slate-50 px-2 py-3 text-[11px] font-bold text-slate-700 hover:bg-emerald-50"
                                                    >
                                                        {item.label}
                                                    </button>
                                                ))}
                                            </div>
                                        ) : null}
                                        <form
                                            className="flex items-end gap-2"
                                            onSubmit={(e) => {
                                                e.preventDefault();
                                                sendText();
                                            }}
                                        >
                                            <button
                                                type="button"
                                                onClick={() => setAttachOpen((v) => !v)}
                                                className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600"
                                            >
                                                <Paperclip className="h-4 w-4" />
                                            </button>
                                            <button
                                                type="button"
                                                onClick={openQuickReplies}
                                                className="hidden h-11 w-11 shrink-0 items-center justify-center rounded-xl border border-slate-200 text-slate-600 sm:inline-flex"
                                                title="Réponses rapides"
                                            >
                                                <Zap className="h-4 w-4" />
                                            </button>
                                            <textarea
                                                rows={1}
                                                value={body}
                                                onChange={(e) => setBody(e.target.value)}
                                                placeholder="Écrire un message…"
                                                className="max-h-28 min-h-[44px] flex-1 resize-none rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 text-sm outline-none focus:border-emerald-300 focus:bg-white focus:ring-4 focus:ring-emerald-500/10"
                                                onKeyDown={(e) => {
                                                    if (e.key === 'Enter' && !e.shiftKey) {
                                                        e.preventDefault();
                                                        sendText();
                                                    }
                                                }}
                                            />
                                            <button
                                                type="submit"
                                                disabled={sending || !body.trim()}
                                                className="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-emerald-600 text-white shadow-sm disabled:opacity-50"
                                            >
                                                <Send className="h-4 w-4" />
                                            </button>
                                        </form>
                                        <input
                                            ref={fileInputRef}
                                            type="file"
                                            className="hidden"
                                            accept={
                                                mediaType === 'image'
                                                    ? 'image/*'
                                                    : mediaType === 'video'
                                                      ? 'video/*'
                                                      : mediaType === 'audio'
                                                        ? 'audio/*'
                                                        : '*/*'
                                            }
                                            onChange={onFileChange}
                                        />
                                    </div>
                                </div>

                                {/* Right panel — orders */}
                                <aside className="hidden w-[280px] shrink-0 flex-col border-l border-slate-200 bg-white lg:flex">
                                    <div className="border-b border-slate-100 px-4 py-3">
                                        <p className="text-xs font-bold uppercase tracking-wide text-slate-400">
                                            Client & commandes
                                        </p>
                                    </div>
                                    <div className="min-h-0 flex-1 space-y-3 overflow-y-auto p-4">
                                        <div className="rounded-xl bg-slate-50 px-3 py-3 text-sm">
                                            <p className="font-bold text-slate-900">
                                                {conversation?.contact_name}
                                            </p>
                                            <p className="mt-1 font-medium text-slate-600">
                                                {conversation?.contact_phone}
                                            </p>
                                            {conversation?.assigned_to ? (
                                                <p className="mt-2 text-xs font-medium text-slate-500">
                                                    Assigné : {conversation.assigned_to.name}
                                                </p>
                                            ) : null}
                                            <p className="mt-1 text-xs font-medium text-slate-500">
                                                Statut : {statusLabel(conversation?.status)}
                                            </p>
                                        </div>
                                        {(conversation?.orders || []).length === 0 ? (
                                            <p className="text-sm font-medium text-slate-500">
                                                Aucune commande liée automatiquement.
                                            </p>
                                        ) : (
                                            conversation.orders.map((order) => (
                                                <div
                                                    key={order.id}
                                                    className="rounded-xl border border-slate-200 px-3 py-3 text-sm"
                                                >
                                                    <p className="font-bold text-slate-900">
                                                        {order.name || order.order_number}
                                                    </p>
                                                    <p className="mt-1 text-xs font-medium text-slate-500">
                                                        {order.confirmation_label || order.status} ·{' '}
                                                        {formatMoney(order.total_price, order.currency)}
                                                    </p>
                                                    {order.city ? (
                                                        <p className="mt-1 text-xs text-slate-500">{order.city}</p>
                                                    ) : null}
                                                    <ul className="mt-2 space-y-0.5 text-xs text-slate-600">
                                                        {(order.line_items || []).slice(0, 4).map((item, idx) => (
                                                            <li key={idx}>
                                                                {item.quantity}× {item.title}
                                                            </li>
                                                        ))}
                                                    </ul>
                                                    <Link
                                                        to="/confirmation"
                                                        className="mt-2 inline-block text-xs font-bold text-emerald-700"
                                                    >
                                                        Ouvrir la commande
                                                    </Link>
                                                </div>
                                            ))
                                        )}
                                    </div>
                                </aside>
                            </div>
                        </>
                    )}
                </section>
            </div>
        </div>
    );
}
