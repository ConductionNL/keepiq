## MODIFIED Requirements

### Requirement: CXF entity to Keepiq type mapping
The system MUST map CXF entities to Keepiq secret types bidirectionally: passwords → `login`, notes → `note`, API keys → `api_key`, SSH keys → `ssh_key`, Wi-Fi → `note` with custom fields; export applies the reverse mapping. A TOTP or passkey entity MUST go on the `totp` or `passkeys` field of the first accepted login in its CXF item, else of its first accepted other secret, whatever the credential order and never on a rejected row, passkeys in the `passkey-item-type` canonical schema; an item with neither MUST become a login with an empty password that carries them. TOTP entities follow CXF 1.0: a base32 `secret` with `period`, `digits` and `algorithm`, stored as the bare secret when they are 30, 6 and `sha1` with no `issuer` or `username`, and as an otpauth URI otherwise; a TOTP with an algorithm or digit count Keepiq cannot generate MUST be rejected with a reason (CXF: importers MUST ignore unknown algorithms). The `url` shape of earlier Keepiq exports MUST still be read. Passkey entities MUST be read from `username` or the earlier `userName` and written with `username`. On export, a secret's `totp` and `passkeys` MUST be written as TOTP and passkey entities in that secret's own CXF item, while a standalone `totp` or `passkey` item keeps exporting as an item of its own.

#### Scenario: CXF passkey round-trips via the canonical schema
- GIVEN a CXF document with an item holding a passkey entity and no other credential
- WHEN it is imported
- THEN a login MUST be created with the credential in its `passkeys` field per the `passkey-item-type` canonical schema, encrypted in the browser, and its RP id in `url`

#### Scenario: A login keeps its code and passkeys through CXF
- GIVEN a login with a `totp` seed and passkeys
- WHEN it is exported as CXF and the file is imported again
- THEN one login MUST come back holding a seed that gives the same code, and the same passkeys

#### Scenario: A TOTP with an unknown algorithm
- GIVEN a CXF TOTP entity whose `algorithm` is not `sha1`, `sha256` or `sha512`
- WHEN it is imported
- THEN it MUST appear in the rejected list with a reason and no seed MUST be stored
