#!/bin/bash
ROOT="/home/u869432432/domains/lavfast-flow.com/public_html"
NODE="/home/u869432432/.cursor-server/bin/linux-x64/2fdd31c9f33f7fbe501f2d57772dc5bf64b63620/node"
exec "$NODE" "$ROOT/node_modules/esbuild-wasm/bin/esbuild" "$@"
