# extension-small-items Specification

## Purpose
Smaller behaviour of the extension: match order, new item defaults, form checks, offline folders and keeping the popup's place.

## Requirements

### Requirement: Suggestions by last use
Among logins that match a site equally well, the one used last MUST come first.

#### Scenario: Two equal matches
@e2e exclude Pure matching function. Covered by tests/extension/smallItems.spec.js ("puts the login used last first among equally good matches").
- **GIVEN** three logins for example.com, two used at different times and one never
- **WHEN** the popup asks for logins for the site
- **THEN** the one used last comes first and the unused one last

### Requirement: A form that starts and checks sensibly
A new item MUST start with the address of the site the popup is on (its origin, for an http or https page), and leaving it untouched MUST not ask about unsaved changes. Saving an authenticator item MUST refuse a secret the code generator cannot read: "This is not a valid authenticator secret".

#### Scenario: New item on a site
@e2e exclude Browser-extension popup. Covered by tests/extension/smallItems.spec.js ("starts a new item with the address of the site, and lets it go without asking").
- **GIVEN** the popup over https://example.com/login?next=/home
- **WHEN** the user starts a new item and cancels it
- **THEN** its address was https://example.com, and nothing asked first

#### Scenario: A bad authenticator secret
@e2e exclude Pure form validation. Covered by tests/extension/smallItems.spec.js ("refuses an authenticator secret the code generator cannot read").
- **GIVEN** an authenticator item
- **WHEN** its secret is not base32 or an otpauth address
- **THEN** the form refuses it

### Requirement: The popup keeps its place
Going back from an item MUST return to the same place in the list. The popup MUST remember its last tab in local storage where the browser has no session storage, and the worker MUST clear it there too on lock. Folder changes MUST be off while the server cannot be reached, with "You are offline. Changes need a connection to Keepiq." A blocked item MAY still go to the trash: it can be the only way to clear out an item of a revoked suite.

#### Scenario: Back to the list
@e2e exclude Browser-extension popup. Covered by tests/extension/smallItems.spec.js ("keeps the place in the list when going back from an item").
- **GIVEN** the list scrolled down
- **WHEN** the user opens an item and goes back
- **THEN** the list is where it was

#### Scenario: No session storage
@e2e exclude Browser-extension popup. Covered by tests/extension/smallItems.spec.js ("remembers the last tab in local storage where the browser has no session storage").
- **GIVEN** a browser without session storage
- **WHEN** the popup closes on Generator and opens again
- **THEN** it opens on Generator

#### Scenario: Offline folders, blocked items
@e2e exclude Browser-extension popup. Covered by tests/extension/smallItems.spec.js ("switches folder changes off while offline") and ("lets a blocked item go to the trash").
- **GIVEN** no server, or a blocked item
- **WHEN** the user opens the folder manager, or the item
- **THEN** every folder change is off with the offline note; the blocked item cannot be edited but can be deleted
