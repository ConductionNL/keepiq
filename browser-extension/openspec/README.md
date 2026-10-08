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
4. `ext-generator`: password, passphrase and username generator
5. `ext-send`: ephemeral sends
6. `ext-settings`: the Settings tab
7. `ext-autofill`: fill from popup, context menu, shortcut, save and update prompts

Later slices not yet specced: inline autofill menu, passkey provider, TOTP autofill, card and identity fill, password history, import and export. Website icons wait on a Keepiq change that stores a favicon on the secret (ADR-002).

## Server facts

Everything about the Keepiq API and crypto lives in `architecture/adr-003-keepiq-api-contract.md`. The Keepiq app's own `openspec/specs` are the source of truth for server behaviour. The `browser-extension/` folder inside the Keepiq app repo is not a reference for this project.
