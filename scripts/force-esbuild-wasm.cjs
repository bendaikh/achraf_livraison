const fs = require('fs');
const path = require('path');
const Module = require('module');

const root = path.join(__dirname, '..');
const wasmMain = path.join(root, 'node_modules/esbuild-wasm/lib/main.js');
const wasmPath = path.join(root, 'node_modules/esbuild-wasm/esbuild.wasm');
const esbuildWasm = require(path.join(root, 'node_modules/esbuild-wasm'));

const ready = esbuildWasm.initialize({
    worker: false,
    wasmModule: new WebAssembly.Module(fs.readFileSync(wasmPath)),
});

// Subsequent initialize() calls from Vite / plugins become no-ops.
esbuildWasm.initialize = () => ready;

const originalResolveFilename = Module._resolveFilename;
Module._resolveFilename = function (request, parent, isMain, options) {
    if (request === 'esbuild') {
        return wasmMain;
    }
    return originalResolveFilename.call(this, request, parent, isMain, options);
};

module.exports = { ready };
