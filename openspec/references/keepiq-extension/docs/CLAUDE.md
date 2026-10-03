# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What Keepiq is

<!-- Replace this paragraph. One or two sentences on what the extension does and
who it is for — everything below inherits its priorities from this. Then link the
file that holds the full scope (a spec, a roadmap, an issue) and say plainly that
it, not this file, is the source of truth for scope. -->

Browser extension for Keepiq, the encrypted secrets manager for Nextcloud. Fill in logins, passkeys and one-time codes on any site, and save new ones as you go. Everything is encrypted — your master password and your secrets never reach the server.

## Stack & commands

Built with [WXT](https://wxt.dev). One source builds **Chromium MV3 and Firefox MV2**.

```sh
npm install            # postinstall runs `wxt prepare`, which generates .wxt/
npm run dev            # Chromium dev + HMR, auto-launches a browser
npm run dev:firefox    # Firefox dev (MV2) + HMR
npm run typecheck      # tsc --noEmit — does not build or catch MV drift
npm run lint           # eslint .  (lint:fix to autofix)
npm run build          # Chrome production → .output/chrome-mv3/
npm run build:firefox  # Firefox production → .output/firefox-mv2/
npm run zip            # Store-submission zip (zip:firefox for the MV2 one)
```

There is no test runner yet. `npm run typecheck` is the only automated check besides lint.

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

<!-- Delete this section or replace it wholesale. It is for the two or three
properties that shape every architectural decision in THIS extension — the things
a reviewer would otherwise "clean up" without realising what they cost. Write each
as a constraint plus its consequence, not as a preference.

Examples of the shape:
- "Undetectability is the defining requirement" → no globals, no guessable custom
  element names, shadow DOM, isolated world.
- "Offline-first" → no feature may depend on a network round-trip completing.
- "One user's data never crosses to another tab" → per-tab storage keys, no
  extension-global session state. -->

## Open decisions

<!-- Surface these to the user rather than assuming. Delete the section once it's
empty. Each entry: the choice, the options, and what it blocks. -->

- Whether the content script's `matches` stays `*://*/*` (the template default) or
  narrows to an allow-list. This affects the permission prompt users see and how
  both stores review the extension.
