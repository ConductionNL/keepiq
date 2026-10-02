# Design: the rest of the browser extension

## Step 1: generator and send

**D1. One generator, more options.** `src/generator/generator.js` gains class toggles, minimum digits and symbols and ambiguity avoidance; defaults keep the server's behaviour, so the web app is unchanged. Usernames are a new pure module, `src/generator/username.js`.

**D2. State in the worker, values in the popup.** Options (per account, `storage.local`), the cached policy and the account's email live in the worker (`background/generator-handlers.js`). Values are generated in the popup. The history lives in `storage.session`, so the browser drops it on restart; the worker clears it whenever an account locks (`vault.onLock`) and forgets everything when an account is removed. History writes are queued per account: values generated in quick succession otherwise read the same list and dropped each other, which the popup test caught.

**D3. Argon2id bundled, not fetched.** esbuild's binary loader puts argon2-browser's WebAssembly into the worker bundle; `loadArgon2WasmBinary` hands it to the web app's KDF. The extension CSP gains `'wasm-unsafe-eval'`, which MV3 requires for WebAssembly. The emscripten glue's Node-only modules (`fs`, `path`, `crypto`) are external; they never run in a browser.

**D4. The load check opens the real popup.** Since #921 the worker answers its own pages only, never a tab, so the load check (which opened `popup.html` as a tab) failed on development. It now opens the action popup and reaches it over the DevTools protocol.
