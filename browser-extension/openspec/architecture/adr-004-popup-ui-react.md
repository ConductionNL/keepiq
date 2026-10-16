# ADR-004: The popup UI is built with React

**Status**: accepted

**Date**: 2026-09-22

## Context

The popup has many repeated, stateful pieces: the item card in the vault list, filter chips, the account switcher rows, settings sections, form rows for additional fields. The WXT template ships a vanilla TypeScript popup, and the first spec drafts kept that with one module per view. That works for a handful of screens but makes shared components and their state hard to keep consistent as the chain grows.

## Decision

The popup (and any other extension page, such as the pop-out window or a future options page) is written in React with TypeScript, wired through WXT's `@wxt-dev/module-react`. Background and content scripts stay plain TypeScript; React never runs there.

Rules that follow:

- **The background still owns all state.** React components read state and trigger actions only through the typed runtime messages in `src/messages.ts`, via a small set of hooks under `entrypoints/popup/hooks/` (for example `useVaultState`, `useSettings`, `useMessage`). No component calls `browser.storage` or the API client directly.
- **Components live under `entrypoints/popup/components/`**, one file per component, `.tsx`. Screen-level compositions live under `entrypoints/popup/views/`. Both are React function components; no class components.
- **Local UI state stays in React** (`useState`, `useReducer`, context). No global state library is added until a change shows a need and records it here.
- **Decrypted values are React state only.** They are requested through `item.decrypt` for what is on screen and dropped on unmount. Nothing decrypted is written to storage from the popup.
- **Styling** stays plain CSS with the existing token approach in `entrypoints/popup/popup.css`, split per component when a file grows. No CSS-in-JS runtime.
- **Testing** of pure logic stays in `src/` with vitest. Component tests, when added, use React Testing Library; a change that adds them says so in its tasks.
- **Dependencies**: `react`, `react-dom`, `@types/react`, `@types/react-dom`, `@wxt-dev/module-react`, plus `eslint-plugin-react-hooks`. Added once by `ext-accounts-and-unlock`.

## Consequences

- One component for the item card, one for the filter chip, one for a masked field with reveal and copy, reused by list, detail, forms, send and settings.
- Bundle grows by roughly 45 KB gzipped for React and ReactDOM. Acceptable for a popup that loads from disk.
- The template's `entrypoints/popup/main.ts` becomes `main.tsx` mounting `<App />`; the on/off scaffold is removed as ext-accounts-and-unlock already planned.
- Builders must keep the boundary: React in extension pages only, messages as the only bridge to the background. A component importing from `src/api/` or `src/crypto/` is a review blocker.
