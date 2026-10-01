import { register } from 'node:module';
import { pathToFileURL } from 'node:url';
import { createRequire } from 'node:module';

const require = createRequire(import.meta.url);
// Initialize wasm + CJS resolve hook first
require('./force-esbuild-wasm.cjs');

register('./esbuild-wasm-resolve-hook.mjs', pathToFileURL('./'));
