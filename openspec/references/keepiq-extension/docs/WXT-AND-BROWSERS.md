# Working with WXT & Cross-Browser Rules

Rules for a browser extension built with [WXT](https://wxt.dev) that ships to
**both Chrome and Firefox from one codebase**. The two browsers differ in ways
that don't always surface at typecheck time — read this before touching anything
under `entrypoints/` or `src/`.

## Quick reference — do / don't

| Do | Don't |
|----|-------|
| Use the `browser.*` global for every extension API | Use `chrome.*` — it's callback-based on Firefox, so `await` yields `undefined` |
| Let WXT pick the manifest version per browser (Chrome MV3, Firefox MV2) | Set a global `manifestVersion` in `wxt.config.ts` — it breaks Firefox dev |
| Write manifest-version-agnostic code; shim APIs that differ (e.g. `action`) | Assume an MV3-only API exists — Firefox builds as MV2 |
| Type message payloads as `unknown` and assert the shape at the boundary | Type a listener param as the concrete envelope — the polyfill types it `unknown` |
| Build **both** targets before committing a browser-facing change | Trust `npm run typecheck` alone — it doesn't build or catch MV drift |

## 1. Always use `browser.*`, never `chrome.*`

WXT auto-imports a `browser` global backed by
[`webextension-polyfill`](https://github.com/mozilla/webextension-polyfill) (the
default `extensionApi`). It is **promise-based on every browser**.

The native `chrome.*` namespace on Firefox is **callback-based** and returns
`undefined` from calls like `chrome.storage.local.get(...)`. So `await chrome.…`
resolves to `undefined` and the next line throws. The polyfill does **not** patch
the `chrome` global — calling `chrome.*` bypasses it entirely.

```ts
// GOOD — promise-based on Chrome and Firefox
const data = await browser.storage.local.get(key)

// BAD — undefined on Firefox, crashes on the next line
const data = await chrome.storage.local.get(key)
```

**Imports:**
- The `browser` **value** is auto-imported — like `defineBackground` /
  `defineContentScript`, you don't (and shouldn't) write an import for it.
- **Types** are *not* auto-imported. WXT ≥ 0.20 exposes them as one `Browser`
  namespace from `wxt/browser`:
  ```ts
  import type { Browser } from 'wxt/browser'
  // then: Browser.runtime.Port, Browser.runtime.MessageSender, Browser.tabs.Tab, …
  ```
  Do **not** use `chrome.runtime.Port` / `chrome.tabs.Tab` as type annotations.
  (On WXT 0.19 and earlier these were separate top-level exports —
  `import type { Runtime, Tabs } from 'wxt/browser'`. Upgrading a 0.19 project is
  mostly this rename plus `defineRunnerConfig` → `defineWebExtConfig`.)

## 2. Manifest versions: Chrome = MV3, Firefox = MV2

WXT defaults to **MV3 for Chrome** (MV2 is sunset there) and **MV2 for Firefox**.
Leave it that way — **do not** add `manifestVersion: 3` to `wxt.config.ts`.

Forcing Firefox to MV3 breaks the dev loop: **Firefox MV3 dev mode is unsupported
upstream** (Mozilla bug [1864284](https://bugzilla.mozilla.org/show_bug.cgi?id=1864284));
`wxt -b firefox` refuses to run in MV3 with
*"Dev mode does not support Firefox MV3"*. Keeping Firefox on MV2 means **dev and
production use the same manifest version per browser — you test what you ship.**

Because of the split, **write manifest-version-agnostic code.** Before using an
API, confirm it exists on *both* MV3 (Chrome) and MV2 (Firefox), and shim the ones
that differ:

- **Toolbar action** — `browser.action` on MV3, `browser.browserAction` on MV2.
  Resolved once in `src/browser-action.ts`
  (`BrowserAction.Static extends Action.Static`, so `setIcon` etc. are identical).
- **`storage.session`** — available Firefox 115+ / Chrome 102+, independent of
  manifest version, but absent below Firefox 115 (the template's
  `strict_min_version` is 109). Guard it: `if (!browser.storage.session) return`.
- Any MV3-only API (`declarativeNetRequest`, service-worker specifics, etc.) needs
  a guard or an MV2 fallback before it can be used.

If Firefox MV3 dev support ever lands upstream, revisit — the fix is a one-line
config change plus dropping the shims.

## 3. Message passing

The polyfill types `onMessage` / port payloads as **`unknown`** (stricter than
`@types/chrome`'s `any`). Accept `unknown` and assert the typed envelope inside —
the payload genuinely is untrusted/untyped at the runtime boundary:

```ts
browser.runtime.onMessage.addListener((msg: unknown, sender: Runtime.MessageSender) => {
  const m = msg as ContentToBackground
  // …
})
```

- **Fire-and-forget listeners must return `undefined`.** Returning a `Promise` or
  `true` tells the browser to hold the message channel open for a `sendResponse`
  we never call. Put the async work in a floating IIFE (`void (async () => {…})()`).
- **Request/response listeners return the `Promise`** — that is what holds the
  channel open for the reply. `entrypoints/background.ts` does both in one
  listener; the split is the thing to copy.
- `browser.runtime.sendMessage(...)` returns a promise — guard it with `.catch()`.
- In content scripts, pre-check `browser.runtime?.id` before sending: the context
  can be invalidated (dev reload, extension reload) and `sendMessage` would throw
  synchronously instead of rejecting. `entrypoints/content.ts` shows the latch.

## 4. Build, typecheck, load

`npm run typecheck` runs `tsc --noEmit` — it does **not** produce a loadable build, and it will **not** catch manifest-version / runtime-API drift between Chrome and Firefox. To actually load changes you must build (or run
dev) and reload the extension in the browser.

| Command | What it does |
|---------|--------------|
| `npm run dev` | Chrome dev + HMR → `.output/chrome-mv3/`, auto-launches the browser |
| `npm run dev:firefox` | Firefox dev (MV2) + HMR → `.output/firefox-mv2/`, auto-launches |
| `npm run build` | Chrome production build (MV3) |
| `npm run build:firefox` | Firefox production build (MV2) |
| `npm run zip` / `zip:firefox` | Store-submission zips |
| `npm run typecheck` | `tsc --noEmit` (no build) |
| `npm run lint` / `lint:fix` | ESLint |

`wxt prepare` (run automatically by `postinstall`) generates `.wxt/` — the
tsconfig that `tsconfig.json` extends and the auto-import globals that
`eslint.config.mjs` imports. **Typecheck and lint both fail on a fresh clone until
dependencies are installed**, because neither generated file exists yet.

**Loading an unpacked Chrome build:** `chrome://extensions` → enable *Developer
mode* → *Load unpacked* → pick `.output/chrome-mv3/`.

**Loading a Firefox build manually:** `about:debugging#/runtime/this-firefox`
→ *Load Temporary Add-on…* → pick `.output/firefox-mv2/manifest.json`. Re-run
`npm run build:firefox` and hit *Reload* to iterate.

**Non-standard browser installs:** WXT only searches a hard-coded list of standard
locations when `wxt dev` launches a browser. Copy `web-ext.config.example.ts` to
`web-ext.config.ts` (gitignored) and point it at your binary.

## 5. Before committing a browser-facing change

1. No `chrome.*` in executable code — `grep -rn "chrome\." src/ entrypoints/`
   should only match comments/prose.
2. `npm run typecheck` and `npm run lint` are clean.
3. **Build both targets** — `npm run build && npm run build:firefox`. MV
   differences surface at build/runtime, not always at typecheck.
