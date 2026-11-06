## MODIFIED Requirements

### Requirement: Which fields open the list
The content script SHALL decide whether a field qualifies, and in which mode, from the page's DOM only, by the field classification of the `autofill` capability:
- **Login mode:** a field classified as the username or the current password of a group classified as a login qualifies, with or without a `<form>`, including a username-only or password-only step.
- **Code mode:** a field of a code group (`totp-autofill`, Code fields) qualifies, any input of a split group included; the list then shows the logins with a code as `totp-autofill` specifies.

Fields of sign-up and password-change groups, fields classified as a new password (confirm fields included), recovery and backup code fields, and hidden, disabled and read-only fields MUST NOT qualify. `autocomplete="off"` is ignored, as that classification specifies. The list opens on any focus of an empty qualifying field, including focus set by page script or `autofocus` and a field already focused when the content script starts, and on a trusted click in a qualifying field, empty or not. Opening MUST NOT move focus out of the field. The content script tells the background the mode with `inline.open`; the mode only chooses what the list shows, and matching stays on `sender.url`.

#### Scenario: Focus on a login field
- **GIVEN** the setting on and a login page
- **WHEN** the user tabs into the empty username field
- **THEN** the list opens beneath it
- **AND** focus and the caret stay in the username field

#### Scenario: Field focused before the content script starts
- **GIVEN** a login page whose username field has `autofocus`
- **WHEN** the content script starts after the field already has focus
- **THEN** the list opens beneath it

#### Scenario: Sign-up form
- **GIVEN** a sign-up form with a username, a new-password and a confirm field
- **WHEN** the user focuses the username, the new-password or the confirm field
- **THEN** no list opens

#### Scenario: Login without a form: list opens
- **GIVEN** a username and a password field and a `type="button"` "Sign in" button with no `<form>` around them
- **WHEN** the user focuses the empty username field
- **THEN** the list opens beneath it

#### Scenario: Formless login and sign-up on one page
- **GIVEN** no `<form>` on the page, a login panel with a username, a password and "Sign in", and a sign-up panel with a username, a new password, a confirm field and "Create account"
- **WHEN** the user focuses each empty field in turn
- **THEN** the list opens on the login panel's username and password fields only

#### Scenario: Newsletter form
- **GIVEN** a form with only an "Email" field and a "Subscribe" button
- **WHEN** the user focuses the empty email field
- **THEN** no list opens

#### Scenario: Login field with autocomplete off
- **GIVEN** a login form with `autocomplete="off"` on the form and its username field
- **WHEN** the user focuses the empty username field
- **THEN** the list opens beneath it

#### Scenario: Login password mislabelled new-password
- **GIVEN** a form with a username field, one password field marked `autocomplete="new-password"` and a "Log in" button
- **WHEN** the user focuses the empty password field
- **THEN** the list opens beneath it

#### Scenario: One-time code field
- **GIVEN** a text field named `code` with `inputmode="numeric"`, `maxlength="6"` and `autocomplete="one-time-code"`
- **WHEN** it receives focus
- **THEN** it is classified as a one-time code and the list opens beneath it in code mode

#### Scenario: Split code field
- **GIVEN** six one-character inputs forming a code group
- **WHEN** the user focuses the empty first input
- **THEN** the list opens beneath it in code mode

#### Scenario: Recovery code field
- **GIVEN** a field labelled "Backup code"
- **WHEN** it receives focus
- **THEN** no list opens

#### Scenario: Filled field
- **GIVEN** a username field that already holds a value
- **WHEN** it receives focus
- **THEN** no list opens
- **AND** a click in the field opens it

#### Scenario: Disabled field
- **GIVEN** a disabled or read-only password field
- **WHEN** it is clicked
- **THEN** no list opens
