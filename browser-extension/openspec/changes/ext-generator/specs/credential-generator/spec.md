## ADDED Requirements

### Requirement: Generator tab with three sub-tabs
The extension SHALL show a Generator tab in the popup shell with the sub-tabs Password, Passphrase and Username, opening on the sub-tab the account last used (Password on first use), and SHALL generate a value immediately when the tab opens and again whenever an option changes, as Bitwarden does. The last used sub-tab is stored with the generator options in `storage.local` under the account's settings and is cleared on account removal.

#### Scenario: Opening the tab generates a value
- **GIVEN** an unlocked account that has never used the generator
- **WHEN** the user opens the Generator tab
- **THEN** the Password sub-tab is selected
- **AND** a 14-character password is shown in the output box without any further click

#### Scenario: Changing an option regenerates
- **GIVEN** the Password sub-tab shows a value
- **WHEN** the user moves the length slider to 20
- **THEN** a new 20-character value replaces the shown one

#### Scenario: Last sub-tab is remembered per account
- **GIVEN** the user last used the Passphrase sub-tab on account A and the Username sub-tab on account B
- **WHEN** the user switches to account B and opens the Generator tab
- **THEN** the Username sub-tab is selected

### Requirement: Password options and defaults
The Password sub-tab SHALL offer a length slider paired with a number input (default 14, range 5 to 128), toggles for uppercase A-Z (on), lowercase a-z (on), numbers 0-9 (on) and special characters `!@#$%^&*` (off), numeric inputs "Minimum numbers" (default 1, range 0 to 9) and "Minimum special" (default 1, range 0 to 9), and an "Avoid ambiguous characters" toggle (on) that removes `I`, `O`, `l`, `0` and `1` from their sets, matching Bitwarden's defaults. At least one character class MUST stay enabled, and the length MUST be raised to the sum of the minimums when the minimums exceed it.

#### Scenario: Last enabled class cannot be turned off
- **GIVEN** only the lowercase toggle is on
- **WHEN** the user tries to turn lowercase off
- **THEN** the toggle stays on
- **AND** the other toggles remain available

#### Scenario: Minimums raise the length
- **GIVEN** length 5 with minimum numbers 4 and minimum special 4 and both classes enabled
- **WHEN** the user leaves the minimum special input
- **THEN** the length control shows 8
- **AND** the generated value is 8 characters long

#### Scenario: Ambiguous characters are avoided by default
- **GIVEN** default options
- **WHEN** the user generates 200 passwords
- **THEN** none of them contains `I`, `O`, `l`, `0` or `1`

### Requirement: Password generation guarantees
Generated passwords SHALL have exactly the requested length, SHALL contain at least the requested minimum count of numbers and of special characters when those classes are enabled, SHALL contain at least one character of every enabled class and none of any disabled class, and SHALL be drawn with `crypto.getRandomValues` through an unbiased integer sampler with the required characters placed at shuffled positions. Generation MUST never call the server.

#### Scenario: Minimum counts are met
- **GIVEN** length 14, numbers and special enabled, minimum numbers 3 and minimum special 2
- **WHEN** a password is generated
- **THEN** it contains at least three digits and at least two characters from `!@#$%^&*`

#### Scenario: Disabled class never appears
- **GIVEN** special characters are off
- **WHEN** a password is generated
- **THEN** it contains no character from `!@#$%^&*`

#### Scenario: Enabled class with minimum 0 still appears once
- **GIVEN** numbers enabled with "Minimum numbers" set to 0
- **WHEN** a password is generated
- **THEN** it contains at least one digit, as in Bitwarden

### Requirement: Passphrase options and generation
The Passphrase sub-tab SHALL offer "Number of words" (default 3, range 3 to 20), "Word separator" (default `-`), "Capitalize" (off) and "Include number" (off). A passphrase SHALL consist of the requested number of words drawn uniformly from the bundled EFF long wordlist, joined by the separator; Capitalize upper-cases the first letter of every word and Include number appends one random digit to exactly one randomly chosen word, as Bitwarden does.

#### Scenario: Default passphrase
- **GIVEN** default passphrase options
- **WHEN** a passphrase is generated
- **THEN** it has three lowercase words from the wordlist separated by `-`
- **AND** it contains no digit

#### Scenario: Include number appends one digit
- **GIVEN** "Include number" is on and 4 words
- **WHEN** a passphrase is generated
- **THEN** exactly one of the four words ends in a single digit
- **AND** the other three words contain no digit

