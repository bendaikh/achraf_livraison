import { useEffect, useState } from 'react';
import { Printer } from 'lucide-react';
import useDeliveryModes from '../../hooks/useDeliveryModes';

/** « Imprimer » — étiquettes of the selection, plus documents declared by each carrier. */
export default function PrintMenu({ onLabels, onDocument, onClose }) {
    const data = useDeliveryModes();
    const [format, setFormat] = useState({});

    useEffect(() => {
        const onKey = (e) => e.key === 'Escape' && onClose?.();
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [onClose]);

    const docs = (data.modes || []).filter((m) => m.type === 'carrier' && m.available && m.documents?.length);

    return (
        <div className="w-64 rounded-xl border border-slate-200 bg-white p-1.5 shadow-lg">
            <button type="button" onClick={onLabels} className="flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-left text-sm font-semibold text-slate-800 hover:bg-slate-50">
                <Printer className="h-3.5 w-3.5 text-slate-500" /> Étiquettes
            </button>
            {docs.map((mode) =>
                mode.documents.map((doc) => (
                    <div key={`${mode.key}-${doc.key}`} className="flex items-center gap-1 px-1">
                        <button
                            type="button"
                            onClick={() => onDocument(mode, doc, doc.formats ? format[mode.key] || mode.default_waybill_format || Object.keys(doc.formats)[0] : null)}
                            className="flex min-w-0 flex-1 items-center gap-2 rounded-lg px-1 py-1.5 text-left text-xs font-semibold text-slate-700 hover:bg-slate-50"
                        >
                            {mode.label} · {doc.label}
                        </button>
                        {doc.formats ? (
                            <select
                                aria-label={`Format étiquette ${mode.label}`}
                                value={format[mode.key] || mode.default_waybill_format || Object.keys(doc.formats)[0]}
                                onChange={(e) => setFormat((f) => ({ ...f, [mode.key]: e.target.value }))}
                                className="h-7 max-w-[9rem] rounded-md border border-slate-200 bg-white px-1 text-[10px]"
                            >
                                {Object.entries(doc.formats).map(([k, v]) => (
                                    <option key={k} value={k}>
                                        {v}
                                    </option>
                                ))}
                            </select>
                        ) : null}
                    </div>
                )),
            )}
        </div>
    );
}
