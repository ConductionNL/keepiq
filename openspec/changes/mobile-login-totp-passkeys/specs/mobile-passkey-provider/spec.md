## MODIFIED Requirements

### Requirement: Keepiq as a passkey provider
On iOS 17+ and Android 14+ the apps MUST act as a passkey provider. They offer the passkeys stored in the vault, on a login's `passkeys` field or as a standalone Passkey item, when an app or browser asks to sign in with a passkey, and save new passkeys when one is created. Passkeys use the same credential JSON as the web app and the browser extension, so a passkey created on any client works on the others.

A new passkey MUST be saved onto a login, never as a standalone Passkey item. It goes onto the login for the same site, not linked to an app, whose user name equals the passkey's user name, ignoring case and surrounding spaces, when exactly one such login exists, the user may edit it, and its `passkeys` is empty or an array; a value that is not an array is never rewritten. Otherwise the apps MUST create a new login named after the relying party, with its rpId as address, the passkey's user name, an empty password and the passkey. Adding to a login MUST fetch it fresh and keep every passkey it already holds. When the fresh login is gone (deleted elsewhere since the phone last synced) or turns out to be one the server takes no passkeys on (no longer the user's to change, or not owned by a user), a new login MUST be made instead. When the fresh login's `passkeys` is no longer an array, the registration MUST fail and nothing is written.

Signing with a passkey on a login MUST fetch the login fresh first and sign with that credential as the server holds it, so a non-zero counter is the stored one plus one; when the credential is no longer on the login, signing MUST fail, whatever its counter. Only when the fresh read gets no answer from the server at all MAY a passkey whose counter is zero sign from the phone's copy, since nothing is written; any other failure of the fresh read, such as a `passkeys` value that cannot be decrypted, MUST fail the sign-in. A counter written back MUST change only that credential's counter, the entry with that credential id and rpId.

Every write of a login's `passkeys` (a counter, a new passkey, a delete) MUST send the fetched row's `updatedAt` as `baseUpdatedAt`, so the server refuses it when the login changed in between; the app then reads the login again and makes the change once more, and a second refusal fails.

A passkey on a login the user may not change (a use-only or read-only copy) is offered like any other, but its counter cannot be written back: once that counter is above zero, signing with it MUST fail, because a failed write-back fails the sign-in. With a counter of zero it signs, since nothing is written.

#### Scenario: Sign in with a passkey made in the browser extension
- **GIVEN** a passkey for `example.com` created with the Keepiq browser extension
- **WHEN** the user signs in to `example.com` on the phone and picks Keepiq in the passkey sheet
- **THEN** the site accepts the signature after the user unlocks

#### Scenario: Sign in with a passkey on a login
- **GIVEN** a login for `example.com` whose `passkeys` field holds a passkey for rpId `example.com`
- **WHEN** the user signs in to `example.com` on the phone and picks that passkey
- **THEN** the site accepts the signature
- **AND** a non-zero counter is stored on that passkey of the login, and its other passkeys are unchanged

#### Scenario: A counted passkey on a read-only login
- **GIVEN** a login shared read-only whose `passkeys` holds a passkey with a counter of 4
- **WHEN** the user picks that passkey in the passkey sheet and unlocks
- **THEN** the server refuses the counter write and the sign-in fails

#### Scenario: Create a passkey for an account the vault has a login for
- **GIVEN** Keepiq as the passkey provider and one login for `example.com` with user name `alice`
- **WHEN** `example.com` asks to create a passkey for `alice` and the user chooses Keepiq
- **THEN** the passkey is added to that login's `passkeys`, and the web app shows it on the login after its next load
- **AND** no Passkey item is created

#### Scenario: A login linked to an app, or with passkeys that are not an array
- **GIVEN** one login for `example.com` with user name `alice` that is linked to an Android app, or whose `passkeys` holds something other than an array
- **WHEN** `example.com` asks to create a passkey for `alice` and the user chooses Keepiq
- **THEN** that login is not a match and is left as it is, and a new login with the passkey is saved

#### Scenario: A passkey deleted on another device
- **GIVEN** a passkey on a login that another device deleted after this phone last synced
- **WHEN** the user picks it in the passkey sheet with the phone online
- **THEN** the sign-in fails and nothing is written

#### Scenario: Create a passkey without a matching login
- **GIVEN** Keepiq as the passkey provider and no login for `example.com` with user name `alice`
- **WHEN** `example.com` asks to create a passkey for `alice` and the user chooses Keepiq
- **THEN** a new login for `example.com` with user name `alice`, an empty password and the passkey is saved

#### Scenario: Android before version 14
- **GIVEN** a phone on Android 13
- **WHEN** the user opens the passkey settings in the app
- **THEN** the app explains that passkeys need Android 14, while passwords and codes still fill

### Requirement: Same signing rules as the browser extension
New passkeys MUST be ES256 on P-256 with an all-zero AAGUID, `none` attestation and a 16-byte random credential id. An assertion MUST sign with flags UP and UV and a DER signature. A non-zero counter MUST go up by one and be written back: for a standalone Passkey item to the item's `key`, for a passkey on a login to that credential in the login's `passkeys`, counted from the login as the server holds it. A request for another algorithm MUST return the platform's "not available" result so another provider can answer.

#### Scenario: Counter goes up
- **GIVEN** a stored passkey with counter 4
- **WHEN** the user signs in with it
- **THEN** the assertion carries counter 5 and the passkey stores 5, in the item's `key` for a Passkey item or on that credential of the login's `passkeys`

#### Scenario: Unsupported algorithm
- **GIVEN** a site that only accepts RS256
- **WHEN** it asks to create a passkey
- **THEN** Keepiq declines and the system offers another provider
