/**
 * Product identity (T1). "Lav'Fast" is the main name, "Flow" its second line.
 * On a single line it is always written "Lav'Fast Flow" (no other spelling).
 */
export const BRAND = {
    name: "Lav'Fast",
    suffix: 'Flow',
    title: "Lav'Fast Flow",
    domain: 'lavfast-flow.com',
    version: '1.0.0',
};

export function pageTitle(section) {
    return section ? `${section} · ${BRAND.title}` : BRAND.title;
}
