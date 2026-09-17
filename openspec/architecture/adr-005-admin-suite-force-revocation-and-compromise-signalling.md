# ADR-005: Administrator Suite Force-Revocation and Compromise Signalling

**Status**: accepted

**Date**: 2026-09-14

## Context

Owner-initiated suite revocation now requires a verified vault-key proof (change
`harden-vault-key-material-guards`, #673): the caller must sign a challenge with
the suite's own private key. An administrator cannot produce that proof — the
vault is zero-knowledge and the server never holds a usable private key — yet
administrators must still be able to revoke a suite they do not own, for three
real situations:

1. **Forgotten master password.** The user is locked out of their own vault.
   Because #673 blocks a proofless rotation, administrator revocation is the
   *only* way back to a working vault: the dead suite is revoked so the user can
   re-onboard with a fresh one. This is the lost-password route of #395.
2. **De-authorisation.** The user leaves and their access must be pulled. They
   may still *know* the secrets they could read.
3. **Compromise.** The private key or master password is in an attacker's hands
   (e.g. a stolen, MDM-wiped laptop; a changed master password making the suite
   irrecoverable).

Application-owned suites additionally have no human owner who can produce a
proof at all, so administrator revocation is their only revocation path.

Revocation is destructive: `EncryptionSuiteRevokedListener` deletes the owner's
inbound `ShareTarget`s, promotes their temporary delegations, and the emergency
listener clears their break-glass recovery envelopes; all secret reads are then
refused.

Whether the revoked suite's secrets should be treated as **compromised** — and
so flagged for rotation — is a *human, situational* judgment, not a property of
the act of revoking:

- A forgotten password whose audit trail shows no access since the user's last
  legitimate use is no compromise at all.
- A stolen key is unambiguously a compromise.
- An amicable departure where secrets are long, generated, non-memorable
  passwords sits in between, and a trusting administrator may reasonably decide
  *not* to rotate everything.

The infrastructure to act on a compromise already exists, but is wired to the
compromise-*recovery rotation* path, not to revocation:

- `Secret.possibly_compromised_at` (field, `jsonSerialize`, compliance count).
- `RotationFlagService::flagCompromisedSecrets(ownerId)` — idempotent, raises
  `suite_compromise` rotation flags for every flagged secret of an owner.
- `NotificationService::notify(subject: 'secret_compromised', …)`.
- `SuiteCompromiseListener` — on `SuiteMigrationCompletedEvent`, walks secrets on
  the **new** suite and notifies owners; keyed on migration, absent for revoke.
- `SecretMapper::findByEncryptionSuiteId($suiteId)` — every secret sealed under a
  suite's key (the owner's own plus received shared copies) = the exact blast
  radius of that key.

`revoked_reason` is an existing free-form `STRING(255)` column, read only by the
GDPR export and `jsonSerialize`, consumed by no behavioural code.

## Decision

Add `POST /api/v1/suites/{id}/force-revoke`, an administrator endpoint that
revokes **any** suite by id (user- or application-owned), guarded by:

- `#[AuthorizedAdminSetting(AdminSettings::class)]` — administrator only,
  mirroring the existing admin-only `reinstate()`; and
- `#[PasswordConfirmationRequired]` — Nextcloud sudo mode. The administrator
  re-confirms their **own** password; there is no vault key to prove. This is
  the app's first use of `PasswordConfirmationRequired`.

It reuses `EncryptionSuiteService::revokeSuite()`, which is owner-agnostic and
records `revokedBy` (the administrator).

**Reason.** The administrator supplies a **required, free-form** reason, stored
in the existing `revoked_reason` field (GDPR: the specific "why" must be
recordable). No new column, no migration.

**Compromise decision.** Whether to treat the suite as compromised is an
**explicit, transient request parameter** `markCompromised` (default `false`),
**not persisted** as a suite column:

- When `true`, the revoke path flags every secret sealed under the suite
  (`findByEncryptionSuiteId`) as `possibly_compromised_at`, raises
  `suite_compromise` rotation flags via `RotationPolicyService`, and notifies the
  affected owners — reusing the existing cascade primitives, adapted to the
  revoke path (no migration; scope is the revoked suite itself).
- When `false`, no cascade runs, and the UI shows a warning that the revoked
  user still knows these secrets and rotation may be warranted.

**Emergency access** is cleared unconditionally — revocation is authoritative —
but the count of destroyed *usable* emergency contacts
(`countUsableForGrantorSuite`) is recorded in the audit metadata and surfaced to
the administrator as an informational warning, **not a gate**. This matters most
for the forgotten-password case, where emergency access may be the user's
genuine recovery route and revoking deletes it.

**Audit.** The `SUITE_REVOKED` audit event's metadata carries
`{ reason, markCompromised, emergencyContactsDestroyed }`.

**After revocation**, a user left with no active suite re-onboards with a fresh
suite through the existing onboarding flow.

## Consequences

**Positive:**

- Reuses the existing revoke, compromise-flag, rotation-flag and notification
  infrastructure; the only net-new backend logic is the endpoint, the guard
  wiring, and adapting the compromise cascade to the revoke (no-migration) path.
- No schema change and no `<version>` bump — `revoked_reason` is reused and the
  compromise trigger is never persisted.
- The compromise decision is a deliberate, informed, audited human act rather
  than an automatic or text-derived one.
- One endpoint covers all three administrator scenarios; application-suite
  revocation gains a first-class, properly guarded path.

**Negative / trade-offs:**

- `markCompromised` is not queryable off the suite table — only via the audit
  trail or the flagged secrets. Acceptable: no UI needs it, and the durable
  compromise evidence lives on the secrets it flags.
- The compromise cascade adds a branch/listener that must be tested for the
  revoke path specifically (it cannot ride the migration-complete tests).
- Sudo mode adds a re-authentication step administrators must complete; it is
  new to this app and needs a client-side confirmation flow.

## Alternatives Considered

- **Persist a `revoked_type` enum column.** Rejected: a new column plus a
  migration to encode what the free-form reason and the durable per-secret flags
  already convey — two columns for one concept.
- **Always cascade on any revocation.** Rejected: a forgotten password with a
  clean audit trail, or an amicable departure with strong generated passwords, is
  not a compromise; forcing rotation there is noise. The judgment is the
  administrator's to make.
- **Derive compromise from the free-form reason text.** Rejected: parsing human
  text ("compromised" vs "key leaked" vs "laptop stolen") to drive a destructive,
  effectively irreversible cascade is fragile.
- **Gate revoke on emergency access** (as the owner path gates on
  `acceptEmergencyLoss`). Rejected: administrator revocation is authoritative and
  is frequently *itself* the offboarding or compromise response; surface the
  count as a warning and in the audit instead of blocking.
- **Require a vault-key proof like the owner path.** Impossible: the
  administrator holds no vault key (zero-knowledge). Sudo mode is the correct
  administrator re-authentication.

## Related

- ADR-002 (polymorphic suite ownership — the `user`/`application` owner types a
  single force-revoke endpoint serves)
- ADR-003 (always-E2E encryption — why an administrator cannot hold a vault key)
- Change `harden-vault-key-material-guards` (#673 — the owner-revoke vault-key
  proof this endpoint is the administrator counterpart to)
- Change `migrate-emergency-access-on-rotation` (#674 — `countUsableForGrantorSuite`
  and the emergency-clear-on-revoke behaviour)
- Encryption-suites spec open question "Forced intermediate revocation and secret
  compromise" — the analogous question one layer down, at the CA intermediate
