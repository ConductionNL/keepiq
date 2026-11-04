## ADDED Requirements

### Requirement: Authenticator key field
The add and edit form of a Login SHALL show an Authenticator key field, a masked input with Show, labelled "Authenticator key (optional)", with the help text "Paste the otpauth://totp/ link or the secret key, or scan the QR code on the page." It takes an `otpauth://totp/` link or a base32 secret. On Save a link is trimmed and a bare secret loses its whitespace and is upper-cased, so a key copied in groups is saved as one; a key `parseTotpSeed()` cannot read MUST refuse Save with "Not a valid authenticator secret" on the field and send no request. An empty field is no key. The typed or scanned key lives in popup memory, in the form draft, and is cleared when the form or the popup closes.

#### Scenario: A key in groups
- **GIVEN** the user adds a login and types `jbsw y3dp ehpk 3pxp` as the Authenticator key
- **WHEN** they save
- **THEN** the create request MUST carry `totp` as the encryption of `JBSWY3DPEHPK3PXP`

#### Scenario: A key that is not one
- **GIVEN** the user typed `not a key!` as the Authenticator key
- **WHEN** they press Save
- **THEN** Save MUST be refused with "Not a valid authenticator secret" on the field and no request MUST be sent

#### Scenario: No key
- **GIVEN** the user adds a login and leaves the Authenticator key empty
- **WHEN** they save
- **THEN** the create request MUST NOT carry `totp`

### Requirement: Scan QR code on page
Next to the Authenticator key input the form SHALL offer "Scan QR code on page". It MUST take a picture of the visible part of the tab the toolbar popup opened over with `tabs.captureVisibleTab`, under the `activeTab` permission, read a QR code from it in the popup, and put the text in the field when it is an `otpauth://totp/` link `parseTotpSeed()` reads, announcing "Authenticator key read from the QR code" in a status line. The picture MUST NOT be stored, logged or sent anywhere, and MUST be dropped once read. Otherwise the field MUST show, as its error:
- no QR code in the picture: "No QR code found on the page. Scroll it into view and try again.";
- a QR code holding anything else: "This QR code does not hold an authenticator key. Use the QR code the site shows when you set up two-factor sign-in.";
- the picture could not be taken, as on a browser page or on Firefox before 126: "Keepiq cannot take a picture of this page. Paste the key instead."
In the pop-out window the button MUST be disabled with "Open Keepiq from the toolbar on the page with the QR code to scan it." A scan replaces what the field held. No content script takes part.

#### Scenario: Scanning the test site's setup page
- **GIVEN** the user is on `test-site/totp-setup.html` and opened the popup from the toolbar
- **WHEN** they open their login for the test site, press Edit, then Scan QR code on page
- **THEN** the Authenticator key field MUST hold `otpauth://totp/Keepiq%20test%20site:test-user?secret=JBSWY3DPEHPK3PXP&issuer=Keepiq%20test%20site`, masked
- **AND** no network request MUST be made for the scan

#### Scenario: A QR code with a website address
- **GIVEN** the tab shows `test-site/qr-website.html`, whose QR code holds `https://example.com/`
- **WHEN** the user presses Scan QR code on page
- **THEN** the field MUST show "This QR code does not hold an authenticator key. Use the QR code the site shows when you set up two-factor sign-in." and no key MUST be set

#### Scenario: QR code scrolled out of view
- **GIVEN** the setup page's QR code is below the visible part of the tab
- **WHEN** the user presses Scan QR code on page
- **THEN** the field MUST show "No QR code found on the page. Scroll it into view and try again."

#### Scenario: Pop-out window
- **GIVEN** the add form is open in the pop-out window
- **WHEN** it renders
- **THEN** Scan QR code on page MUST be disabled with "Open Keepiq from the toolbar on the page with the QR code to scan it."
- **AND** pasting a key MUST still work

