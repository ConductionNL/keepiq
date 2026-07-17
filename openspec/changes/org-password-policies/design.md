# Design: Org password policies

## Context

Doriath is zero-knowledge: the server never sees a secret's plaintext value
(`openspec/architecture/adr-003-rsa-aes-encryption-architecture.md`, ADR-003). The
secret create/edit dialogs encrypt client-side with the owner's suite certificate
before the POST (`openspec/specs/secrets-write-ui/spec.md:12`). Two mechanisms already
exist that a policy can lock down: the **server-side** key generator
(`openspec/specs/key-generator/spec.md:139`, config fields at `:16`) and the
**client-side** password-health engine (zxcvbn scoring at
`openspec/specs/password-health/spec.md:27`, HIBP k-anonymity via a Doriath proxy at
`:85` / `lib/Controller/BreachProxyController.php`). Admin settings already persist a
master-password floor via `IAppConfig` and `SettingsService`
(`lib/Controller/SettingsController.php:132` read, `:147` write;
`openspec/specs/admin-settings/spec.md:22`). This change adds an org policy for stored
**secret values** using those same rails — no new table, no new crypto.

## Goals / Non-Goals

**Goals:**
- Admin sets an instance-wide policy: generator floor (length + required char classes),
  minimum zxcvbn score for manually entered login passwords, optional block-on-HIBP-hit
  at save, and a per-`SecretType` exemption list.
- Generator can never emit a value below the policy floor (server-side, authoritative).
- The save flow refuses a non-compliant manually entered login value (client-side).
- Every policy change is audited.
- Be explicit and honest about which checks are real boundaries and which are hygiene.

**Non-Goals:**
- Any server-side inspection or scoring of a stored secret value (breaks ADR-003).
- Retroactive enforcement / bulk re-scoring of existing secrets (a follow-up could pair
  with `bulk-actions`).
- Per-user / per-group policy scoping — instance-wide only in v1.
- Changing the master-password policy (`min_password_length` / `min_password_score`
  stay as-is and govern a different thing).
- A hostile-client guarantee — see the trust-model decision below.

## Decisions

### Decision: The policy lives in IAppConfig, not a new table (ADR-001)

The policy is a small, single, instance-wide config object, exactly like the existing
master-password floor. Store it as discrete `IAppConfig` keys under the `doriath` app,
read/written through `SettingsService` and surfaced by `SettingsController::getAdminSettings`
/ `updateAdminSettings` (`lib/Controller/SettingsController.php:132`, `:147`), guarded by
`#[AuthorizedAdminSetting(AdminSettings::class)]` as those methods already are. No
Doctrine entity, no migration.

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `policy_enabled` | bool | false | Master off-switch; off = today's behaviour |
| `generator_min_length` | int | 16 | MUST be ≥ generator hard-floor 8 (`key-generator/spec.md:31`) |
| `generator_require_upper` | bool | false | |
| `generator_require_lower` | bool | false | |
| `generator_require_digit` | bool | false | |
| `generator_require_symbol` | bool | false | Uses the OWASP set (`key-generator/spec.md:69`) |
| `min_zxcvbn_score` | int | 0 | 0–4; 0 = no manual-entry score gate |
| `block_on_hibp_hit` | bool | false | Requires `breach_check_enabled` already on (`password-health/spec.md:85`) |
| `policy_exempt_types` | string[] | `[]` | `SecretType.name` values exempt from value checks |

### Decision: Two enforcement surfaces with different trust levels — stated plainly

There are exactly two points a value passes through, and they are **not** equally strong:

