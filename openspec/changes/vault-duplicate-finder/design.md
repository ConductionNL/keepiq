# Design: find duplicate items in the vault and merge them

## Context

At development `4c214a9d`:

- `src/store/modules/health.js:109` `analyseVault()` fetches the owner-scoped list and `:159` `loadDecryptedRows()` decrypts each value in the browser, excluding authenticator seeds; the engine runs in a web worker (`src/health/worker.js`, `src/health/engine.js`) and is terminated on lock.
- `src/health/engine.js:97-111` already hashes every value and buckets identical digests to mark reuse.
- `openspec/specs/password-health/spec.md:111-112` forbids any endpoint that accepts scores, digests, reuse data or verdicts.
- The import wizard's duplicate step (`src/store/modules/import.js:58-61`, `:83-89`) compares incoming rows with the vault by name and address.
- `src/views/HealthReportView.vue` renders the categories weak, reused, stale, breached, compromised and rotation (`:95-153`).
- `SecretService::update()` (`lib/Service/SecretService.php:825`) accepts new ciphertext for the owner; `delete()` (`:931`) removes a secret and its shares.
- `src/utils/favicon.js` `extractDomain()` turns a stored address into a host.

## Goals / Non-Goals

**Goals**
- Show the user where their vault holds the same credential more than once, and let them collapse it in one guided step.

**Non-Goals**
- Merging recipients' copies of shared items, or items owned by someone else.
- Merging attachments or version histories. The kept item keeps its own; the others' go with them (to the trash when it exists).
- Fuzzy name matching. Grouping is by host, username and value only.

## Decisions

**D1. Group on host, username and value.** Exact duplicate: same host (from `extractDomain()` over `url`), same decrypted username and same decrypted value. Likely duplicate: same host and username, different value. Items without an address are grouped on exact name, username and value only. Alternative: reuse the import wizard's name and address match. Rejected: names differ between browsers ("GitHub" and "github.com") while host and username do not.

**D2. Detection runs with the health engine.** `src/health/duplicates.js` is a pure function called from the same worker, on rows that now also carry the decrypted username. Nothing new is kept after lock.

**D3. Merge is update then delete.** The kept item is re-saved with the folded additional fields, encrypted in the browser for the owner's suite; the others are deleted through the existing route, so their shares end as today. With `vault-trash-and-archive` in place the delete is a trash move and the merge can be undone item by item. Alternative: a server-side merge endpoint. Rejected: the server cannot read the fields it would merge.

**D4. Shared items are allowed but announced.** An owned item that is shared is marked in its group, and choosing to merge it away shows how many people lose access. Recipient copies (rows whose source is someone else's) are never listed.

## Security and zero-knowledge

Grouping and merging run in the browser on decrypted data the user already may read. No digest, group or count reaches the server, which keeps `password-health` "No Server-Side Health Knowledge". The merge writes ciphertext through the owner's existing update path; the delete audit events carry identifiers and names only, as today.

## Risks / Trade-offs

- Two genuinely different accounts with one username on one host (a personal and a work login on one site with the same email) show as a likely duplicate. Likely duplicates are never pre-selected for merge.
- Before the trash lands, a merge deletes for good. The confirmation says so until then.

## Seed data

Keepiq owns its tables (keepiq ADR-001) and has no OpenRegister register. The dev fixture owner `admin` gets two identical logins for `example.org` and one with a different password, so both group kinds appear.

## Migration

None.
