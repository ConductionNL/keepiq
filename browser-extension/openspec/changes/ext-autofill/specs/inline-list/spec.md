## ADDED Requirements

### Requirement: The inline list follows the "Show autofill suggestions on form fields" setting
The extension SHALL offer the inline list only when an account is logged in (locked or unlocked), the active account's "Show autofill suggestions on form fields" setting (`inlineSuggestions` from ext-settings, default on) is on, and the field's frame is `http:` or `https:`. The background decides this on every open; the content script holds no copy of the setting. Excluded domains do not hide the list; they suppress only the save and update bars (`login-capture`).

#### Scenario: Setting off
- **GIVEN** an unlocked vault with a login for the site and "Show autofill suggestions on form fields" off
- **WHEN** the user focuses the empty username field
- **THEN** no list opens and nothing is inserted into the page

#### Scenario: Setting turned back on
- **GIVEN** the setting off and a login page open
- **WHEN** the user turns the setting on in the popup and focuses the username field again
- **THEN** the list opens without reloading the page

#### Scenario: No account
- **GIVEN** no Keepiq account in the extension, or only one that must log in again
- **WHEN** the user focuses a username field
- **THEN** no list opens

#### Scenario: Excluded domain
- **GIVEN** `example.test` in excluded domains and a login for it
- **WHEN** the user focuses the username field on `https://example.test/login`
- **THEN** the list opens with that login

### Requirement: Which fields open the list
The content script SHALL decide whether a field qualifies, from the page's DOM only, by the field classification of the `autofill` capability: a field classified as the username or the current password of a group classified as a login qualifies, with or without a `<form>`, including a username-only or password-only step. Fields of sign-up and password-change groups, fields classified as a new password (confirm fields included) or a one-time code, and hidden, disabled and read-only fields MUST NOT qualify. `autocomplete="off"` is ignored, as that classification specifies. The list opens on any focus of an empty qualifying field, including focus set by page script or `autofocus` and a field already focused when the content script starts, and on a trusted click in a qualifying field, empty or not. Opening MUST NOT move focus out of the field.

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
- **THEN** it is classified as a one-time code and no list opens

#### Scenario: Filled field
- **GIVEN** a username field that already holds a value
- **WHEN** it receives focus
- **THEN** no list opens
- **AND** a click in the field opens it

#### Scenario: Disabled field
- **GIVEN** a disabled or read-only password field
- **WHEN** it is clicked
- **THEN** no list opens

### Requirement: The list shows matching logins by name and username
For an unlocked vault the list SHALL show every login item matching the URL of the field's frame, chosen and ordered as the popup's suggestions and the context menu are (`autofill`: match rule from settings, no blocked rows or other types, last used then name). Each row shows the item's plaintext `name` and its decrypted `login` as the username, or the name alone when the login is empty, after a decorative globe icon hidden from assistive technology. A use-only shared copy is listed and fillable, since use-only means fill only (ADR-003). No password, URL, folder or other field is shown. Up to five rows show at a time and the list scrolls beyond that. The decrypted usernames exist only in the background while answering and in the list page while it is open, never in storage or the content script.

#### Scenario: Two matching logins
- **GIVEN** logins "Work" (username `alice@work.test`) and "Personal" (username `alice`) for `example.test`, "Personal" filled earlier
- **WHEN** the list opens on `https://example.test/login`
- **THEN** it shows "Personal" with `alice`, then "Work" with `alice@work.test`

#### Scenario: Login without a username
- **GIVEN** a matching login whose `login` is empty
- **WHEN** the list opens
- **THEN** its row shows only the name

#### Scenario: Twelve matches
- **GIVEN** twelve matching logins
- **WHEN** the list opens
- **THEN** five rows are visible and the others are reached by scrolling or the arrow keys

### Requirement: No-match and locked states
The extension SHALL open the list on a qualifying field whether or not a login matches, so its presence does not tell the page whether a login matches. Its height fits what it shows (Position), so the page can read from it how many logins match, up to five; that is accepted, as the count is of no use to a site. With an unlocked vault and no match, it shows "No logins for <host>" and no action. With a locked vault it shows "Unlock Keepiq to see your logins" and an "Unlock" button that opens the popup on the unlock view, or the pop-out window where the popup cannot be opened, and then closes the list; nothing is matched or decrypted while locked. After unlocking, the next focus of the field shows the logins.

