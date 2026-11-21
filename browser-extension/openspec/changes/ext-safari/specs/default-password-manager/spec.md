## MODIFIED Requirements

### Requirement: Default password manager entry
On Chromium the Autofill view SHALL show the toggle "Make Keepiq your default password manager" (nl "Maak Keepiq je standaard wachtwoordbeheerder") with the description "Turns off the browser's own password saving and address autofill, so its suggestions don't cover Keepiq's." (nl "Zet het opslaan van wachtwoorden en het invullen van adressen in de browser uit, zodat de suggesties ervan die van Keepiq niet bedekken."). It is off by default. On Firefox and Safari it is not shown, and their manifests do not declare `privacy`, nor on a Chromium without `privacy.services.autofillAddressEnabled`, where the background gives the permission back. Nothing is stored: the toggle is on when the extension holds the `privacy` permission and both `privacy.services.passwordSavingEnabled` and `privacy.services.autofillAddressEnabled` read `false` with `levelOfControl` `controlled_by_this_extension`, or one does while the other is managed elsewhere (Settings controlled elsewhere), and it is read again each time the view opens.

#### Scenario: Firefox
- **GIVEN** the extension on Firefox
- **WHEN** the Autofill view renders
- **THEN** no default password manager entry is shown

#### Scenario: Safari
- **GIVEN** the extension on Safari, which has no `privacy` API
- **WHEN** the Autofill view renders
- **THEN** no default password manager entry is shown and the background registers no `privacy` listener

#### Scenario: Fresh install on Chrome
- **GIVEN** a new install on Chrome
- **WHEN** the Autofill view renders
- **THEN** the toggle is shown off with its description
