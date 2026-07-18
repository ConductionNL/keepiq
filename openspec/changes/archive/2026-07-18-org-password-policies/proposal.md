---
kind: code
---

# Proposal: Admin-defined org password policies enforced at save & generate time

## Why

Doriath has every building block for org-wide credential-hygiene policy but wires none
of them together into an admin-configurable policy. The generator already accepts a
`length` floor and character-class flags (`openspec/specs/key-generator/spec.md:16`)
and runs **server-side** (`openspec/specs/key-generator/spec.md:139`); password-health
already scores every value with zxcvbn client-side (`openspec/specs/password-health/spec.md:27`)
and checks HIBP via a k-anonymity proxy (`openspec/specs/password-health/spec.md:85`,
`lib/Controller/BreachProxyController.php`); admin settings already persist a
`min_password_length` / `min_password_score` pair — but only for the **master
password**, not for stored secret values (`openspec/specs/admin-settings/spec.md:22`).
Nothing lets an admin say "every login secret saved on this instance must score ≥ 3 and
not appear in HIBP." This is the single largest procurement gap for the Dutch
public-sector target: BIO2 / NIS2 credential-hygiene controls make *configurable*
password policy a checkbox, and Bitwarden (master-password + generator enterprise
policies), Keeper (enforcement policies), and Passbolt (Business password policies) all
gate exactly this behind paid tiers. The work here is mostly wiring + UI, not new
cryptography.

Verified there is no existing policy surface for secret **values**: `grep -ri "password_policy\|passwordPolicy\|min_secret" lib src` returns nothing; the only
`min_password_*` keys in `lib/Controller/SettingsController.php` (`getAdminSettings`
at `:132`, `updateAdminSettings` at `:147`) govern the master password, and no active
or archived `openspec/changes/*` proposal mentions org secret-value policy.

## What Changes

- Add an **org password policy** stored in `IAppConfig` (per ADR-001, no new table),
  configured on the Doriath admin settings page next to the existing master-password
  policy: `generator_min_length`, `generator_require_classes` (upper/lower/digit/symbol),
  `min_zxcvbn_score` for manually entered login passwords, `block_on_hibp_hit` (bool),
  and a `policy_exempt_types` list of `SecretType` names.
- **Lock the generator defaults to the policy** (real server-side enforcement): because
  generation runs server-side (`openspec/specs/key-generator/spec.md:139`), the generator
  endpoint MUST clamp its resolved config so it can never produce a value below the policy
  floor, and reject a regex override that provably yields a shorter/weaker value.
- **Enforce value checks client-side in the save flow** (the honest-client boundary):
  the secret create/edit dialogs (`openspec/specs/secrets-write-ui/spec.md:12`) run the
  zxcvbn score check and, when `block_on_hibp_hit` is set, the existing k-anonymity HIBP
  check BEFORE encrypting, and refuse to submit a non-compliant login value — because the
  server never sees the plaintext, these checks are hygiene guidance for honest clients,
  **not** a security boundary against a hostile client.
- Add a **policy-exemption axis keyed on `SecretType`**: `type_id` is server-visible
  plaintext (`openspec/specs/secrets/spec.md:63`), so the server authoritatively knows
  which secrets a policy applies to; exempt types skip the client value checks entirely.
- Add a **`password_policy.updated` audit event** (new `AuditEventTypes` constant,
  dispatched via the existing `dispatchAudit` path, `lib/Service/SecretService.php:855`)
  on every policy change, recording actor + before/after policy values (no secret data).
- Explicitly **out of scope**: any server-side inspection of a stored secret value;
  retroactive enforcement / bulk re-scoring of existing secrets; per-user or per-group
  policy scoping (instance-wide only in v1); master-password policy changes (unchanged).

## Capabilities

### New Capabilities
- `org-password-policies`: admin-configured, instance-wide password policy that clamps
  the server-side generator and gates the client-side secret save flow, with per-type
  exemptions and an audit trail.

### Modified Capabilities
_(none — generator and password-health behaviour is reused, not respecified; their specs
are referenced, not modified. The delta lives entirely in the new capability.)_

## Impact

- **Backend**: `SettingsService` / `SettingsController` gain policy read/write + validation;
  the key-generator service clamps its resolved config to the policy floor; new
  `AuditEventTypes::PASSWORD_POLICY_UPDATED`. All via `IAppConfig` — no migration.
- **Frontend**: admin settings gains a "Password policy" `CnSettingsSection`; the secret
  create/edit dialogs consume the policy and block non-compliant login values pre-encrypt.
- **Zero-knowledge**: server stores policy + enforces the generator floor and the
  type-exemption metadata rule; it can NEVER verify a manually entered value — documented
  as an honest-client hygiene control, not a hostile-client boundary.
- **Sister app**: OpenConnector writes secrets through the machine seam, not the browser
  save flow; the policy governs the browser UI only and does not change the machine
  write contract.
