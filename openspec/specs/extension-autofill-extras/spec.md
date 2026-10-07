# extension-autofill-extras Specification

## Purpose
Filling and saving beyond the popup: fields in shadow roots, the context menu and shortcut, never-save sites and a folder on save.

## Requirements

### Requirement: Find fields in shadow roots and by their label
The content script MUST look for login fields in the page and in its open shadow roots. It MUST skip a hidden field, a disabled or read-only one, and one under `aria-hidden`. When no field is marked as a username by its type, autocomplete, name or id, a text field whose label, `aria-labelledby`, `aria-label` or placeholder names a user, e-mail, login or account counts as the username; failing that, the text field right before the password field.

#### Scenario: A web component and a labelled field
@e2e exclude Content-script field detection on a jsdom document. Covered by tests/extension/autofillExtras.spec.js ("finds the fields inside an open shadow root") and ("skips a field under aria-hidden and a hidden one, and knows a username by its label").
- **GIVEN** a login form inside an open shadow root, or one with a decoy field under aria-hidden and a username field known only by its label "E-mailadres"
- **WHEN** the fields are looked for
- **THEN** the real username and password fields are found

### Requirement: Fill from the context menu and a shortcut
The right-click menu of a form field MUST offer "Fill a login with Keepiq", and a keyboard shortcut (Ctrl+Shift+L, Command+Shift+L on a Mac, changeable in the browser) MUST do the same. When the site has exactly one login and the vault is unlocked, it fills at once, with the same frame and site checks as a fill from the popup. A locked vault, several logins or an https login on an http page MUST open the popup instead. Settings MUST show the current shortcut. When a fill from the popup finds no form, the popup MUST say so and stay open.

#### Scenario: One login for the site
@e2e exclude Browser-extension worker events. Covered by tests/extension/autofillExtras.spec.js ("adds the menu item on install") and ("fills the only login for the site from the menu and from the shortcut").
- **GIVEN** an unlocked vault with one login for the site
- **WHEN** the user picks the menu item, or presses the shortcut
- **THEN** the login fills without the popup

#### Scenario: Locked or several logins
@e2e exclude Browser-extension worker. Covered by tests/extension/autofillExtras.spec.js ("opens the popup instead when the vault is locked or the site has several logins").
- **GIVEN** a locked vault, or two logins for the site
- **WHEN** the user presses the shortcut
- **THEN** the popup opens and nothing fills

#### Scenario: No form on the page
@e2e exclude Browser-extension popup. Covered by tests/extension/autofillExtras.spec.js ("says so when no form was filled, and stays open").
- **GIVEN** a page where no frame finds a login form
- **WHEN** the user picks a login in the popup
- **THEN** the popup says "Keepiq found no login form on this page to fill." and stays open

### Requirement: Never offer to save on a site
The save offer for a new login, in the page and in the popup, MUST offer "Never for this site". A site on that list MUST get no save offer. Settings MUST list those sites, each with a way to take it off the list.

#### Scenario: Never, then again
@e2e exclude Browser-extension worker, popup and in-page bar. Covered by tests/extension/autofillExtras.spec.js ("offers Never for this site on a new login only, and answers never"), ("never offers to save on a site the user excluded, until they take it off the list") and ("offers a folder and Never in the save prompt, and lists the excluded sites in Settings").
- **GIVEN** a login submitted on new.example
- **WHEN** the user picks Never for this site
- **THEN** the next submit there gets no offer, until the user removes the site in Settings

### Requirement: Save a new login into a folder
The popup's save offer for a new login MUST offer the vault's folders, "No folder" first, and save into the folder chosen. An update keeps the login where it is.

#### Scenario: Pick a folder
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/autofillExtras.spec.js ("saves a new login into the folder the user picked") and ("offers a folder and Never in the save prompt, and lists the excluded sites in Settings").
- **GIVEN** a held new login and a folder Work
- **WHEN** the user saves it into Work
- **THEN** the server receives it with that folder
