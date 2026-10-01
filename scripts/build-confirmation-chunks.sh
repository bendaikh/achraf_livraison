#!/usr/bin/env bash
# Rebuild Confirmation + Commandes assets when Vite cannot run on the host.
# Usage: bash scripts/build-confirmation-chunks.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"
ESBUILD="$ROOT/node_modules/@esbuild/linux-x64/bin/esbuild"
OUT_DIR="$ROOT/public/build/assets"
SHIM_DIR="$OUT_DIR/_shims"
mkdir -p "$SHIM_DIR"

cat > "$SHIM_DIR/react.js" << 'EOF'
import { a as React } from '../app-lPH5M_A2.js';
export default React;
export const {
  useState, useEffect, useCallback, useMemo, useRef, Fragment,
  createElement, createContext, useContext, forwardRef, lazy, Suspense,
  StrictMode, Children, cloneElement, isValidElement, memo, useReducer,
} = React;
EOF

cat > "$SHIM_DIR/jsx-runtime.js" << 'EOF'
import { j as jsxRuntime, a as React } from '../app-lPH5M_A2.js';
export const jsx = (...args) => (jsxRuntime.jsx ? jsxRuntime.jsx(...args) : jsxRuntime(...args));
export const jsxs = (...args) => (jsxRuntime.jsxs ? jsxRuntime.jsxs(...args) : jsxRuntime(...args));
export const Fragment = React.Fragment;
export default { jsx, jsxs, Fragment };
EOF

cat > "$SHIM_DIR/lucide-react.js" << 'EOF'
import { c as createLucideIcon, X as XIcon, M as MessageCircleIcon, d as SearchIcon } from '../app-lPH5M_A2.js';
import { a as CheckCircle2, C as XCircle } from '../circle-x-DgurjaS3.js';
import { R as RefreshCw } from '../refresh-cw-CxE6OgFd.js';
import { P as PackageSearch } from '../package-search-DXuQNeeb.js';

const Phone = createLucideIcon('phone', [
  ['path', { d: 'M13.832 16.568a1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 6.392 6.384', key: '9njp5v' }],
]);
const PhoneOff = createLucideIcon('phone-off', [
  ['path', { d: 'M10.1 13.9a14 14 0 0 0 3.732 2.668 1 1 0 0 0 1.213-.303l.355-.465A2 2 0 0 1 17 15h3a2 2 0 0 1 2 2v3a2 2 0 0 1-2 2 18 18 0 0 1-12.728-5.272', key: '1wngk7' }],
  ['path', { d: 'M22 2 2 22', key: 'y4kqgn' }],
  ['path', { d: 'M4.76 13.582A18 18 0 0 1 2 4a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v3a2 2 0 0 1-.8 1.6l-.468.351a1 1 0 0 0-.292 1.233 14 14 0 0 0 .244.473', key: '10hv5p' }],
]);
const Clock3 = createLucideIcon('clock-3', [
  ['circle', { cx: '12', cy: '12', r: '10', key: '1mglay' }],
  ['path', { d: 'M12 6v6h4', key: '135r8i' }],
]);

export { CheckCircle2, XCircle, RefreshCw, PackageSearch, Phone, PhoneOff, Clock3, MessageCircleIcon as MessageCircle, XIcon as X, SearchIcon as Search };
EOF

cat > "$SHIM_DIR/react-router-dom.js" << 'EOF'
import { L as Link, e as useLocation, h as useNavigate, N as Navigate, R as Routes, b as Route } from '../app-lPH5M_A2.js';
export { Link, useLocation, useNavigate, Navigate, Routes, Route };
EOF

build_page() {
  local entry="$1"
  local outfile="$2"
  shift 2
  "$ESBUILD" "$entry" \
    --bundle --format=esm --jsx=automatic --platform=browser --target=es2020 \
    --outfile="$outfile" \
    --alias:react="$SHIM_DIR/react.js" \
    --alias:react/jsx-runtime="$SHIM_DIR/jsx-runtime.js" \
    --alias:react/jsx-dev-runtime="$SHIM_DIR/jsx-runtime.js" \
    --alias:lucide-react="$SHIM_DIR/lucide-react.js" \
    "$@" \
    --external:../app-lPH5M_A2.js \
    --external:../circle-x-DgurjaS3.js \
    --external:../refresh-cw-CxE6OgFd.js \
    --external:../package-search-DXuQNeeb.js

  sed -i \
    's|from "../app-lPH5M_A2.js"|from "./app-lPH5M_A2.js"|g; s|from "../circle-x-DgurjaS3.js"|from "./circle-x-DgurjaS3.js"|g; s|from "../refresh-cw-CxE6OgFd.js"|from "./refresh-cw-CxE6OgFd.js"|g; s|from "../package-search-DXuQNeeb.js"|from "./package-search-DXuQNeeb.js"|g' \
    "$outfile"
}

build_page resources/js/pages/Confirmation.jsx "$OUT_DIR/Confirmation-CWuRoLUR.js"
build_page resources/js/pages/Commandes.jsx "$OUT_DIR/Commandes-DJFx2Xda.js" \
  --alias:react-router-dom="$SHIM_DIR/react-router-dom.js"

rm -rf "$SHIM_DIR"

# Hoist ESM imports to the top (required after bundling shims from a subfolder).
python3 - "$OUT_DIR" <<'PY'
import re, pathlib, sys
root = pathlib.Path(sys.argv[1])
for name in ['Confirmation-CWuRoLUR.js', 'Commandes-DJFx2Xda.js']:
    p = root / name
    src = p.read_text()
    imports = re.findall(r'^import\s.+?;$', src, flags=re.M)
    body = re.sub(r'^import\s.+?;\s*\n?', '', src, flags=re.M)
    by, other = {}, []
    for line in imports:
        m = re.match(r'^import\s+\{([^}]+)\}\s+from\s+("([^"]+)")\s*;$', line)
        if not m:
            other.append(line)
            continue
        specs = [s.strip() for s in m.group(1).split(',') if s.strip()]
        by.setdefault(m.group(2), set()).update(specs)
    merged = other + [f"import {{ {', '.join(sorted(specs))} }} from {mod};" for mod, specs in by.items()]
    p.write_text('\n'.join(merged) + '\n' + body.lstrip('\n'))
print('imports hoisted')
PY

echo "OK: Confirmation + Commandes chunks rebuilt."