#### Scenario: Empty separator is accepted
- **GIVEN** the separator input is cleared
- **WHEN** a passphrase is generated
- **THEN** the words are concatenated with nothing between them

### Requirement: Username types and defaults
The Username sub-tab SHALL offer a type select with Random word (default), Plus addressed email and Catch-all email. Random word SHALL draw one word from the bundled wordlist with "Capitalize" (on) and "Include number" (on, appends a four-digit number), as Bitwarden does. Plus addressed email SHALL take an email input seeded from the account's cached Nextcloud email and insert `+` followed by either 8 random lowercase alphanumerics or the website name before the `@`. Catch-all email SHALL take a domain input and produce a local part of 8 random lowercase alphanumerics or the website name. Forwarded email alias is not offered.

#### Scenario: Default username
- **GIVEN** the Username sub-tab opens for the first time
- **WHEN** a value is generated
- **THEN** it is one capitalised wordlist word followed by four digits

#### Scenario: Plus addressed random
- **GIVEN** type Plus addressed email, sub-mode Random, and email `user@example.com`
- **WHEN** a value is generated
- **THEN** it matches `user+<8 lowercase alphanumerics>@example.com`

#### Scenario: Account without an email
- **GIVEN** the cached Nextcloud user record has no email
- **WHEN** the user selects Plus addressed email
- **THEN** the email input is empty with a placeholder
- **AND** the output box shows "Enter an email address" instead of a value

#### Scenario: Catch-all with website name
- **GIVEN** type Catch-all email, sub-mode Website name, domain `example.com`, and the active tab is on `https://login.example.org/`
- **WHEN** a value is generated
- **THEN** it is `login.example.org@example.com`

### Requirement: Website name source
The Website name sub-modes SHALL use the hostname of the active tab in the current window, read by the background at the moment the generator context is requested. When no hostname is available (no active tab, a browser-internal page, or the popup was opened while locked from a context without a tab) the Website name sub-mode SHALL be disabled with the hint "No website detected" and the sub-mode falls back to Random.

#### Scenario: Browser-internal page
- **GIVEN** the active tab shows a browser settings page
- **WHEN** the user opens the Username sub-tab with type Plus addressed email
- **THEN** the Website name sub-mode is disabled with "No website detected"
- **AND** Random is selected

### Requirement: Output box, Regenerate and Copy
The output box SHALL show the current value in a monospace font with digits and special characters in two distinct colours and letters in the default colour, as Bitwarden does. A Regenerate button SHALL produce a new value with the current options. A Copy button SHALL copy the value to the clipboard and SHALL schedule the clipboard clear when the clipboard-clear setting is configured. Copying a value that is longer than the box SHALL copy the whole value, not the visible part.

#### Scenario: Copy respects the clipboard-clear setting
- **GIVEN** the clipboard-clear setting is 30 seconds
- **WHEN** the user clicks Copy
- **THEN** the value is on the clipboard
- **AND** the clipboard is cleared 30 seconds later if it still holds that value

#### Scenario: Copy without a clear setting
- **GIVEN** the clipboard-clear setting is off
- **WHEN** the user clicks Copy
- **THEN** the value is on the clipboard and nothing is scheduled

### Requirement: Pick mode from the item form
When the item form opens the generator in pick mode, the generator SHALL show a "Use this password" button in addition to Regenerate and Copy, SHALL keep the same options and history behaviour, and on click SHALL return the current value to the form field that requested it and navigate back to the form. Passphrases and usernames returned this way go to the field that requested them (login field for usernames, password field for the other two). Pick mode is never offered while locked, because there is no item form.

#### Scenario: Use this password
- **GIVEN** the item form's password field opened the generator in pick mode
- **WHEN** the user clicks "Use this password"
- **THEN** the form is shown again with the value in its password field
- **AND** the form's other fields are unchanged

#### Scenario: Leaving without picking
- **GIVEN** the generator is open in pick mode
- **WHEN** the user navigates back without clicking "Use this password"
- **THEN** the form's password field keeps its previous content

### Requirement: Generator history
The extension SHALL keep the last 50 generated values for the account in `storage.session` (background memory on Firefox below 115), each with its type and generation time, adding an entry on every generation. History SHALL be cleared on lock, logout, account removal, extension reload and browser restart, and is never written to `storage.local`. A History view SHALL list the entries newest first with the value in the same colour-coded monospace style, the relative time, a Copy button per entry and a Clear button.

#### Scenario: Oldest entry drops out
- **GIVEN** the history holds 50 entries
- **WHEN** a new value is generated
- **THEN** the history holds 50 entries with the new value first and the oldest one gone

