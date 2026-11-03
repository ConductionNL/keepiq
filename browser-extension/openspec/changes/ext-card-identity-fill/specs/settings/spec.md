## MODIFIED Requirements

### Requirement: Autofill settings entries
The extension SHALL store per account the entries Autofill on page load (default off, with the warning "Compromised or untrusted websites can exploit autofill on page load"), Default autofill setting for login items (Autofill on page load if enabled, default, or Never), Default URI match detection (Base domain, default; Host; Starts with; Exact; Regular expression; Never), Show autofill suggestions on form fields (default on) with, beneath it, Show cards as suggestions (nl "Kaarten als suggesties tonen", default on) and Show identities as suggestions (nl "Identiteiten als suggesties tonen", default on), Always show cards as Autofill suggestions on Vault view (nl "Kaarten altijd als invulsuggesties tonen in de kluisweergave", default on), Always show identities as Autofill suggestions on Vault view (nl "Identiteiten altijd als invulsuggesties tonen in de kluisweergave", default on) and Enable context menu options (default on). Show cards as suggestions and Show identities as suggestions are disabled, keeping their stored value, while Show autofill suggestions on form fields is off. Each change is stored immediately and read by ext-autofill and ext-card-identity-fill on their next use.

ext-autofill delivers the behaviour behind these entries and behind Ask to add login, Ask to update existing login and Excluded domains; ext-card-identity-fill delivers the four card and identity entries. Until the background reports an entry's behaviour as delivered, the entry is shown disabled with "Coming later" and its stored value and default are left untouched.

#### Scenario: Entries not yet delivered
- **GIVEN** the background reports no autofill behaviour as delivered and a stored Enable context menu options of off
- **WHEN** the Autofill view renders
- **THEN** every entry above is disabled with "Coming later"
- **AND** Enable context menu options still shows off and stays stored as off

#### Scenario: Enabling autofill on page load
- **GIVEN** fill on page load is delivered and Autofill on page load is off
- **WHEN** the user turns it on
- **THEN** the warning text is shown beneath the toggle and the Default autofill setting entry becomes enabled

#### Scenario: Regular expression match chosen
- **GIVEN** URI matching is delivered
- **WHEN** the user selects Regular expression as the default URI match
- **THEN** the note "Regular expressions are an advanced option and can break matching" is shown and the value is stored

#### Scenario: Card suggestions under the inline list setting
- **GIVEN** card and identity fill delivered and Show autofill suggestions on form fields turned off
- **WHEN** the Autofill view renders
- **THEN** Show cards as suggestions and Show identities as suggestions are disabled and keep their stored values

#### Scenario: Per account
- **GIVEN** two accounts, Show cards as suggestions off for the first
- **WHEN** the user switches to the second
- **THEN** the second account's own value applies to the inline list
