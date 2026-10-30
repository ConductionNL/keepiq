## ADDED Requirements

### Requirement: Passkeys in the list during a conditional request
While a conditional `get` ceremony (`passkey-provider`) is registered for the list's frame and "Show autofill suggestions on form fields" is on, the list SHALL show the passkeys offered for that request above the logins, each row showing the item's name and the passkey's user name after a decorative key icon hidden from assistive technology, as an option named "<name>, <user name>, passkey" in the same listbox. A field whose `autocomplete` contains `webauthn` qualifies while such a ceremony is pending, even when the field classification would not qualify it. Passkey rows count toward the five visible rows and the height, as logins do, so the page can count matches only after the user focused or clicked the field. The rows come from the background in the `inline.state` reply as item id, name and user name only; the list page holds them while open and nowhere else. Picking a passkey row goes through `inline.pick` with the same offered-in-this-list, visibility and unlock checks as a login, then signs as the passkey window does; no credential reaches the content script but the assertion it relays to the page. With a locked vault the list keeps its locked state and shows no passkey; with no passkey and no login it shows "No logins or passkeys for <host>".

#### Scenario: Conditional sign-in
- **GIVEN** an unlocked vault with passkey "Example" (user `alice`) for `example.test` and a page that called `get({ mediation: 'conditional' })`
- **WHEN** the user focuses the `autocomplete="username webauthn"` field and picks "Example, alice, passkey"
- **THEN** the page's pending `get` resolves with the assertion and the list closes

#### Scenario: No conditional request
- **GIVEN** the same vault on a page that made no passkey request
- **WHEN** the user focuses its username field
- **THEN** the list shows logins only

#### Scenario: Locked with a conditional request
- **GIVEN** a locked vault and a pending conditional `get`
- **WHEN** the user focuses the field
- **THEN** the list reads "Unlock Keepiq to see your logins" with "Unlock", and no passkey row
- **AND** after unlocking, focusing the field again lists the passkey

#### Scenario: Suggestions turned off
- **GIVEN** "Show autofill suggestions on form fields" off and a pending conditional `get`
- **WHEN** the user focuses the field
- **THEN** no list opens and the browser's own conditional request keeps running