#### Scenario: No match
- **GIVEN** an unlocked vault with no login for `nothing.test`
- **WHEN** the user focuses the username field on `https://nothing.test/login`
- **THEN** the list reads "No logins for nothing.test" and offers nothing to pick

#### Scenario: Locked vault
- **GIVEN** a locked vault with a login for the site
- **WHEN** the user focuses the username field
- **THEN** the list reads "Unlock Keepiq to see your logins" with an "Unlock" button and no rows

#### Scenario: Height follows the matches
- **GIVEN** the same login page layout on a site with no matching login, one with one, one with three and one with twelve, and the vault locked, then unlocked
- **WHEN** the list opens on each
- **THEN** the list is one row tall when locked, without a match and with one, three rows with three, and five rows with twelve, the rest scrolling

#### Scenario: Unlock from the list
- **GIVEN** the locked list
- **WHEN** the user activates "Unlock"
- **THEN** the popup, or on failure a pop-out window, opens on the unlock view and the list closes
- **AND** after unlocking, focusing the field again lists the matching logins

### Requirement: Picking a login fills the field's form
When the user picks a row, by click or by Enter or Space on a focused row, the background SHALL check that the row was offered in this list, that it still matches the frame's URL and that the vault is unlocked, then decrypt that one item's `login` and `key` and send them once, to the frame that holds the list only, where the content script fills the form of the field the list was opened for. Picking an `https` login on an `http` frame first asks inside the list, titled "Fill on this insecure site?" (nl "Toch invullen op deze onbeveiligde site?") and followed by the warning "This site is not secure. Anyone on the network could read what is filled in.", with "Fill" and "Cancel"; Cancel sends and decrypts nothing. After a fill the list closes, focus returns to the field without reopening the list, the item's last-used time is recorded, and the pick counts as interaction for the vault timeout. Opening or showing the list does not, and neither does a refused pick.

#### Scenario: Pick with the mouse
- **GIVEN** the list open on the username field with "Work" listed
- **WHEN** the user clicks "Work"
- **THEN** the username and password fields of that form are filled with `input` and `change` events
- **AND** the list closes and focus is back in the username field with no list open

#### Scenario: Other forms and frames
- **GIVEN** a page with a second login form and a same-site iframe with a login form
- **WHEN** the user picks a login from the list on the first form
- **THEN** only the first form is filled and the iframe receives no credential

#### Scenario: Insecure page
- **GIVEN** a login with url `https://example.test/` and the list open on `http://example.test/login`
- **WHEN** the user picks it
- **THEN** the list asks whether to fill on an insecure site
- **AND** "Cancel" fills nothing and "Fill" fills the form

#### Scenario: Vault locks between open and pick
- **GIVEN** the list open with rows and the vault then locking on its timeout
- **WHEN** the lock happens
- **THEN** the list closes and nothing can be picked from it

#### Scenario: Interaction for the timeout
- **GIVEN** a 15-minute timeout and a page that focuses its username field every minute
- **WHEN** the user does nothing else for 15 minutes
- **THEN** the vault locks

### Requirement: Keyboard and screen reader use
The list SHALL meet WCAG 2.2 AA. Its frame is titled "Keepiq logins". With logins it is a listbox named "Keepiq logins for <host>" whose rows are options named "<name>, <username>"; the no-match state is text and the locked state a message with a button, neither a listbox. While the list is open, Down in the field moves focus into the list the same way in every state: to the first row, to "Unlock" in the locked state, or to the no-match message; Escape in the field closes the list, focus staying in the field; Tab and Shift+Tab in the field close the list and move focus on as they would without it, so focus never lands in the list; other keys reach the page unchanged. In the list, Up and Down move between rows, Home and End go to the first and last, Enter or Space picks, Up on the first row, on "Unlock" or on the no-match message returns focus to the field with the list still open, and Escape, Tab and Shift+Tab close the list and return focus to the field. Whenever the list closes while focus is inside it, for any reason, focus returns to the field. The insecure-site question leads with its title "Fill on this insecure site?", followed by the warning, opens with focus on "Cancel", and Escape there cancels and returns focus to the row. When the list opens, a status message announces "<n> Keepiq logins. Press Down Arrow to choose." ("1 Keepiq login. Press Down Arrow to choose." for one; nl "Druk op pijl-omlaag"), "No Keepiq logins for <host>" or "Keepiq is locked. Press Down Arrow to unlock." (nl "Keepiq is vergrendeld. Druk op pijl-omlaag om te ontgrendelen."), without moving focus. The list never covers the focused field, stays open while the pointer is over it, shows a visible focus indicator, has targets of at least 24 by 24 CSS px, meets text and non-text contrast in light and dark, follows the extension's Theme setting, and does not animate. Text follows the browser's language (`ext-i18n`).

