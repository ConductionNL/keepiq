## MODIFIED Requirements

### Requirement: Create a Secret from the UI
The system MUST provide a dialog reachable from the vault view that lets the user
create a new secret. The dialog MUST collect a name (required), a secret type
(from the seeded type catalogue), the secret value (required), and optional URL
and login fields. The optional target folder MUST default to the folder the user
is currently viewing.

The dialog MUST also let the user add ADDITIONAL FIELDS: named members carried
inside the Secret's single encrypted `additional_fields` blob. A member name that
collides with a reserved field name (`key`, `login`, `url`) MUST be refused with a
reason rather than accepted, because those names address the Secret's own columns
and a member bearing one would be misrouted or shadowed. Duplicate and blank
names MUST be refused.

On submit, the browser MUST encrypt the secret value (and the login, when present)
with the owner's suite certificate via the existing `importPublicKey` +
`rsaEncrypt` crypto BEFORE the request leaves the page, then POST the ciphertext
through `secret.createSecret`. The additional fields MUST be encrypted as ONE blob
by the same path. The server MUST never receive the plaintext value.
The dialog MUST be blocked (disabled) while the vault is locked.

#### Scenario: Create a secret with required fields
- **WHEN** the user opens the "New secret" dialog, enters a name and a secret value, and submits
- **THEN** the browser MUST RSA-encrypt the value with the suite certificate
- **THEN** the system MUST POST the ciphertext and create the secret (HTTP 201)
- **THEN** the new secret MUST appear in the vault list and its value MUST round-trip: opening it and decrypting MUST return the exact value entered

#### Scenario: Create a secret with additional fields
@e2e exclude Proven without a browser, and more strongly than a browser could: SecretCreateDialog.additionalFields "sends the members the user added, as one object" pins that the members reach the store, secret-additional-fields "encrypts the members as ONE blob on create" pins the single-blob shape, and additional-fields-crypto "round-trips every member name and value exactly as entered" does it with REAL RSA-4096 keys, including unicode and punctuation a serializer could mangle.
- **WHEN** the user adds one or more named additional fields and submits
- **THEN** they MUST be stored inside the encrypted `additional_fields` blob
- **AND** opening the secret and decrypting MUST return every member name and value exactly as entered

#### Scenario: A reserved name is refused as an additional field
@e2e exclude Dialog-level validation with one shared implementation: additional-fields "refuses every reserved name, with a reason" and "refuses a reserved name whatever its casing" cover the rule, AdditionalFieldsEditor "refuses a reserved name WITH a reason, and emits nothing" and "flags a rename onto a reserved or duplicate name" cover both entry points. Removing the check fails exactly those four and nothing else — verified by injecting it.
- **WHEN** the user tries to add an additional field named `key`, `login` or `url`
- **THEN** the dialog MUST refuse it and say why
- **AND** MUST NOT write that value into the Secret's corresponding column

#### Scenario: Create a secret inside the current folder
@e2e exclude Had NO coverage at all until this change (it was carried off PR #270 as a known gap rather than waived). Now driven by SecretCreateDialog.additionalFields "defaults the folder to the one being viewed, and persists it", which asserts both the default and the folderId in the payload.
- **WHEN** the user is viewing a folder and creates a secret
- **THEN** the dialog's folder field MUST default to that folder and the created secret MUST persist that `folderId`

#### Scenario: Name and value are required
@e2e exclude Also previously uncovered, and also from the #270 gap. Driven by SecretCreateDialog.additionalFields "requires a name AND a value before anything is sent", which walks every partial state including whitespace-only and asserts no request is made.
- **WHEN** the user submits with an empty name or empty value
- **THEN** the submit control MUST be disabled and no request MUST be sent

### Requirement: Edit a Secret from the UI
The system MUST provide an "Edit" affordance on the secret detail view that opens a
dialog pre-filled with the secret's current (decrypted) fields. The user MUST be
able to change the name, type, URL, login, and secret value. Only changed
sensitive fields (value / login) MUST be re-encrypted client-side before the PUT
via `secret.updateSecret`; metadata-only edits (name / type / URL) MUST NOT require
re-encryption.

The dialog MUST also present the secret's existing ADDITIONAL FIELDS, pre-filled
from the decrypted blob, and MUST let the user rename a member, change its value,
add another and remove one. The same reserved-name, duplicate and blank rules
apply as on creation. Because the members share one blob, any member change MUST
re-encrypt and write the whole blob; removing the last member MUST produce an
empty blob rather than a null value, so that "no additional fields" stays
distinguishable from "not loaded".

An owner MUST NOT have to ask another party to write an additional field for them.
Until this requirement, the only writers were the write-for-application dialog,
import, and an external recipient filling a secret request — so obtaining one on
your own secret meant asking a stranger to submit it.

#### Scenario: Edit a secret's value
- **WHEN** the user edits a secret, changes the value, and saves
- **THEN** the browser MUST RSA-encrypt the new value and PUT it
- **THEN** re-opening the secret and decrypting MUST return the updated value

#### Scenario: Edit an existing additional field
@e2e exclude Driven by SecretEditDialog.additionalFields "pre-fills the existing members from the decrypted blob" and "sends the whole blob when one member is renamed" / "when a value changes or a member is added"; the decrypt round-trip itself is proven with real keys in additional-fields-crypto. Removing the diff block fails exactly the three write-path tests.
- **GIVEN** a secret whose encrypted blob holds a member
- **WHEN** the user opens Edit
- **THEN** that member MUST be shown with its decrypted name and value
- **AND** changing either and saving MUST round-trip through decryption

#### Scenario: Remove the last additional field
@e2e exclude Driven at three layers, because the distinction is a JavaScript trap rather than a UI one. The write half: SecretEditDialog.additionalFields "sends an EMPTY object when the last member is removed", plus secret-additional-fields "sends an EMPTY blob, not null" and "leaves the stored blob alone when the caller sends nothing" — that pair is what makes empty-versus-absent meaningful. The read half ("re-opening MUST show no additional fields"): SecretDetail.additionalFields "shows NO additional-fields section for an empty blob", added after /opsx-verify found the view rendered the heading over an empty list, since `{}` is truthy AND an object. That state was unreachable before owners could remove a member.
- **WHEN** the user removes the only remaining member and saves
- **THEN** the stored blob MUST be empty rather than null
- **AND** re-opening the secret MUST show no additional fields and no error