#### Scenario: Lock clears history
- **GIVEN** the history holds entries
- **WHEN** the vault locks by timeout
- **THEN** the History view shows the empty state "No generated values yet"

#### Scenario: Clear button
- **GIVEN** the History view lists entries
- **WHEN** the user clicks Clear and confirms
- **THEN** the list is empty and `storage.session` holds no history for the account

### Requirement: Options remembered per account
The extension SHALL store the generator options (all Password, Passphrase and Username settings, including the plus addressed email and catch-all domain inputs, and the last used sub-tab) in `storage.local` under the account's settings, save them on every change, and load them when the Generator tab opens. Stored options SHALL be sanitised against the ranges in this spec and against the active policy before use. Options are cleared on account removal only.

#### Scenario: Options survive a popup close
- **GIVEN** the user set length 24 and enabled special characters
- **WHEN** the popup is closed and reopened on the Generator tab
- **THEN** the length control shows 24 and special characters are on

#### Scenario: Out-of-range stored value is sanitised
- **GIVEN** `storage.local` holds a length of 300 for the account
- **WHEN** the Generator tab opens
- **THEN** the length control shows 128 and the generated value is 128 characters long

### Requirement: Organisation policy clamp
The background SHALL fetch `GET /api/settings/policy` (ADR-003) on unlock and on every sync and cache the response in `storage.local` under the account, replacing the previous copy only on a successful response. When `policy_enabled` is true the Password sub-tab SHALL clamp the length minimum to `generator_min_length` and force each class named by `generator_require_upper`, `generator_require_lower`, `generator_require_digit` and `generator_require_symbol` on with its toggle disabled and its minimum count at least 1, showing "Set by your organisation" next to every clamped control. When `policy_enabled` is false or no policy is cached the sub-tab is unclamped. The other policy fields are ignored by the generator. The cached policy is cleared on logout and account removal.

#### Scenario: Policy forces length and symbols
- **GIVEN** the cached policy has `policy_enabled` true, `generator_min_length` 20 and `generator_require_symbol` true
- **WHEN** the user opens the Password sub-tab
- **THEN** the length slider cannot go below 20 and shows "Set by your organisation"
- **AND** the special characters toggle is on and disabled with the same label
- **AND** "Minimum special" cannot go below 1

#### Scenario: Stored options below the policy are raised
- **GIVEN** the account's stored length is 12 and the cached policy requires 16
- **WHEN** the Generator tab opens
- **THEN** the length control shows 16 and the stored options are updated to 16

#### Scenario: Policy disabled by the admin
- **GIVEN** a sync returns a policy with `policy_enabled` false
- **WHEN** the user opens the Password sub-tab
- **THEN** no control is clamped and no "Set by your organisation" label is shown

#### Scenario: Policy fetch fails
- **GIVEN** a cached policy requiring length 16
- **WHEN** the next sync's policy request fails with a network error or a 5xx status
- **THEN** the cached policy is kept and the clamp still applies

### Requirement: Works while locked and offline
The Generator tab SHALL be reachable from the lock screen through a "Generate a password" link and SHALL generate, show history, copy and save options while the vault is locked and while the server is unreachable, using the cached policy. No generation path SHALL depend on the private key, the network or the server generator endpoint.

#### Scenario: Locked generation
- **GIVEN** the vault is locked
- **WHEN** the user follows "Generate a password" on the lock screen and clicks Regenerate
- **THEN** a value is generated and can be copied
- **AND** no request is sent to the server

#### Scenario: Offline with a cached policy
- **GIVEN** the server is unreachable and a policy requiring length 16 is cached
- **WHEN** the user opens the Password sub-tab
- **THEN** the length is clamped to 16 with "Set by your organisation"

### Requirement: Bundled wordlist
The extension SHALL bundle the EFF long wordlist (7776 words) as a static resource loaded on first use of the Passphrase or Random word generator and kept in popup memory for the popup's lifetime, together with its CC BY 3.0 attribution file. When the resource cannot be loaded the Passphrase and Random word generators SHALL show "Wordlist unavailable" instead of a value and the other generators stay usable.

#### Scenario: Wordlist loads once
- **GIVEN** the popup is open
- **WHEN** the user generates five passphrases
- **THEN** the wordlist resource is fetched once

#### Scenario: Wordlist load failure
- **GIVEN** the wordlist resource fails to load
- **WHEN** the user opens the Passphrase sub-tab
- **THEN** the output box shows "Wordlist unavailable"
- **AND** the Password sub-tab still generates values
