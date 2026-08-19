# Design — leaf-integrations (documented decision)

## Context

The fleet leaf wave assumes an app's domain objects live in OpenRegister and gain leaf widgets by declaring `linkedTypes`. Doriath inverted that assumption on purpose: it is a zero-knowledge secrets vault whose entities are app-owned tables with their own authorization model (suites, shares, delegation, team folders), and its register is a placeholder (`example` schema only — verified at HEAD; `RegisterConfigurationLoader` exists for AppHost adoption, not for domain data). The question this change answers is not "which leaves do we add" but "is any leaf justifiable", and the answer, after walking the candidates, is no — so the change ships the decision, its rationale, and a guard, and nothing else. Honesty over volume.

## Goals / Non-Goals

**Goals:**
- Make "Doriath has zero leaves" a specced decision a fleet sweep can cite, not an apparent gap
- Pin the underlying rule (no secret material *or vault-structure metadata* outside the vault ACL envelope) as a requirement that binds future changes
- Guard the register shape by test so a codemod can't adopt a leaf silently
- Define the exception gate so the decision is revisitable without being bypassable

**Non-Goals:**
- Building any leaf, feed, or calendar/deck/files/activity surface
- Migrating any Doriath entity into OpenRegister
- Changing the expiry/rotation notification pipeline (referenced as the served alternative, untouched)
- The MCP question (separate `hermiq-ai-tooling` change; different mechanism, different — narrower — data class)

## Decisions

### D1: Refuse all four candidate leaves, individually

| Leaf | Candidate use | Refusal |
|------|---------------|---------|
| files | attachments, backups, exports | Puts secret-adjacent artifacts under NC Files sharing; export egress is already defined client-side by secret-export/encrypted-attachments — a second, server-mediated path would be strictly worse |
| calendar | cert/CA expiry, rotation due dates | Entry names + dates in CalDAV = a shareable, syncable, exportable map of the credential estate and its weakest moments; duplicate of the 90/30/7 scan pipeline that already notifies under vault ACL |
| deck | rotation task follow-ups | Copies entry names onto boards with independent sharing; rotation flags are the in-app queue |
| activity | vault event stream | The audit trail is deliberately in-app (secret-audit-trail spec direction); an activity leaf is an audit-trail sink outside the vault's access model |

The near-miss is calendar with *anonymised* items ("3 certificates expire this week", no names) — rejected too: an anonymous calendar item cannot be acted on without the app anyway, so it adds a sync/sharing surface for zero workflow value. It remains possible through the exception gate if a real need appears.

### D2: The load-bearing rule is about metadata, not just ciphertext

The zero-knowledge property already protects values (the server holds ciphertext). What leaves would actually leak is *structure*: names, folders, expiry dates, certificate identities — `Secret.name`/`url` and all of `CertificateMetadata` are plaintext server-side by design. The spec therefore forbids exporting vault-structure metadata across the ACL boundary, which is the rule the candidates all break, and states it once so per-leaf arguments don't have to be re-litigated.

### D3: Guard by test, not by convention

A one-line PHPUnit test (`RegisterLeafGuardTest`) scans the register JSON (and any future fragments) for `linkedTypes` / `mailObjectTemplate`. Rationale: fleet codemods legitimately mass-edit register files; a decision that exists only in prose is invisible to them. The test names this spec in its failure message so the developer lands here.

### D4: Ship it as a spec change with near-zero code

Precedent in this repo: stating deliberate absences as spec content ("admin-initiated export of another user's vault — cryptographically impossible by design — worth stating", secret-export design). One guard test and one FEATURES.md line are the entire footprint.
