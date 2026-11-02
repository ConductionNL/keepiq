## ADDED Requirements

### Requirement: The menu shows anywhere on the page
The extension SHALL add a "Keepiq" context menu on the page, frames, selections, links, editable fields, images, video and audio, and MUST NOT add it to the toolbar icon's own menu. It is hidden when the "Enable context menu options" setting is off, when no account is added and when the active account is logged out. While the active account is locked it holds only "Unlock vault", which opens the popup on the unlock view. The menu MUST be rebuilt on install, browser start, tab activation, a URL change of the active tab, window focus, lock, unlock, logout, account switch, settings change and sync.

#### Scenario: Right-click next to a form
- **GIVEN** an unlocked vault and a login page
- **WHEN** the user right-clicks the page's background, not a field
- **THEN** the Keepiq menu is shown with all its entries

#### Scenario: Locked vault
- **GIVEN** a locked vault
- **WHEN** the user right-clicks an image
- **THEN** the Keepiq menu offers only "Unlock vault", which opens the popup on the unlock view

#### Scenario: Setting off
- **GIVEN** "Enable context menu options" is off
- **WHEN** the user right-clicks anywhere on a page
- **THEN** no Keepiq menu is shown

### Requirement: Entries and order
While unlocked, the menu SHALL hold, in this order: Autofill login (nl "Login invullen"), Copy username, Copy password, Copy verification code, a separator, Autofill identity (nl "Identiteit invullen"), Autofill card (nl "Kaart invullen"), a separator, and Generate password and copy. Every entry but the last is a submenu, also when it lists one item, and MUST NOT be disabled itself. Each submenu lists at most 10 items by last used, then name, labelled with the item's name only, never a username or another decrypted value; `&` in a name is escaped so it shows as typed. When a submenu has no item to list, it holds one disabled line:

| Submenu | Lists | Empty line (en / nl) |
| --- | --- | --- |
| Autofill login | Logins matching the tab | "No logins available" / "Geen logins beschikbaar" |
| Copy username | Matching logins with a username | "No usernames available" / "Geen gebruikersnamen beschikbaar" |
| Copy password | Matching logins, use-only copies left out | "No passwords available" / "Geen wachtwoorden beschikbaar" |
| Copy verification code | Matching logins with a code (`totp-autofill`) | "No verification codes available" / "Geen verificatiecodes beschikbaar" |
| Autofill identity | The account's identities (`card-identity-fill`) | "No identities available" / "Geen identiteiten beschikbaar" |
| Autofill card | The account's cards (`card-identity-fill`) | "No cards available" / "Geen kaarten beschikbaar" |

Autofill login, Autofill identity and Autofill card end, after a separator, with Add login (nl "Login toevoegen"), Add identity (nl "Identiteit toevoegen") or Add card (nl "Kaart toevoegen"), then Open Keepiq, which opens the popup. Blocked rows are never listed.

#### Scenario: One match
- **GIVEN** one login matching the tab
- **WHEN** the user opens the Keepiq menu
- **THEN** Copy username is a submenu listing that login by its name

#### Scenario: No match
- **GIVEN** no login matches the tab
- **WHEN** the user opens Autofill login
- **THEN** it lists the disabled line "No logins available", then a separator, Add login and Open Keepiq
- **AND** Autofill login itself is not disabled

#### Scenario: No login with a code
- **GIVEN** matching logins, none with a code
- **WHEN** the user opens Copy verification code
- **THEN** it holds only the disabled line "No verification codes available"

#### Scenario: More than ten matches
- **GIVEN** twelve logins matching the tab
- **WHEN** the user opens Autofill login
- **THEN** the first ten by last used, then name, are listed, then Add login and Open Keepiq

#### Scenario: No username in a title
- **GIVEN** a matching login named "GitHub" with username `alice`
- **WHEN** the menu is built
- **THEN** no menu title contains `alice`

