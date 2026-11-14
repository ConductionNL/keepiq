# OpenSpec

Specs and architecture decisions for the Keepiq browser extension. Workflow: the `opsx-*` skills from the user's global `.claude` directory (`/opsx-ff`, `/opsx-continue`, `/opsx-apply`, `/opsx-verify`, `/opsx-archive`).

| Path | Purpose |
| --- | --- |
| `config.yaml` | Project context and per-artifact rules the skills load |
| `architecture/adr-*.md` | Repo decisions. ADR-001 Bitwarden parity, ADR-002 key lifetime and caching, ADR-003 the Keepiq API contract, ADR-004 React popup |
| `changes/<name>/` | One change per feature slice: proposal, specs, design, tasks |
| `specs/` | Main specs, filled by `/opsx-sync` or `/opsx-archive` from a change's delta specs |

## Change chain

Changes build on each other in this order. Each proposal lists its predecessors in `depends_on`.

1. `ext-accounts-and-unlock`: accounts, app-password auth, unlock and lock, storage, API client, crypto module
2. `ext-vault-browse`: sync, popup shell with tabs, vault list, item detail
3. `ext-vault-edit`: add, edit, delete, clone, move, folders
4. `ext-share-sync`: an edit of a shared item reaches every recipient's copy, from the ext-vault-edit review
5. `ext-i18n`: English and Dutch message catalogs, which every later change's text goes through
6. `ext-generator`: password, passphrase and username generator, ported from Keepiq's shared generator
7. `ext-send`: ephemeral sends
8. `ext-theme`: the Theme setting, the Settings tab's section list and its Appearance view
9. `ext-settings`: the rest of the Settings tab
10. `ext-autofill`: fill from popup, inline list, context menu, shortcut, save and update prompts
11. `ext-toolbar-icon`: logged out, locked and unlocked icon, badge counter of matching logins
12. `ext-totp-autofill`: one-time codes, filled and copied
13. `ext-totp-capture`: a login's authenticator key, typed or scanned from a QR code
14. `ext-card-identity-fill`: card and identity fill
15. `ext-context-menu`: the Keepiq context menu anywhere on the page
16. `ext-default-password-manager`: turning off Chrome's own password manager
17. `ext-passkey-provider`: passkeys saved and signed with from a login
18. `ext-safari`: Safari 26 on macOS, its containing app, native clipboard and website access

Later slices not yet specced: password history, import and export, Safari on iOS. Website icons wait on a Keepiq change that stores a favicon on the secret (ADR-002).
Also later: answering a pending save or update from the toolbar popup, reachable by keyboard and behind page modal dialogs.

## Server facts

Everything about the Keepiq API and crypto lives in `architecture/adr-003-keepiq-api-contract.md`. The Keepiq app's own `openspec/specs` are the source of truth for server behaviour. The `browser-extension/` folder inside the Keepiq app repo is not a reference for this project.
