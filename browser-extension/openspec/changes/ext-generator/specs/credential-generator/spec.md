## ADDED Requirements

### Requirement: Generator tab with three sub-tabs
The extension SHALL fill the popup shell's Generator tab with the sub-tabs Password, Passphrase and Username.
- The sub-tabs SHALL be one full-width segmented tab bar, and each sub-tab's options one card with its checkboxes in a row and paired inputs side by side.
- It SHALL open on the sub-tab the account last used, or Password on first use, or when Passphrase is turned off by the policy.
- It SHALL generate a value when the tab opens, and again whenever an option changes, as Bitwarden does.
- The last used sub-tab is stored with the generator options.
- Quoted UI text in this spec is the English text of a key in `locales/en.yml` and `locales/nl.yml`.

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

### Requirement: Generation follows Keepiq's shared generator
The extension SHALL generate passwords, passphrases and usernames with a port of Keepiq's shared generator (`src/generator/generator.js` and `username.js` in the Keepiq app).
- For the same random source, it SHALL give the same output, or the same refusal, as the shared vectors in `tests/vectors/generator/cases.json`. The regex cases are excluded, since the popup has no regex option.
- Randomness SHALL come from `crypto.getRandomValues` through a rejection-sampled integer source.
- Generation MUST NOT call the server.

#### Scenario: Shared vectors match
- **GIVEN** each non-regex case of the shared generator vectors with its seeded random source
- **WHEN** the extension's generator runs it
- **THEN** the output equals the case's `expected`, or the refusal matches its `error`

#### Scenario: No server call
- **GIVEN** the Generator tab is open
- **WHEN** the user generates 20 values across the three sub-tabs
- **THEN** no request is sent to the server

### Requirement: Password options and defaults
The Password sub-tab SHALL offer:
- a length slider paired with a number input (default 14, range 8 to 128);
- checkboxes for uppercase A-Z (on), lowercase a-z (on), numbers 0-9 (on) and special characters `!@#$%^&*()-_=+[]{}|;:,.<>?/` (off), labelled "A-Z", "a-z", "0-9" and "!@#$%^&*" in one row named "Character types"; the special characters checkbox is named "Special characters, such as !@#$%^&*", since its short label shows only part of the set;
- under that row, "At least one type stays on" for the last enabled class, which refuses to turn off;
- numeric inputs "Minimum numbers" and "Minimum special" side by side (default 1, range 0 to 9);
- an "Avoid ambiguous characters" checkbox (on), which removes `I`, `O`, `l`, `0` and `1`.

At least one character class MUST stay enabled. The length MUST be raised to the sum, over the enabled classes, of each class's minimum, counting at least 1 per enabled class.

#### Scenario: Last enabled class cannot be turned off
- **GIVEN** only the lowercase checkbox is on
- **WHEN** the user tries to turn lowercase off
- **THEN** the checkbox stays on
- **AND** the other checkboxes remain available

#### Scenario: Minimums raise the length
- **GIVEN** all four classes on, length 8, minimum numbers 6 and minimum special 6
- **WHEN** a password is generated
- **THEN** the length control shows 14
- **AND** the password has 14 characters with at least six digits, six special characters, one uppercase and one lowercase letter

#### Scenario: Ambiguous characters are avoided by default
- **GIVEN** default options
- **WHEN** the user generates 200 passwords
- **THEN** none of them contains `I`, `O`, `l`, `0` or `1`

### Requirement: Password generation guarantees
Generated passwords SHALL meet all of these:
- exactly the requested length, after the raise above;
- at least the requested minimum count of numbers and of special characters, when those classes are enabled;
- at least one character of every enabled class;
- no character of a disabled class;
- required characters at positions drawn uniformly, not tied to the start of the value.

#### Scenario: Minimum counts are met
- **GIVEN** length 14, numbers and special enabled, minimum numbers 3 and minimum special 2
- **WHEN** a password is generated
- **THEN** it contains at least three digits and at least two special characters

#### Scenario: Disabled class never appears
- **GIVEN** special characters are off
- **WHEN** a password is generated
- **THEN** it contains no special character

#### Scenario: Enabled class with minimum 0 still appears once
- **GIVEN** numbers enabled with "Minimum numbers" set to 0
- **WHEN** a password is generated
- **THEN** it contains at least one digit

#### Scenario: No positional bias
- **GIVEN** default options
- **WHEN** 20 000 passwords are generated
- **THEN** the share of digits at each position stays within three standard errors of the mean share over all positions

### Requirement: Passphrase options and generation
The Passphrase sub-tab SHALL offer:
- "Number of words" (default 5, range 4 to 12);
- "Word separator" (default `-`);
- "Capitalize" (off);
- "Include number" (off).