#### Scenario: Pick with the keyboard
- **GIVEN** the list open with "Personal" and "Work"
- **WHEN** the user presses Down, Down and Enter in the username field
- **THEN** "Work" fills the form

#### Scenario: Back to the field
- **GIVEN** focus on the first row
- **WHEN** the user presses Up
- **THEN** focus is back in the field and the list stays open

#### Scenario: Down in the no-match state
- **GIVEN** the list open in the no-match state
- **WHEN** the user presses Down in the field
- **THEN** focus moves to the "No logins for <host>" message, as it would move to a row
- **AND** Up or Escape returns focus to the field

#### Scenario: Escape from the field
- **GIVEN** the list open and focus in the field
- **WHEN** the user presses Escape
- **THEN** the list closes, focus stays in the field and the field's value is unchanged

#### Scenario: Leaving the list
- **GIVEN** focus on a row of the list
- **WHEN** the user presses Escape or Tab
- **THEN** the list closes and focus is back in the field

#### Scenario: Tab out of the field
- **GIVEN** the list open under the last field of the page
- **WHEN** the user presses Tab in the field
- **THEN** the list closes and focus moves where it would without the list, never into the list

#### Scenario: Focus inside the list when it closes
- **GIVEN** focus on a row or on "Unlock"
- **WHEN** the list closes because the vault locks, the popup opens from "Unlock" or the pop-out window opens
- **THEN** focus is back in the field

#### Scenario: Locked announcement
- **GIVEN** a screen reader and a locked vault
- **WHEN** the user focuses the username field
- **THEN** "Keepiq is locked. Press Down Arrow to unlock." is announced

#### Scenario: Screen reader announcement
- **GIVEN** a screen reader and two matching logins
- **WHEN** the user focuses the username field
- **THEN** the field is announced as usual and then "2 Keepiq logins. Press Down Arrow to choose."
- **AND** after Down the first row is announced as an option of "Keepiq logins for example.test" with its name and username

#### Scenario: Pointer over the list
- **GIVEN** the list open
- **WHEN** the user moves the pointer from the field onto the list
- **THEN** the list stays open

### Requirement: The list closes when it no longer belongs to the field
The list SHALL close on Escape, on Tab in the field, when focus moves anywhere but the list, when the user types in the field, when the tab is hidden, when the field is removed, hidden or scrolled fully out of view, when the list no longer fits above or below the field, when the page navigates, and when the vault locks, the account logs out or the active account changes. It opens again only on the next focus of an empty qualifying field or a click in one.

#### Scenario: Focus moves on
- **GIVEN** the list open under the username field
- **WHEN** the user clicks elsewhere in the page or tabs to the next page control
- **THEN** the list closes

#### Scenario: Typing
- **GIVEN** the list open
- **WHEN** the user types a character in the field
- **THEN** the character reaches the field and the list closes

#### Scenario: Switching tabs
- **GIVEN** the list open
- **WHEN** the user switches to another tab and back
- **THEN** the list is closed until the field is focused or clicked again

