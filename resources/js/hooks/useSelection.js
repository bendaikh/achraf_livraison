import { useCallback, useMemo, useState } from 'react';

/**
 * Multi-selection state (ids), kept across pages/filters until cleared.
 * Ready to feed future bulk actions: `selectedIds` is the payload.
 */
export default function useSelection() {
    const [selected, setSelected] = useState(() => new Set());

    const toggle = useCallback((id) => {
        setSelected((prev) => {
            const next = new Set(prev);
            if (next.has(id)) next.delete(id);
            else next.add(id);
            return next;
        });
    }, []);

    const setMany = useCallback((ids, checked) => {
        setSelected((prev) => {
            const next = new Set(prev);
            ids.forEach((id) => (checked ? next.add(id) : next.delete(id)));
            return next;
        });
    }, []);

    const clear = useCallback(() => setSelected(new Set()), []);

    const pageState = useCallback(
        (ids) => {
            const count = ids.filter((id) => selected.has(id)).length;
            return { all: ids.length > 0 && count === ids.length, some: count > 0 && count < ids.length };
        },
        [selected],
    );

    return useMemo(
        () => ({ selected, selectedIds: [...selected], count: selected.size, isSelected: (id) => selected.has(id), toggle, setMany, clear, pageState }),
        [selected, toggle, setMany, clear, pageState],
    );
}