A passphrase SHALL consist of the requested number of words, drawn uniformly from the bundled EFF long wordlist without its four hyphenated words, and joined by the separator.
- Capitalize upper-cases the first letter of every word.
- Include number appends one random digit to exactly one randomly chosen word.

#### Scenario: Default passphrase
- **GIVEN** default passphrase options and no policy
- **WHEN** a passphrase is generated
- **THEN** it has five lowercase words from the wordlist separated by `-`
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

#### Scenario: Hyphenated words are never drawn
- **GIVEN** default passphrase options
- **WHEN** 10 000 passphrases are generated
- **THEN** none contains `drop-down`, `felt-tip`, `t-shirt` or `yo-yo`

### Requirement: Username types and defaults
The Username sub-tab SHALL offer a type select with Random word (default), Plus addressed email and Catch-all email. Forwarded email alias is not offered.
- **Random word:** one word from the bundled wordlist, with "Capitalize" (on) and "Include number" (on, appends four digits).
- **Plus addressed email:** takes an email input seeded from the account's cached Nextcloud email, and inserts `+` before the `@`, followed by either 8 random lowercase letters and digits or the website name.
- **Catch-all email:** takes a domain input, and produces a local part of 8 random lowercase letters and digits or the website name.

#### Scenario: Default username
- **GIVEN** the Username sub-tab opens for the first time
- **WHEN** a value is generated
- **THEN** it is one capitalised wordlist word followed by four digits

#### Scenario: Plus addressed random
- **GIVEN** type Plus addressed email, sub-mode Random, and email `user@example.com`
- **WHEN** a value is generated
- **THEN** it matches `user+<8 lowercase letters and digits>@example.com`

#### Scenario: Account without an email
- **GIVEN** the cached Nextcloud user record has no email
- **WHEN** the user selects Plus addressed email
- **THEN** the email input is empty with a placeholder
- **AND** the output box shows "Enter an email address" instead of a value

#### Scenario: Catch-all with website name
- **GIVEN** type Catch-all email, sub-mode Website name, domain `example.com`, and the popup's tab is on `https://login.example.org:8443/`
- **WHEN** a value is generated
- **THEN** it is `login.example.org@example.com`

### Requirement: Website name source
The Website name sub-modes SHALL use the hostname, without a port, of the tab the popup belongs to. For a pop-out window, that is the tab it was opened from. Only http and https tabs count.
- The hostname is never stored.
- When there is no hostname, the Website name sub-mode SHALL be disabled with the hint "No website detected", and Random is used. That covers a browser-internal page and a popup with no tab.

#### Scenario: Browser-internal page
- **GIVEN** the popup's tab shows a browser settings page
- **WHEN** the user opens the Username sub-tab with type Plus addressed email
- **THEN** the Website name sub-mode is disabled with "No website detected"
- **AND** Random is selected

#### Scenario: Pop-out keeps the originating tab
- **GIVEN** the popup was popped out from a tab on `https://shop.example.com/`
- **WHEN** the user generates a catch-all email with Website name in the pop-out window
- **THEN** the local part is `shop.example.com`

### Requirement: Output box, Regenerate and Copy
The output box SHALL show the current value in a monospace font, with digits and special characters in two distinct colours and letters in the default colour.
- A Regenerate button SHALL produce a new value with the current options.
- A Copy button SHALL copy the whole value to the clipboard, and SHALL schedule the clipboard clear unless the clipboard-clear setting is Never.

#### Scenario: Copy respects the clipboard-clear setting
- **GIVEN** the clipboard-clear setting is 30 seconds
- **WHEN** the user clicks Copy
- **THEN** the value is on the clipboard
- **AND** the clipboard is overwritten 30 seconds later

#### Scenario: Copy without a clear setting
- **GIVEN** the clipboard-clear setting is Never
- **WHEN** the user clicks Copy
- **THEN** the value is on the clipboard and nothing is scheduled

### Requirement: Pick mode from the item form
On every credential type (login, database, api_key, ssh_key, certificate and custom types), the item form SHALL show a generate button next to the Username field, and next to the Password field when it is single-line. A multiline secret, such as an SSH key or a certificate, gets no button.
- **The Password button** opens the generator in pick mode on the Password and Passphrase sub-tabs, with a "Use this password" button.
- **The Username button** opens it on the Username sub-tab, with a "Use this username" button.
- **"Use"** returns the current value to the field that asked and shows the form again, with the rest of its input intact.
- **Back** returns to the form and leaves that field as it was.

Pick mode is never offered while locked, because there is no item form.