### Requirement: The list sits beneath the field and follows it
The extension SHALL place the list 4 CSS px below the field, left edges aligned, at least as wide as the field and 240 CSS px, and at most the frame's viewport width minus 16 px. Its height fits its contents: one row per matching login up to five, one row for the locked and no-match messages, and the height the insecure-site question or a refusal needs, at most five rows; it is capped by the larger free side of the field within the frame's viewport, and what does not fit scrolls. The height is set when the list opens and changes only when the list's contents do, which counts as a move for the visibility guard. When less than 240 px of width or less than one row fits, the list does not open. When there is less room below the field than the list needs and more above, it is placed above instead. It stays inside the viewport of the field's frame, above the page's own content, and follows the field when the page or an inner container scrolls, the window resizes or the page zooms. When the field is inside a modal dialog, found across shadow roots, the list is placed inside that dialog, since everything outside it is inert. Where the browser has the popover API, a script-trapped modal (`role="dialog"` with `aria-modal="true"`, as Radix, MUI, Headless UI or react-focus-lock build it) counts the same way, so its focus trap keeps focus in the list; without the popover API the list stays outside it.

#### Scenario: Field near the bottom
- **GIVEN** a password field 80 px above the bottom of the viewport
- **WHEN** the list opens
- **THEN** it is placed above the field without covering it

#### Scenario: Small iframe
- **GIVEN** a login iframe whose username field leaves less than one row of room above and below
- **WHEN** the field gets focus
- **THEN** no list opens, whatever the vault holds

#### Scenario: Scrolling
- **GIVEN** the list open under a field inside a scrolling container
- **WHEN** the user scrolls the container a little
- **THEN** the list moves with the field
- **AND** once the field is scrolled fully out of view, the list closes

#### Scenario: Page with its own overlays
- **GIVEN** a page with a sticky header at `z-index: 9999` over the field's surroundings
- **WHEN** the list opens beneath the field
- **THEN** the list is drawn above the header

#### Scenario: Login form in a modal dialog
- **GIVEN** a login form inside a `<dialog>` opened with `showModal()` and centred with `transform: translate(-50%, -50%)`, inside a web component's shadow root
- **WHEN** the user focuses the empty username field
- **THEN** the list opens beneath it inside the dialog, and its rows can be picked with the pointer and the keyboard

#### Scenario: Login form in a script-trapped ARIA modal
- **GIVEN** a browser with the popover API and a login form inside a `role="dialog"` element with `aria-modal="true"` whose script keeps focus inside it
- **WHEN** the user focuses the empty username field and presses Down
- **THEN** the list opens inside that element and focus moves to its first row

### Requirement: The list lives in the frame of the field
The content script runs in every frame. Each frame that offers a list SHALL host its own list for its own fields, matched against that frame's own URL as the browser reports it, and a pick fills only that frame. Frames without an `http:` or `https:` URL get no list. On Chrome and Firefox alike, only the top frame and subframes whose ancestor frames are all same-origin with them offer one, because only there can the extension check that nothing above the frame hides the list: a cross-origin ancestor could hide it with a mask, which no browser reports. Fill from the popup, the context menu and the shortcut still reaches the other frames.

#### Scenario: Same-site login iframe
- **GIVEN** a page on `https://example.test/` with a login form in an iframe on `https://login.example.test/` and a login for `example.test`
- **WHEN** the user focuses the iframe's username field
- **THEN** no list opens there on Chrome or Firefox, since the iframe's origin differs from its parent's
- **AND** filling from the popup still fills the iframe

#### Scenario: Same-origin iframe
- **GIVEN** a page on `https://example.test/` with a login form in an iframe on `https://example.test/frame` and a login for `example.test`
- **WHEN** the user focuses the iframe's username field
- **THEN** the list opens inside the iframe with that login, and a pick fills only the iframe

#### Scenario: Cross-site iframe
- **GIVEN** a page on `https://example.test/` with a password field in an iframe on `https://ads.other.test/`
- **WHEN** the user focuses that field
- **THEN** no list opens in that iframe, on Chrome or Firefox

#### Scenario: Masking page around a login iframe
- **GIVEN** a page on `https://evil.test/` that frames `https://example.test/login` and sets a `mask-image` on the iframe that hides everything below the username field
- **WHEN** the user focuses the iframe's username field
- **THEN** no list opens, so no click on the masked area can pick a login

