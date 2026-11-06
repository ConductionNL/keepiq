## ADDED Requirements

### Requirement: Default password manager entry
On Chromium the Autofill view SHALL show the toggle "Make Keepiq your default password manager" (nl "Maak Keepiq je standaard wachtwoordbeheerder") with the description "Turns off the browser's own password saving and address autofill, so its suggestions don't cover Keepiq's." (nl "Zet het opslaan van wachtwoorden en het invullen van adressen in de browser uit, zodat de suggesties ervan die van Keepiq niet bedekken."). It is off by default. On Firefox it is not shown, nor on a Chromium without `privacy.services.autofillAddressEnabled`, where the background gives the permission back. Nothing is stored: the toggle is on when the extension holds the `privacy` permission and both `privacy.services.passwordSavingEnabled` and `privacy.services.autofillAddressEnabled` read `false` with `levelOfControl` `controlled_by_this_extension`, or one does while the other is managed elsewhere (Settings controlled elsewhere), and it is read again each time the view opens.

#### Scenario: Firefox
- **GIVEN** the extension on Firefox
- **WHEN** the Autofill view renders
- **THEN** no default password manager entry is shown

#### Scenario: Fresh install on Chrome
- **GIVEN** a new install on Chrome
- **WHEN** the Autofill view renders
- **THEN** the toggle is shown off with its description

### Requirement: Turning it on
Turning the toggle on SHALL request the optional `privacy` permission from the popup, in the user's gesture. When granted, the background sets both settings to `false` on `permissions.onAdded`, since Chrome closes the popup for the prompt, and the toggle shows on once both read as controlled by this extension. When the user declines the permission the toggle stays off and nothing is set. The browser returns both settings to the user's own choices when the extension is uninstalled.

#### Scenario: Permission granted
- **GIVEN** the toggle off on Chrome
- **WHEN** the user turns it on and allows the permission, the popup having closed for the prompt
- **THEN** `passwordSavingEnabled` and `autofillAddressEnabled` read `false`, controlled by this extension
- **AND** the toggle shows on when the popup is opened again

#### Scenario: Permission declined
- **GIVEN** the toggle off on Chrome
- **WHEN** the user turns it on and declines the permission
- **THEN** the toggle stays off and neither setting is changed

### Requirement: Turning it off
Turning the toggle off SHALL clear each setting this extension controls, so it follows the user's own browser choice again, and then remove the `privacy` permission, also when a clear fails. The toggle then shows off, enabled and without a note, since without the permission the settings cannot be read.

#### Scenario: Turned off
- **GIVEN** the toggle on
- **WHEN** the user turns it off
- **THEN** both settings are cleared, their `levelOfControl` is no longer `controlled_by_this_extension`, and the extension no longer holds `privacy`

### Requirement: Settings controlled elsewhere
When either setting's `levelOfControl` is `not_controllable` or `controlled_by_other_extensions`, the toggle SHALL show "Your organisation or another extension manages this browser setting." (nl "Je organisatie of een andere extensie beheert deze browserinstelling."), and nothing is requested or set. While the other setting is still controlled by this extension, the toggle shows on and stays enabled, so turning it off clears that one and removes the permission; otherwise it is shown off and disabled. This is checked before the permission is requested, through the background, which can read the settings once the permission is held, and shows the toggle enabled while it is not.

#### Scenario: Managed by policy
- **GIVEN** the extension holds `privacy` and Chrome's password manager is set by enterprise policy
- **WHEN** the Autofill view renders
- **THEN** the toggle is disabled and shows the managed note

#### Scenario: Managed after Keepiq took control
- **GIVEN** the toggle on, and an enterprise policy then sets Chrome's password manager
- **WHEN** the Autofill view renders and the user turns the toggle off
- **THEN** the toggle was shown on, enabled, with the managed note
- **AND** `autofillAddressEnabled` is cleared and the extension no longer holds `privacy`