1. **Generator (server-side, authoritative).** Generation happens in PHP
   (`key-generator/spec.md:139`), so the server produces the value and CAN enforce the
   policy floor for certain. The generator's resolved config is **clamped** to the policy:
   `length = max(requested, generator_min_length)`, required classes are force-included in
   the character set, and a `regex` override is rejected when its provable length
   quantifier is below `generator_min_length` or it excludes a required class (reusing the
   generator's existing regex-validity gate at `key-generator/spec.md:42`). A generated
   value is therefore guaranteed compliant.

2. **Manual entry (client-side at save, honest-client only).** For a value the user types,
   the server never sees plaintext, so the checks run in the create/edit dialog before
   encryption (`secrets-write-ui/spec.md:12`): compute the zxcvbn score, compare to
   `min_zxcvbn_score`; if `block_on_hibp_hit`, run the existing 5-char-prefix k-anonymity
   check (`password-health/spec.md:85`). If a check fails, the submit control is disabled
   and no ciphertext is POSTed. A modified or hostile client can bypass all of this — the
   server cannot tell. **This is documented as hygiene guidance for honest clients, not a
   security boundary.** The client MAY send a non-authoritative `policyAttested: true`
   flag; the server MAY store it for audit context but MUST NOT treat it as proof and MUST
   NOT reject a save for its absence (that would be security theatre).

### Decision: Policy scope is keyed on SecretType, which the server can see

`type_id` is stored in plaintext (`openspec/specs/secrets/spec.md:63`), so "does this
policy apply to this secret?" is answerable server-side as a metadata rule. The exemption
list (`policy_exempt_types`) names `SecretType.name` values; the value checks (both the
manual-entry gate and any UI policy badges) are skipped for exempt types. Only `login`-ish
password-bearing types are policy-relevant anyway — `ssh_key`, `certificate`, and `totp`
carry high-entropy machine material that zxcvbn scores as noise (mirrors the
password-health exclusion at `password-health/spec.md:28`), so those are exempt by default
guidance.

### Decision: Audit via the existing pipeline, no secret data

Add `AuditEventTypes::PASSWORD_POLICY_UPDATED = 'password_policy.updated'`
(`lib/Event/Audit/AuditEventTypes.php`) and dispatch it from the policy-write path using
the existing `dispatchAudit(AuditEvent::forUser(...))` mechanism
(`lib/Service/SecretService.php:855`). The event carries the actor uid and the
before/after policy field values — all non-sensitive config, never a secret value.

### Declarative-vs-imperative decision

Doriath has no OpenRegister; everything is imperative PHP by ADR-001. The policy is read
and validated in `SettingsService`, the generator clamp is imperative code in the
key-generator service, and the client checks are imperative Vue in the write dialogs.
There is no declarative schema/register layer.

## API / surfaces

- `GET /settings/admin` (existing `getAdminSettings`) — extended to return the policy
  block. `#[AuthorizedAdminSetting]`.
- `PUT/POST /settings/admin` (existing `updateAdminSettings`) — extended to validate and
  persist the policy; emits the audit event. Validation rejects `generator_min_length < 8`,
  `min_zxcvbn_score` outside 0–4, and `block_on_hibp_hit=true` while `breach_check_enabled`
  is off. `#[AuthorizedAdminSetting]`.
- `GET /settings/policy` (new, `#[NoAdminRequired]`) — read-only policy projection the
  write dialogs consume so a normal user can enforce the policy locally without admin rights
  (returns only the policy floor + exempt types, no other admin config).
- Generator endpoint (existing) — clamps its resolved config to the policy before
  generating; no signature change.

## Frontend surfaces (Vue 2)

- **Admin "Password policy" section** — a `CnSettingsSection` in the Doriath admin
  settings page with the fields above; save calls `updateAdminSettings`. Modal isolation /
  input-label gates apply (any `NcSelect` for the exempt-type picker needs `inputLabel`).
- **Create/edit secret dialog** (`src/dialogs/`, per ADR-004 modal isolation, reused from
  `secrets-write-ui`) — on a policy-relevant, non-exempt type, shows the required floor,
  live zxcvbn meter vs `min_zxcvbn_score`, and (if `block_on_hibp_hit`) a breached-value
  block; disables submit until compliant.
- **Generator modal** — its length/class controls are clamped to the policy floor and show
  a "locked by org policy" hint on clamped controls.

## Risks / Trade-offs

- **Honest-client manual-entry gate is bypassable** — a determined user can edit the
  bundle or POST ciphertext directly. Accepted and documented; the generator surface is the
  only hard guarantee. Mitigation: default-guide admins to generated passwords.
- **HIBP block depends on a second gate** — `block_on_hibp_hit` is a no-op unless
  `breach_check_enabled` (admin) is on; validated at save time so the admin can't set an
  ineffective policy silently.
- **Exempt-by-type coarseness** — exemption is per type, not per secret; a mixed-type vault
  can't exempt one login. Accepted for v1.
- **No retroactive enforcement** — pre-existing weak secrets stay until re-saved; a future
  change could pair a bulk re-scan with `bulk-actions`.

## Decisions made under uncertainty

1. **Policy in `IAppConfig`, not a table** — chosen to match the existing master-password
   floor and avoid a migration; assumes instance-wide single policy is enough for v1.
2. **Two enforcement surfaces, honestly unequal** — generator = server-authoritative,
   manual entry = honest-client hygiene; chosen because ADR-003 forbids the server ever
   seeing the value, so a manual-entry hard boundary is impossible by construction.
3. **`policyAttested` flag is non-authoritative** — accepted for audit colour only; the
   server never rejects on its absence, to avoid security theatre.
4. **Exemption keyed on `SecretType.name`** — chosen because `type_id` is the only
   value-relevant attribute the server can see without decrypting.
5. **`min_zxcvbn_score` applies to `login`-type password values only** — non-password key
   material (ssh/cert/totp) is exempt by default, matching password-health's key-material
   exclusion; assumes admins don't want zxcvbn noise-gating machine keys.
6. **HIBP block reuses the existing proxy unchanged** — no new endpoint; assumes the
   k-anonymity proxy's soft-degrade (`password-health/spec.md:106`) is acceptable (an
   unreachable HIBP means the block can't be evaluated → save is allowed, fail-open, since
   the check is hygiene not a boundary).
7. **Policy off by default (`policy_enabled=false`)** — chosen so upgrade is a no-op and
   the instance opts in deliberately.