### Requirement: Fill from the menu
Picking an item under Autofill login, Autofill identity or Autofill card SHALL fill it into the tab as the popup's Fill does, wherever the user right-clicked, with the insecure and cross-site questions in the page (`autofill`, `card-identity-fill`). A login fill copies its code as `totp-autofill` specifies.

#### Scenario: Fill after right-clicking a link
- **GIVEN** a login page with a matching login
- **WHEN** the user right-clicks a link on it and picks the login under Autofill login
- **THEN** the login form is filled

### Requirement: A fill without a form says so
When a fill from the menu finds no field for the item in any frame of the tab, the extension SHALL show a notice in the page's top frame: "Keepiq found no login form to fill on this page." (nl "Keepiq vond geen inlogformulier om in te vullen op deze pagina."), for a card "Keepiq found no card fields to fill on this page." (nl "Keepiq vond geen kaartvelden om in te vullen op deze pagina."), for an identity "Keepiq found no address or personal details fields to fill on this page." (nl "Keepiq vond geen adres- of persoonsgegevensvelden om in te vullen op deze pagina."). The notice is an extension page in an iframe in the save bar's corner, below the save bar when one is open. It is announced as a status, has a Close button, never takes focus, and closes after 8 seconds counted only while neither pointer nor focus is in it. A later notice replaces it. A declined question, a lock or a failed decrypt shows no notice. The background sends the top frame only a code, never vault data.

#### Scenario: Page without a form
- **GIVEN** an article page whose site has a matching login
- **WHEN** the user picks the login under Autofill login
- **THEN** the page shows "Keepiq found no login form to fill on this page."
- **AND** nothing was decrypted for the page

#### Scenario: Declined question
- **GIVEN** an `http:` login page and an item saved on `https:`
- **WHEN** the user picks the item and declines the insecure question
- **THEN** no notice is shown

#### Scenario: Notice and keyboard
- **GIVEN** the notice is shown
- **WHEN** the user keeps typing in the page
- **THEN** focus stays where it was, and the notice closes after 8 seconds

### Requirement: Add entries open a form for one type
Add login, Add identity and Add card SHALL open the popup, or the pop-out window where the popup cannot open, on the add form for that type without the Type select (`item-editing`). The choice MUST reach the popup only through background memory and expire after 10 seconds, on lock and on account switch. It is never stored.

#### Scenario: Add identity
- **GIVEN** an unlocked vault
- **WHEN** the user picks Add identity
- **THEN** the popup opens on "New identity" with the identity fields and no Type select

#### Scenario: Add login takes the page
- **GIVEN** the tab shows `https://example.test/login`
- **WHEN** the user picks Add login
- **THEN** the popup opens on "New login" with Website `https://example.test/login`

#### Scenario: Toolbar popup later
- **GIVEN** the user picked Add card and closed the popup
- **WHEN** they open the popup from the toolbar a minute later
- **THEN** it opens on the vault list

### Requirement: Copy from the menu clears after the configured delay
Picking an item under Copy username, Copy password or Copy verification code SHALL decrypt that one value in the background, or compute the current code, and copy it; Generate password and copy SHALL generate a password with the account's saved generator options, copy it and add it to the generator history (ext-generator). The clipboard MUST be cleared after the "Clear clipboard" delay unless it is Never, and a copied code also on lock (`totp-autofill`). The item must still match the tab at the click. On Chrome MV3 the write happens in the offscreen document, on Firefox MV2 in the background page; the plaintext lives in memory only for the copy.

#### Scenario: Copy password with a clear delay
- **GIVEN** a matching login and the clear delay set to 30 seconds
- **WHEN** the user picks it under Copy password
- **THEN** the clipboard holds the password
- **AND** 30 seconds later the clipboard is overwritten

#### Scenario: Copy a code
- **GIVEN** a matching login with a code
- **WHEN** the user picks it under Copy verification code
- **THEN** the clipboard holds its current code

#### Scenario: Generate password and copy
- **GIVEN** an unlocked vault and saved generator options of 20 characters without symbols
- **WHEN** the user picks Generate password and copy
- **THEN** a 20-character password without symbols is on the clipboard and in the generator history
