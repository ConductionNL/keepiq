# Design: generator, vault and send in the browser extension

## Decisions

**D1. The worker keeps the keys; the popup gets index fields.** `vault-list` returns names, addresses, types and folders, never a blob. `vault-item` decrypts one item when the user opens it, and the popup drops those values when the item closes. Saving encrypts in the worker. Tests record every request body and fail when a plaintext value appears.

**D2. Reuse the web app's modules.** The generator (`src/generator/generator.js`) and the send crypto (`src/send/sendCrypto.js`) are imported, as `src/totp` and `src/crypto` already are. One implementation, one test suite.

**D3. The page may ask for a password, nothing more.** `generate-for-field` is added to the content-script allow-list. It reads no vault data and returns a random value; the offer that triggers it sits in a closed shadow root and acts only on trusted clicks, like the save prompt.

**D4. Sends without a password only, for now.** The fragment-key mode needs only Web Crypto. Password sends need the web app's Argon2 WebAssembly build, which the extension bundler does not load yet. The form says so and points to the web app.

**D5. Trash, not delete.** Delete in the popup moves an item to the trash after the browser's own confirmation, so it can be restored in the web app.