#### Scenario: Use this password
- **GIVEN** a new `login` item with a name and a username typed
- **WHEN** the user presses the generate button next to Password and clicks "Use this password"
- **THEN** the form is shown again with the value in its password field
- **AND** the name and username are unchanged

#### Scenario: Use this username
- **GIVEN** the edit form of a `database` item
- **WHEN** the user presses the generate button next to Username and clicks "Use this username"
- **THEN** the username field holds the generated value
- **AND** only the Username sub-tab was offered

#### Scenario: Multiline secret has no button
- **GIVEN** the add form with type `ssh_key`
- **WHEN** the form renders
- **THEN** the key field has no generate button
- **AND** the Username field has the generate button

#### Scenario: Leaving without picking
- **GIVEN** the generator is open in pick mode for the password field
- **WHEN** the user navigates back without clicking "Use this password"
- **THEN** the form's password field keeps its previous content

### Requirement: Generator history
The extension SHALL keep the last 50 recorded values for the account, newest first, each with its type and time.
- **Storage:** `storage.session`, or background memory on Firefox below 115. Never `storage.local`.
- **When a value is recorded:** on Regenerate, Copy, "Use this password", "Use this username", and when the user switches to another sub-tab with a value shown. Never on an option change. A value equal to the newest entry is not added again.
- **What clears it:** lock, timeout, logout, account removal, extension reload and browser restart.
- **Only an unlocked account shows it.** For a locked account the background returns no entries and ignores Clear. A value recorded while locked is stored only encrypted to the account's public key and joins the history when it is first read after the unlock, so no stored generated value is readable without unlocking.
- **History view:** lists the entries in the output box's colour-coded monospace style, with the relative time, a Copy button per entry and a Clear button.

#### Scenario: Option changes are not recorded
- **GIVEN** an empty history
- **WHEN** the user drags the length slider across ten values
- **THEN** the history is still empty

#### Scenario: Oldest entry drops out
- **GIVEN** the history holds 50 entries
- **WHEN** the user clicks Regenerate
- **THEN** the history holds 50 entries, with the new value first and the oldest one gone

#### Scenario: Lock clears history
- **GIVEN** the history holds entries
- **WHEN** the vault locks by timeout and the user unlocks again
- **THEN** the History view shows the empty state "No generated values yet"

#### Scenario: Clear button
- **GIVEN** the History view lists entries
- **WHEN** the user clicks Clear and confirms
- **THEN** the list is empty and `storage.session` holds no history for the account

### Requirement: Options remembered per account
The extension SHALL store the generator options in `storage.local` under `generatorOptions.<accountId>`.
- **What is stored:** every Password, Passphrase and Username setting, including the email and domain inputs, and the last used sub-tab.
- **When:** saved on every change, and loaded when the Generator tab opens.
- Stored options SHALL be sanitised against the ranges in this spec.
- They SHALL keep the user's own values whatever the policy, which is applied on read.
- They are kept on logout and removed on account removal.

#### Scenario: Options survive a popup close
- **GIVEN** the user set length 24 and enabled special characters
- **WHEN** the popup is closed and reopened on the Generator tab
- **THEN** the length control shows 24 and special characters are on

#### Scenario: Out-of-range stored value is sanitised
- **GIVEN** `storage.local` holds a length of 300 for the account
- **WHEN** the Generator tab opens
- **THEN** the length control shows 128 and the generated value is 128 characters long

### Requirement: Organisation policy for passwords
The background SHALL fetch `GET /api/settings/policy` (ADR-003) on every sync, which includes the full sync after unlock.
- It caches the result in `storage.local` under `generatorPolicy.<accountId>`, replacing the cached copy only on a 2xx response.
- The cache is removed on logout and account removal.
- The route and every field read exist in every supported Keepiq, so no version gate applies and no older-Keepiq scenario exists.

When `policy_enabled` is true, the Password sub-tab SHALL:
- not let the length go below the floor, `max(8, generator_min_length)` capped at 128;
- lock on each class named by `generator_require_upper`, `generator_require_lower`, `generator_require_digit` and `generator_require_symbol`, with a minimum of at least 1 for a required number or special character;
- label every locked control "Set by your organisation".

The policy is applied when the options are read. Stored options keep the user's own values. When `policy_enabled` is false or no policy is cached, nothing is locked.

#### Scenario: Policy forces length and symbols
- **GIVEN** the cached policy has `policy_enabled` true, `generator_min_length` 20 and `generator_require_symbol` true
- **WHEN** the user opens the Password sub-tab
- **THEN** the length slider cannot go below 20 and shows "Set by your organisation"
- **AND** the special characters checkbox is on and disabled with the same label
- **AND** "Minimum special" cannot go below 1

