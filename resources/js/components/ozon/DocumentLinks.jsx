import { ExternalLink, FileText, Printer } from 'lucide-react';

/** BL PDF / Étiquettes A4 / Étiquettes 10×10 links of a saved Ozon delivery note (URLs built from the BL ref). */
export default function DocumentLinks({ documents, compact = false }) {
    if (!documents?.bl_pdf) return null;
    const links = [
        ['bl_pdf', 'BL PDF', FileText],
        ['labels_a4', 'Étiquettes A4', Printer],
        ['labels_10x10', 'Étiquettes 10×10', Printer],
    ];
    return (
        <div className={compact ? "flex flex-wrap gap-2" : "flex flex-wrap gap-3"}>
            {links.map(([k, label, Icon]) => (
                <a key={k} href={documents[k]} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-1 text-xs font-semibold text-teal-700 hover:text-teal-800">
                    <Icon className="h-3.5 w-3.5" /> {label} <ExternalLink className="h-3 w-3" />
                </a>
            ))}
        </div>
    );
}