### Requirement: A saved key stays hidden
`item.fetch` SHALL answer `totpSaved: true` for a row whose `totp` is non-empty ciphertext and MUST NOT decrypt it or send the seed to the popup. The edit form of such a row MUST show the Authenticator key as a field reading "Saved", with a trash button named "Remove authenticator key" in it, instead of the input, and never the seed:
- On a Login, the trash brings back the input, labelled "New authenticator key", with Scan QR code on page, the help text "Paste or scan a new key, or leave this empty to remove the saved one." and Keep the saved key. Save sends a new key as `totp`, or `''` when the input is left empty.
- On a row of another type, the trash shows "The authenticator key is removed when you save." with Keep the saved key, and no input; Save sends `totp` as `''`.
- Keep the saved key, or leaving it untouched, sends no `totp`.
The trash moves focus to the input, or to Keep the saved key where there is no input; Keep the saved key moves it back to the trash button.

#### Scenario: Editing a login without touching its key
- **GIVEN** a login that holds a seed
- **WHEN** the user changes only its password and saves
- **THEN** the share-save patch MUST NOT carry `totp`
- **AND** the stored seed MUST be unchanged

#### Scenario: Removing the key
- **GIVEN** a login that holds a seed
- **WHEN** the user presses the trash button, leaves the new key empty and saves
- **THEN** the patch MUST carry `totp` as `''`
- **AND** its detail view MUST show no code

#### Scenario: Replacing the key
- **GIVEN** a login that holds a seed
- **WHEN** the user presses the trash button, scans a new QR code and saves
- **THEN** the patch MUST carry the new key encrypted as `totp`
- **AND** reopening Edit MUST show the key as "Saved", not the key

#### Scenario: A note with a key
- **GIVEN** a note whose `totp` holds a seed from an import
- **WHEN** the user opens Edit and presses the trash button
- **THEN** the form MUST say the key is removed when saving, and offer no input for a new one

#### Scenario: The seed never reaches the popup
- **GIVEN** a login that holds a seed
- **WHEN** its edit form opens
- **THEN** no message to the popup MUST contain the seed, and the DOM MUST NOT contain it

### Requirement: The key is saved encrypted
The background SHALL normalise and check a `totp` from the popup again before it encrypts it, and MUST encrypt it like `key` (ADR-003). On create it MUST send `totp` only for a `login` draft with a key, and refuse `totp` on another type with `invalid`. On update a non-empty `totp` MUST be refused with `invalid` unless the cached row is a `login`; `''` is taken on any type. A key that does not read MUST be refused with `invalid` before any request. The key in plain lives in background memory only for the save; `storage.local` and `storage.session` hold it only as ciphertext. The field is in Keepiq 0.3.12, the minimum, so the save is not gated.

#### Scenario: Create a login with a key
- **GIVEN** a login draft with Authenticator key `JBSWY3DPEHPK3PXP`
- **WHEN** the user saves
- **THEN** `POST /api/v1/secrets` MUST carry `totp` as an RSA blob that decrypts to `JBSWY3DPEHPK3PXP`
- **AND** after the sync the detail view MUST show the login's code

#### Scenario: A key on a type that takes none
- **GIVEN** an `item.create` from the popup whose draft is a note with `totp`
- **WHEN** the background handles it
- **THEN** it MUST answer `invalid` and send no request

#### Scenario: No plaintext left behind
- **GIVEN** the user saved a login with a key
- **WHEN** the save has finished
- **THEN** neither `storage.local` nor `storage.session` MUST hold the key in plain

### Requirement: A clone leaves the key out
`item.fetch` for a clone SHALL answer `totpSaved: false`, with `totpLeftOut: true` when the row holds a seed, and send nothing of the key; the clone form MUST show an empty Authenticator key field with the note "The authenticator key is not cloned." when the original holds one. A key typed or scanned into the clone form is saved with the clone.

#### Scenario: Clone a login with a key
- **GIVEN** the detail view of "GitHub", which holds a seed
- **WHEN** the user presses Clone and saves the clone unchanged
- **THEN** the create request MUST NOT carry `totp`
- **AND** the original MUST keep its seed