#### Scenario: The user's own value returns when the policy relaxes
- **GIVEN** the user's stored length is 12 and the cached policy requires 16
- **WHEN** the Generator tab opens, and a later sync replaces the policy with one requiring 8
- **THEN** the length control first shows 16 and then 12
- **AND** the stored options held 12 throughout

#### Scenario: Floor above the maximum
- **GIVEN** a cached policy with `generator_min_length` 200
- **WHEN** a password is generated
- **THEN** it is 128 characters long and no error is shown

#### Scenario: Policy disabled by the admin
- **GIVEN** a sync returns a policy with `policy_enabled` false
- **WHEN** the user opens the Password sub-tab
- **THEN** no control is locked and no "Set by your organisation" label is shown

#### Scenario: Policy fetch fails
- **GIVEN** a cached policy requiring length 16
- **WHEN** the next sync's policy request fails with a network error or a 5xx status
- **THEN** the cached policy is kept and still applies

### Requirement: Organisation policy for passphrases
When `policy_enabled` is true, a passphrase SHALL meet the floor and the required classes, as Keepiq's shared generator does:
- words are added until the floor is met;
- words are capitalised when uppercase is required;
- a digit is added when a number is required;
- a random special character becomes the separator when a symbol is required and the separator is not one.

When `generator_allow_passphrase` is false, the Passphrase sub-tab SHALL be unavailable, with the hint "Turned off by your organisation". Pick mode for a password then offers only the Password sub-tab.

#### Scenario: Floor adds words
- **GIVEN** a cached policy with a floor of 40 and 4 words chosen
- **WHEN** a passphrase is generated
- **THEN** it is at least 40 characters long

#### Scenario: Required symbol becomes the separator
- **GIVEN** a cached policy requiring a symbol, and the separator set to a space
- **WHEN** a passphrase is generated
- **THEN** its words are joined by one special character

#### Scenario: Passphrases turned off
- **GIVEN** a cached policy with `policy_enabled` true and `generator_allow_passphrase` false, and Passphrase as the last used sub-tab
- **WHEN** the user opens the Generator tab
- **THEN** the Password sub-tab is selected
- **AND** the Passphrase sub-tab is disabled with "Turned off by your organisation"

### Requirement: Works while locked and offline
The Generator SHALL be reachable from the lock screen through a "Generate a password" link.
- It SHALL generate, copy and save options while the vault is locked and while the server is unreachable, using the cached policy.
- It SHALL NOT show the history while the vault is locked (see Generator history).
- No generation path SHALL depend on the private key, the network or the server generator endpoint.

#### Scenario: Locked generation
- **GIVEN** the vault is locked
- **WHEN** the user follows "Generate a password" on the lock screen and clicks Regenerate
- **THEN** a value is generated and can be copied
- **AND** no request is sent to the server

#### Scenario: No history while locked
- **GIVEN** a value was generated on the lock screen
- **WHEN** the user opens the generator from the lock screen again
- **THEN** it offers no History, and no history is read from the background

#### Scenario: A value generated while locked shows after the unlock
- **GIVEN** the user copied a value from the lock screen generator
- **WHEN** the user unlocks and opens Generator history
- **THEN** the copied value is the newest entry

#### Scenario: Nothing is kept before the first unlock
- **GIVEN** the account was not unlocked since it was added or last logged in
- **WHEN** the user copies a value from the lock screen generator, then unlocks and opens Generator history
- **THEN** the copied value is not in the history

#### Scenario: Copy while locked keeps no other vault open
- **GIVEN** account A is unlocked and account B, the active one, is locked
- **WHEN** the user copies a value from B's lock screen generator
- **THEN** A's vault timeout is not reset

#### Scenario: Offline with a cached policy
- **GIVEN** the server is unreachable and a policy requiring length 16 is cached
- **WHEN** the user opens the Password sub-tab
- **THEN** the length is locked at 16 or more with "Set by your organisation"

### Requirement: Bundled wordlist
The extension SHALL bundle the EFF long wordlist (7776 words), identical to the Keepiq web app's list, with its CC BY 3.0 US attribution file.
- It is a static resource, loaded on first use of the Passphrase or Random word generator, and kept in popup memory for the popup's lifetime.
- When it cannot be loaded, the Passphrase and Random word generators SHALL show "Wordlist unavailable" instead of a value. The other generators stay usable.

#### Scenario: Wordlist loads once
- **GIVEN** the popup is open
- **WHEN** the user generates five passphrases
- **THEN** the wordlist resource is fetched once

#### Scenario: Wordlist load failure
- **GIVEN** the wordlist resource fails to load
- **WHEN** the user opens the Passphrase sub-tab
- **THEN** the output box shows "Wordlist unavailable"
- **AND** the Password sub-tab still generates values