### Requirement: The page can neither read the list nor pick from it
The list SHALL be an extension page (`inline-list.html`) in an iframe inside a closed shadow root that the content script inserts. No message to the content script carries a row, name, username or item id; of the list's contents the content script learns nothing but a random session key and the list's height, which the page can read from the host anyway. The background MUST accept the list's messages only from `inline-list.html` in the session's tab, from the first list frame that presented the session's key, with that key, and MUST refuse popup messages from the list page and list messages from any other page. The list page MUST ignore messages posted by the page. The list's session holds no plaintext, lives in `storage.session` (background memory on Firefox below 115), and is cleared when the list closes, the next list opens in the tab, the tab closes or loads a new page, the vault locks, the account logs out or the active account changes, and on browser restart.

#### Scenario: Page frames the list page itself
- **GIVEN** a page that embeds `inline-list.html` in its own iframe
- **WHEN** that iframe loads
- **THEN** it shows no rows, and anything it sends is refused

#### Scenario: Page navigates the list's iframe
- **GIVEN** the list open
- **WHEN** page script navigates the list's iframe through `window.frames`
- **THEN** the list closes and the new document receives no key

#### Scenario: Page posts to the list
- **GIVEN** the list open
- **WHEN** page script posts a message shaped like a pick or a focus command to the list's window
- **THEN** the list ignores it and nothing is filled

#### Scenario: What the content script receives
- **GIVEN** the list open with two matching logins
- **WHEN** the messages the content script received are inspected
- **THEN** none contains a name, username or item id

### Requirement: Picks the user could not see are refused
The extension SHALL refuse a pick unless the list stayed visible from 500 ms before the user's press until the pick. A pick by pointer, Enter or Space within 500 ms of the list being shown, moved or changing its content MUST be ignored. Before decrypting, the background MUST ask the hosting content script to confirm that, throughout that time, the list's host stayed in place at its own size with its own styling, was itself not transparent, filtered, blended, transformed, scaled, rotated, zoomed, clipped, masked or hidden, and neither was any ancestor frame or element above that frame, a plain translation of those excepted; and that, checked when the pick arrives, nothing else is hit at the point pressed, in its own document and in every ancestor document; on a refusal it decrypts nothing. Elements above the host in its own document count too where the browser lacks the popover API (Firefox below 125); where the host is a showing popover they do not, since it is drawn and clickable whatever their opacity, filter, transform, mask or visibility, except on Firefox for a `filter` on the page root, which reaches its top layer and is refused; and what can still hide it (`display: none`, `content-visibility: hidden`, an inherited `zoom`, `inert`, an overlay) changes its size or what is hit. Any change to the host or its popover state by page script closes the list, also when the page stops the popover's events first. Down in the field moves focus into the list only after the field has held focus for 500 ms, or right after the user clicked or tabbed into it, a Tab the page cancelled not counting; a held-down Down never moves into the list; and when focus leaves the list, no row keeps focus, so a page that focuses the list's window cannot direct a key press at a row.

The guarantee differs per browser. On Chrome, IntersectionObserver v2 plus the same-origin ancestor checks; masks are covered by refusing cross-origin ancestors. A pick there is also refused while the browser reports the list occluded or not fully visible, which catches overlays that let clicks through. On Firefox, which has no such report, the guard is best effort: it misses a visible element drawn over the list that lets clicks through (`pointer-events: none`), and a style the page applies and removes between two of the extension's visibility samples.

#### Scenario: Transparent page root
- **GIVEN** the list open and page script that sets `opacity: 0`, a `filter` or a `transform` on `<html>`
- **WHEN** the user clicks where a row is
- **THEN** on Chrome, where the list is a popover, it is still drawn on top, and the pick fills the form
- **AND** on Firefox with the popover API the pick fills the form for `opacity` or a `transform`, but a `filter`, which reaches Firefox's top layer, refuses it and the list says Keepiq can't fill here
- **AND** on Firefox below 125, without the popover API, nothing is decrypted or filled for any of them, and the list says Keepiq can't fill here

#### Scenario: Zoomed page root
- **GIVEN** the list open and page script that sets `zoom: 2` on `<html>`
- **WHEN** the user clicks where a row is
- **THEN** the list is not drawn at the size it was given, so nothing is decrypted or filled

