# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What Keepiq is

Browser extension for Keepiq, the encrypted secrets manager for Nextcloud. Fill in logins, passkeys and one-time codes on any site, and save new ones as you go. Everything is encrypted — your master password and your secrets never reach the server.

## Stack & commands

Built with [WXT](https://wxt.dev). One source builds **Chromium MV3 and Firefox MV2**.

```sh
npm install            # postinstall runs `wxt prepare`, which generates .wxt/
npm run dev            # Chromium dev + HMR, starts the test site and opens a browser on it
npm run dev:firefox    # Firefox dev (MV2) + HMR
npm run test-site      # the test site alone, http://localhost:8100
npm run typecheck      # tsc --noEmit — does not build or catch MV drift
npm run lint           # eslint .  (lint:fix to autofix)
npm run build          # Chrome production → .output/chrome-mv3/
npm run build:firefox  # Firefox production → .output/firefox-mv2/
npm run zip            # Store-submission zip (zip:firefox for the MV2 one)
```

There is no test runner yet. `npm run typecheck` is the only automated check besides lint.

`test-site/` holds one mock page per fill and capture case in the `ext-autofill`
specs. When a change adds a case, add its page and link it from `test-site/index.html`.

`wxt.config.ts` generates the manifest — there is no hand-written `manifest.json`.
Do **not** set a global `manifestVersion` there: it breaks Firefox dev (Firefox MV3
dev mode is unsupported upstream). The extension `version` is **not** in
`wxt.config.ts` either — WXT derives it from `package.json`, so bump it in one
place. Read [WXT-AND-BROWSERS.md](WXT-AND-BROWSERS.md) before touching
`entrypoints/` — it covers the `browser.*`-not-`chrome.*` rule, the MV3/MV2 split,
and message-passing gotchas.

`public/icon/*.png` ships as placeholder art. Replace it before any release.

## Comments & docs

Keep them short. These are rules, not preferences:

- **A file's comment block must be shorter than its code.** If it isn't, cut it.
- **Comment the *why*, never the *what*.** If the code already says it, delete the line.
- **A decision worth recording gets one or two sentences** — and only when someone
  would otherwise undo it by accident. No arguments, no "considered and rejected"
  narratives, no restating a trade-off from both sides.
- **No history.** Don't write what the code used to be. That's git.
- **Say it once.** Link to the one place a rule lives instead of repeating it in
  every file that obeys it.
- **Docs are checklists, not essays.** One line per bullet.

## Core constraints

- **Key material never touches disk.** The master password is dropped after
  derivation, the RSA private key lives only in `storage.session` (re-imported as a
  non-extractable `CryptoKey` on every worker wake) or MV2 background memory.
  Ciphertext and the app password may go in `storage.local`; nothing derived from
  the master password may, except under the warned "Never" timeout. (ADR-002)
- **Content scripts run in a hostile page.** They hold no vault state, never see the
  key, and receive exactly one credential per fill after the user picked it. URL
  matching and decryption stay in the background; `storage.session` keeps its
  default access level. This is what makes matching `*://*/*` safe, which stays as
  in Bitwarden (ADR-001). (ADR-002)
- **No request reveals the vault to anyone but the user's own server.** No favicon
  fetching, no icon services, no telemetry; API calls go from the background only,
  with `credentials: 'omit'`. (ADR-002, ADR-003)

## Open decisions

<!-- Surface these to the user rather than assuming. Delete the section once it's
empty. Each entry: the choice, the options, and what it blocks. -->

- Whether the "Custom" vault timeout is capped (for example at 24 hours) or
  unbounded. Blocks the timeout options in `ext-settings` (ADR-002).
