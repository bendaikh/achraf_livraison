import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const wasmMain = pathToFileURL(
    path.join(__dirname, '../node_modules/esbuild-wasm/lib/main.js')
).href;

export async function resolve(specifier, context, nextResolve) {
    if (specifier === 'esbuild') {
        return {
            shortCircuit: true,
            url: wasmMain,
        };
    }
    return nextResolve(specifier, context);
}
