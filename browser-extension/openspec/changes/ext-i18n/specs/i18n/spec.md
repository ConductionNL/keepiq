## ADDED Requirements

### Requirement: Text from the catalog
The extension SHALL take every user-facing text from the message catalog of the active language: visible text, `aria-label`, `title`, `placeholder`, toasts, error messages and the manifest's name, description and toolbar title. Values from the vault or the server, such as item names, folder names and type labels, are shown as they are.

#### Scenario: Dutch browser
- **GIVEN** the browser's UI language is Dutch
- **WHEN** the popup opens on the unlock screen
- **THEN** the labels, the button and the placeholder are in Dutch

#### Scenario: Interpolated value
- **GIVEN** an item named "Bank"
- **WHEN** the vault list renders its launch button
- **THEN** the button's accessible name is the catalog's launch text with "Bank" in its placeholder

### Requirement: Language follows the browser
The extension SHALL use the catalog for the browser's UI language and MUST fall back to English when the browser's language has no catalog or a key is missing. It SHALL offer no language setting of its own.

#### Scenario: Unsupported language
- **GIVEN** the browser's UI language is French
- **WHEN** the popup opens
- **THEN** it is in English

### Requirement: English and Dutch with the same keys
The extension SHALL ship English and Dutch catalogs with exactly the same keys and, per key, the same placeholders. A test MUST fail when they differ.

#### Scenario: Key missing in Dutch
- **GIVEN** a key in `en.yml` that `nl.yml` lacks
- **WHEN** the tests run
- **THEN** the parity test fails and names the key

### Requirement: Codes, not text, from the background
The background SHALL send codes to the popup, never display text: a failed action replies with its error code, the key-changed notice with a notice code, and a blocked row with a reason code. The popup MUST turn each code into text from the catalog and show a generic text for a code it does not know. Error messages from the server are not shown.

#### Scenario: Wrong master password
- **GIVEN** the browser's UI language is Dutch
- **WHEN** an unlock fails with a wrong master password
- **THEN** the popup shows the Dutch text for that error

#### Scenario: Server message
- **GIVEN** the server answers 401 with an English `message`
- **WHEN** the popup shows the logged-out state
- **THEN** it shows the catalog's text, not the server's message

### Requirement: Blocked reasons stored as codes
The extension SHALL store the reason a row is blocked as a code (`suite_missing`, `suite_compromised` or `suite_revoked`), so a cached vault shows its reasons in the current language. A stored reason that is not a known code MUST show the generic blocked text.

#### Scenario: Language changed after sync
- **GIVEN** a vault synced while the browser was in English, with a row on a compromised suite
- **WHEN** the browser switches to Dutch and the item is opened offline
- **THEN** the reason is shown in Dutch

#### Scenario: Reason cached before this change
- **GIVEN** a cached row whose reason is an English sentence
- **WHEN** the item is opened
- **THEN** the generic blocked text is shown

### Requirement: Formatting in the browser's language
The extension SHALL format dates, times, relative times and numbers in the browser's UI language, and MUST put counts in the catalog's plural forms.

#### Scenario: Last synced
- **GIVEN** the browser's UI language is Dutch and the vault is offline
- **WHEN** the last sync was five minutes ago
- **THEN** the banner shows the relative time in Dutch
