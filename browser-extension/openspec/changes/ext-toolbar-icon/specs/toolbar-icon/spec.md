## Purpose

Shows on the toolbar icon whether the active account is logged out, locked or unlocked, and when unlocked how many logins match the current page, as Bitwarden does.

## ADDED Requirements

### Requirement: Icon follows the active account's state
The extension SHALL show one of three toolbar icons, chosen by the active account's status from ext-accounts-and-unlock:
- **Logged out**: the grey icon, when there is no account or the active account is "Logged out".
- **Locked**: the coloured icon with a lock, when the active account is "Locked".
- **Unlocked**: the coloured icon, when the active account is "Unlocked".

The other accounts' status MUST NOT affect the icon. The icon is the same in every window and tab.

#### Scenario: No account
- **GIVEN** a fresh install with no account stored
- **WHEN** the browser shows the toolbar
- **THEN** the icon is grey

#### Scenario: Locking the active account
- **GIVEN** the active account is unlocked
- **WHEN** the user locks it, or the vault timeout locks it
- **THEN** the icon changes to the coloured icon with a lock without the popup being opened

#### Scenario: Revoked app password
- **GIVEN** the active account is unlocked
- **WHEN** a request returns 401 and the account becomes "Logged out"
- **THEN** the icon turns grey

#### Scenario: Switching to an account with another status
- **GIVEN** account A is unlocked and active, and account B is locked
- **WHEN** the user switches to B
- **THEN** the icon shows the lock
- **AND** switching back to A shows the coloured icon without a lock

#### Scenario: Browser restart
- **GIVEN** the active account was unlocked under a timeout other than Never
- **WHEN** the browser is closed and reopened
- **THEN** the icon shows the lock

### Requirement: Badge counts the logins matching the active tab
While the active account is unlocked, the extension SHALL set a badge on each tab with the number of items that ext-autofill's URL matching offers for that tab's URL. These are the same items that the popup's "Autofill suggestions" section lists. The count MUST be computed in the background, from the cached plaintext `url`, `typeId` and `blocked` fields of the active account's snapshot and the URL the browser reports for the tab. It MUST NOT decrypt anything, send any request or read a URL supplied by page content. The text is the count for 1 to 9, "9+" above nine, and empty at zero. The badge is per tab, so tabs in different windows each show their own count.

#### Scenario: Three logins for a site
- **GIVEN** an unlocked vault with three `login` items whose `url` matches `https://www.youtube.com/` under the default Base domain rule
- **WHEN** the user opens `https://www.youtube.com/watch`
- **THEN** the badge on that tab reads "3"

#### Scenario: More than nine matches
- **GIVEN** twelve matching `login` items
- **WHEN** the tab loads the matching page
- **THEN** the badge reads "9+"

#### Scenario: No matches
- **GIVEN** no item matches the tab's URL
- **WHEN** the tab loads
- **THEN** the badge is empty

#### Scenario: Excluded rows
- **GIVEN** a matching row with `blocked: true`, a matching row whose type is not `login`, and one matching `login` row
- **WHEN** the badge is computed
- **THEN** it reads "1"

#### Scenario: Pages that cannot be filled
- **GIVEN** the tab shows a `chrome://`, `about:`, `file:` or extension page
- **WHEN** the badge is computed
- **THEN** it is empty

#### Scenario: Match rule set to Never
- **GIVEN** the URI match default is Never
- **WHEN** any page loads
- **THEN** the badge is empty

### Requirement: Badge is empty unless unlocked
The extension MUST clear the badge on every tab when the active account is locked or logged out, or when the user switches to an account that is not unlocked. A locked vault MUST NOT show a count, even though the snapshot's plaintext fields could produce one.

#### Scenario: Lock clears the count
- **GIVEN** the badge reads "3" on the active tab
- **WHEN** the vault locks
- **THEN** the badge is empty on every tab

### Requirement: Badge stays current
The extension SHALL recompute the badge of the affected tab, within one second, when:
- the active tab changes;
- a tab's URL changes or it finishes loading;
- the focused window changes;
- the active account unlocks or changes;
- the snapshot changes after a sync, add, edit, delete or capture save;
- the URI match default changes;
- the badge counter setting changes.

On Chrome MV3, listeners are registered when the service worker starts, so a worker woken by any of these events still updates the badge.

#### Scenario: Navigating within a tab
- **GIVEN** the badge reads "2" on `https://a.example/`
- **WHEN** the same tab navigates to `https://b.example/`, which has no matching items
- **THEN** the badge becomes empty

#### Scenario: Saving a captured login
- **GIVEN** the badge is empty on `https://shop.example/`
- **WHEN** the user saves the submitted login from the save bar
- **THEN** the badge reads "1" after the vault refreshes

#### Scenario: Unlock from the popup
- **GIVEN** the vault is locked on a tab with two matching items
- **WHEN** the user unlocks
- **THEN** the icon loses the lock and the badge reads "2"

### Requirement: Tooltip names the state
The extension SHALL set the icon's tooltip from the extension name and the state:
- the name alone when unlocked;
- "<name>: Locked" when locked;
- "<name>: Logged out" when logged out.

The name keeps the " (DEV)" suffix in development builds. When the badge shows a count, the tooltip also says how many logins match, for example "Keepiq: 3 logins for this site".

#### Scenario: Locked tooltip
- **GIVEN** the active account is locked
- **WHEN** the user hovers the icon
- **THEN** the tooltip reads "Keepiq: Locked"

### Requirement: Badge counter setting
The extension SHALL offer "Show number of login autofill suggestions on extension icon" in the Appearance view. It is on by default and is stored in the global Appearance record in `storage.local`, which is never cleared automatically. When it is off, the badge is empty on every tab and the tooltip leaves out the count; the icon still shows the account state. The change applies without reopening the popup or reloading the extension.

#### Scenario: Turning the counter off
- **GIVEN** the badge reads "3"
- **WHEN** the user turns the setting off
- **THEN** the badge is empty on every tab and the icon stays coloured

### Requirement: Counts live in memory only
The extension MUST NOT write the badge counts or the per-tab match results to any storage. They are recomputed from the snapshot when needed and are lost when the worker or background page stops.

#### Scenario: Worker restart
- **GIVEN** the badge reads "3" and the MV3 worker is terminated
- **WHEN** the user switches to that tab again
- **THEN** the count is recomputed from the snapshot, and no stored count exists in `storage.local` or `storage.session`
