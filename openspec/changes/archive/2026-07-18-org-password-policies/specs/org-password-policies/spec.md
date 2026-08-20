## ADDED Requirements

### Requirement: Admin Configures the Org Password Policy
The system MUST let an administrator configure an instance-wide password policy stored in
`IAppConfig` (no new database table, per ADR-001), read and written through the existing
admin-settings surface guarded by `#[AuthorizedAdminSetting(AdminSettings::class)]`. The
policy MUST contain: `policy_enabled` (bool), `generator_min_length` (int), the four
`generator_require_*` character-class booleans, `min_zxcvbn_score` (int 0–4),
`block_on_hibp_hit` (bool), and `policy_exempt_types` (list of `SecretType.name`). When
`policy_enabled` is false the system MUST behave exactly as before this change.

#### Scenario: Admin saves a valid policy
- **WHEN** an admin submits a policy with `policy_enabled=true`, `generator_min_length=16`, and `min_zxcvbn_score=3`
- **THEN** the system MUST persist the values via `IAppConfig`
- **AND** MUST return the stored policy on the next admin-settings read

#### Scenario: Policy is instance-wide with no new table
- **WHEN** the policy is stored
- **THEN** it MUST be held in `IAppConfig` under the `doriath` app
- **AND** no new database table or migration MUST be introduced for the policy

### Requirement: Policy Validation
The system MUST reject invalid policy values server-side before persisting: a
`generator_min_length` below the generator hard-floor of 8, a `min_zxcvbn_score` outside
0–4, and `block_on_hibp_hit=true` while the instance-wide `breach_check_enabled` gate is
off (which would make the block a silent no-op).

#### Scenario: Length below the generator floor rejected
- **WHEN** an admin submits `generator_min_length=6`
- **THEN** the system MUST reject the change with a validation error and MUST NOT persist it

#### Scenario: HIBP block without the breach gate rejected
- **WHEN** an admin submits `block_on_hibp_hit=true` while `breach_check_enabled` is off
- **THEN** the system MUST reject the change explaining the breach gate must be enabled first

### Requirement: Generator Is Clamped to the Policy (Server-Side, Authoritative)
Because key generation runs server-side, the generator MUST clamp its resolved
configuration to the policy so it can never emit a value below the policy floor: the
effective length MUST be `max(requested_length, generator_min_length)`, every required
character class MUST be present in the resolved character set, and a `regex` override whose
provable length quantifier is below `generator_min_length` — or that excludes a required
class — MUST be rejected as invalid. This enforcement is authoritative: a generated value
is guaranteed compliant regardless of the client.

#### Scenario: Requested length raised to the policy floor
- **WHEN** the policy sets `generator_min_length=20` and a request asks for `length=12`
- **THEN** the generator MUST produce a value of at least 20 characters

#### Scenario: Required class forced into the character set
- **WHEN** the policy sets `generator_require_symbol=true` and a request omits special characters
- **THEN** the resolved character set MUST include the OWASP symbol set and the output MUST be able to contain a symbol

#### Scenario: Non-compliant regex override rejected
- **WHEN** the policy sets `generator_min_length=16` and a request supplies a regex whose length quantifier is `{8}`
- **THEN** the generator MUST reject the request with a validation error

### Requirement: Manual-Entry Value Checks Run Client-Side at Save
For a manually entered login password, the create/edit secret dialog MUST, before
encrypting and submitting, compute the value's zxcvbn score and refuse to submit when it is
below `min_zxcvbn_score`; and when `block_on_hibp_hit` is set, run the existing 5-character
k-anonymity HIBP check and refuse to submit a value found in the corpus. Because the server
never receives the plaintext, these checks are the honest-client boundary only.

#### Scenario: Weak manual password blocked before submit
- **WHEN** the policy requires `min_zxcvbn_score=3` and the user types a login value scoring 1
- **THEN** the dialog MUST disable the submit control and MUST NOT POST any ciphertext

#### Scenario: HIBP-hit manual password blocked before submit
- **WHEN** `block_on_hibp_hit` is set and the typed value appears in the HIBP corpus
- **THEN** the dialog MUST block submission and surface the breached-value state
- **AND** only the 5-character SHA-1 prefix MUST leave the browser for the check

#### Scenario: Compliant manual password submits normally
- **WHEN** the typed value meets `min_zxcvbn_score` and (if checked) is not in HIBP
- **THEN** the dialog MUST encrypt client-side and POST the ciphertext as usual

### Requirement: Zero-Knowledge Trust Model Is Explicit
The system MUST NOT inspect, score, or otherwise derive the strength or breach status of a
stored secret value on the server, and MUST NOT reject a save for a missing or false
client policy attestation. The manual-entry checks are hygiene guidance for honest clients,
not a security boundary against a hostile client; the client MAY send a non-authoritative
`policyAttested` flag that the server MAY record for audit context only.

#### Scenario: Server never gates a save on the value
- **WHEN** a secret create/update request arrives with ciphertext and no `policyAttested` flag (or `policyAttested=false`)
- **THEN** the server MUST still accept and store the ciphertext
- **AND** MUST NOT attempt to decrypt or score the value

#### Scenario: No server-side value-policy surface exists
- **WHEN** the registered routes are enumerated
- **THEN** no endpoint MUST accept a secret's plaintext value, score, or breach verdict for policy evaluation

### Requirement: Per-Type Policy Exemption
The system MUST skip the manual-entry value checks for any secret whose `SecretType.name`
is listed in `policy_exempt_types`. Because `type_id` is stored as plaintext, the applies-to
decision is a server-visible metadata rule; the value checks themselves remain client-side.
High-entropy machine-material types (`ssh_key`, `certificate`, `totp`) SHOULD be exempt by
default guidance, matching the password-health key-material exclusion.

#### Scenario: Exempt type skips the value checks
- **WHEN** the policy exempts `ssh_key` and the user saves an `ssh_key` secret
- **THEN** the dialog MUST NOT apply the zxcvbn or HIBP gate to that value

#### Scenario: Non-exempt type is gated
- **WHEN** the policy exempts only `ssh_key` and the user saves a `login` secret
- **THEN** the `login` value MUST be subject to the configured zxcvbn / HIBP checks

### Requirement: Policy Change Is Audited
The system MUST emit a `password_policy.updated` audit event on every successful policy
change, dispatched through the existing audit pipeline, recording the acting admin's uid and
the before/after policy field values. The event MUST NOT contain any secret value.

#### Scenario: Policy update dispatches an audit event
- **WHEN** an admin changes any policy field
- **THEN** the system MUST dispatch a `password_policy.updated` audit event naming the admin and the changed values
- **AND** the event MUST contain no secret data