#### Scenario: Login iframe in a moved container
- **GIVEN** a same-origin login iframe inside a container moved with `transform: translate(40px, 0)`, and another inside one scaled with `transform: scale(1.5)`
- **WHEN** the user picks a row from the list in each iframe
- **THEN** the first fills its form and the second is refused

#### Scenario: Page hides the popover without its events
- **GIVEN** the list open and page script that stops the host's `beforetoggle` and `toggle` events
- **WHEN** page script calls `hidePopover()` on the host
- **THEN** the list closes

#### Scenario: Page turns the user's Tab into its own focus
- **GIVEN** page script that cancels the user's Tab and focuses the username field itself
- **WHEN** the user presses Down and Enter within 500 ms
- **THEN** Down reaches the page and no row is picked

#### Scenario: Decoy removed when the field loses focus
- **GIVEN** a browser without the popover API (Firefox below 125), the list open under a transparent page root, and page script that restores the opacity when the field loses focus
- **WHEN** the user presses on a row, which takes focus from the field, and releases
- **THEN** the pick is refused, because the list was not visible in the 500 ms before the press

#### Scenario: Page hides the popover
- **GIVEN** the list open
- **WHEN** page script calls `hidePopover()` and `showPopover()` on the host
- **THEN** the list closes

#### Scenario: Overlay that lets clicks through
- **GIVEN** the list open on Chrome and a page element with `pointer-events: none` drawn over it
- **WHEN** the user clicks where a row is
- **THEN** the pick is refused

#### Scenario: Double-click on the field
- **GIVEN** an empty username field and a page that expects a double-click on it
- **WHEN** the second click of the double-click lands where the list has just appeared
- **THEN** the click is ignored

#### Scenario: Enter right after the list appears
- **GIVEN** a page that focuses the username field while the user is typing elsewhere
- **WHEN** the user presses Down and Enter within 500 ms
- **THEN** Down reaches the page and no row is picked

#### Scenario: Host restyled by the page
- **GIVEN** the list open
- **WHEN** page script changes the host element's style or removes it
- **THEN** the list closes

#### Scenario: Click right after the list appears
- **GIVEN** a page that focuses the username field just before a click it expects
- **WHEN** the click lands on a row within 500 ms of the list showing
- **THEN** the click is ignored

#### Scenario: Page focuses the list's window
- **GIVEN** the user moved into the list and back out to a page control
- **WHEN** page script focuses the list's window and the user presses Enter
- **THEN** no row is picked

### Requirement: A pick refused as not visible says why
When the hosting content script cannot confirm the list was visible, or the list page's own visibility check (Chrome's report that the list is occluded or not fully visible) blocks the pick, the extension SHALL leave the list open instead of closing it, and the list SHALL show and focus "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar." (nl "Keepiq kan hier niet invullen, omdat deze pagina aanpast hoe ze wordt weergegeven. Gebruik de Keepiq-knop in de werkbalk."). Nothing is decrypted or filled, and the refusal is no interaction for the vault timeout. Escape or Tab then closes the list and returns focus to the field. Assistive styling can change the page this way and refuse every pick, so the user learns to fill from the toolbar instead: a zoom extension's `zoom` on `<html>` everywhere; on Firefox a dark-mode extension's `filter` on `<html>`, such as "Invert the page"; and a high-contrast extension's `transform` or opacity on `<html>` where the browser lacks the popover API (Firefox below 125).

#### Scenario: Page styled by an assistive extension
- **GIVEN** a zoom extension that sets `zoom` on `<html>`, or on Firefox a dark-mode extension's "Invert the page" that puts a `filter` on it, and the list open with "Work"
- **WHEN** the user clicks "Work"
- **THEN** nothing is decrypted or filled, the list stays open and shows and focuses "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar."
- **AND** Escape closes the list and focus is back in the field

#### Scenario: Pick blocked by the list page's own check
- **GIVEN** the list open on Chrome and a page overlay that lets clicks through drawn over it, so Chrome reports the list not fully visible
- **WHEN** the user clicks a row
- **THEN** nothing is decrypted or filled, and the list stays open and shows and focuses "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar."
