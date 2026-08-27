# ADR-002: Polymorphic Ownership on EncryptionSuite

**Status**: accepted

**Date**: 2026-03-23

## Context

EncryptionSuites — the records that hold a public certificate and AES-encrypted private key — must be owned by either a Nextcloud user or a registered Application. Both owner types require identical encryption operations (generate, revoke, look up by owner).

Two design options exist:
1. **Separate tables** — `UserEncryptionSuite` and `AppEncryptionSuite`
2. **Polymorphic association** — single `EncryptionSuite` table with `owner_type` (user|application) and `owner_id` columns

A complicating factor: Nextcloud users are not stored in Keepiq's own database — they are managed by Nextcloud's user backend. This means that even with separate tables, the "user" side cannot use a native foreign key constraint to the users table.

## Decision

Use a single `EncryptionSuite` table with polymorphic `owner_type` and `owner_id` columns.

- `owner_type`: enum `user` | `application`
- `owner_id`: string (Nextcloud user ID, or Keepiq Application row ID)

Referential integrity for the `application` owner type is enforced at the application layer with cascade delete logic. For the `user` owner type, a Nextcloud `IUserDeletedEvent` listener handles cleanup.

## Consequences

**Positive:**
- Single table, single query path for all encryption operations regardless of owner type
- Owner types are small and fixed (user, application) — no combinatorial explosion
- Encryption logic is identical regardless of owner type — no branching needed
- Extension to a third owner type (e.g., group) requires only a new enum value and cleanup listener

**Negative / trade-offs:**
- No database-level foreign key constraint for owner references — orphan risk must be handled in code
- Queries filtering by owner require composite index on `(owner_type, owner_id)`
- Code must explicitly handle the `user` case using Nextcloud's user management interfaces rather than a DB join

## Alternatives Considered

| Option | Reason not chosen |
|--------|------------------|
| Separate `UserEncryptionSuite` and `AppEncryptionSuite` tables | Duplicates identical schema and logic; does not solve the FK problem for Nextcloud users anyway |
| Single table with nullable `user_id` and `application_id` columns | Allows invalid state (both null, or both set); more complex validation; no cleaner than polymorphic |
