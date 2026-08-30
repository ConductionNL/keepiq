# Frontend unit tests (Vitest)

Pure-logic frontend unit tests. The first target is the security-critical
browser crypto in `src/crypto/rsa.js` (RSA-OAEP-SHA256 + X.509 SPKI
extraction), which had no JS-unit coverage.

## Running

```bash
npm run test          # vitest run (one-shot)
npm run test:unit     # alias
npm run test:unit:watch
```

Or directly: `npx vitest run`.

## Config

`vitest.config.js` runs in the **`node`** environment, which provides
WebCrypto (`globalThis.crypto.subtle`) plus `btoa` / `atob` — everything
`src/crypto/rsa.js` needs, with no DOM. Tests live under `tests/vitest/**`
as `*.spec.js`; the PHP `tests/Unit` / `tests/unit` dirs are excluded.

To add **component** (`.vue`) tests later, give the relevant spec a
`// @vitest-environment jsdom` header and add `@vitejs/plugin-vue2`. See
`openbuild/vitest.config.js` for the full Vue 2.7 component harness pattern
(css-noop plugin, `@conduction/nextcloud-vue` stub, inline deps).

## Pattern (reusable across apps)

1. `npm install -D vitest@^1.6.1 --legacy-peer-deps` (matches the openbuild
   pin; `--legacy-peer-deps` only because of a pre-existing eslint peer
   conflict, unrelated to vitest).
2. Add `vitest.config.js` (node env for pure logic; jsdom + plugin-vue2 for
   components).
3. Add `test` / `test:unit` / `test:unit:watch` scripts to `package.json`.
4. Put deterministic fixtures under `tests/vitest/fixtures/`. Crypto fixtures
   (cert / SPKI / PKCS#8) are generated once with openssl and embedded as PEM
   constants so the suite has no toolchain dependency at runtime.

## What the crypto suite locks

- `importPublicKey()` accepts a full X.509 **CERTIFICATE** PEM and extracts
  the embedded SubjectPublicKeyInfo before `importKey('spki', …)` — the
  Phase-0 vault-crypto fix. A regression guard shows that handing the raw
  cert DER straight to `importKey('spki')` throws, proving the extraction is
  load-bearing.
- The SPKI pulled from the certificate is byte-identical to the standalone
  SPKI public key (Node `crypto` oracle).
- RSA-OAEP-SHA256 encrypt/decrypt round-trip (RSA-4096) recovers the exact
  plaintext, including the empty string, a multi-chunk (>446 byte) payload
  with a verified big-endian chunk count, and multi-byte UTF-8.
- Failure modes: non-SEQUENCE / truncated certificate DER throws; malformed
  PKCS#8 is rejected; the private key imports non-extractable with `decrypt`
  usage only.
