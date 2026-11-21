## MODIFIED Requirements

### Requirement: Picks the user could not see are refused
The extension SHALL refuse a pick unless the list stayed visible from 500 ms before the user's press until the pick. A pick by pointer, Enter or Space within 500 ms of the list being shown, moved or changing its content MUST be ignored. Before decrypting, the background MUST ask the hosting content script to confirm that, throughout that time, the list's host stayed in place at its own size with its own styling, was itself not transparent, filtered, blended, transformed, scaled, rotated, zoomed, clipped, masked or hidden, and neither was any ancestor frame or element above that frame, a plain translation of those excepted; and that, checked when the pick arrives, nothing else is hit at the point pressed, in its own document and in every ancestor document; on a refusal it decrypts nothing. Elements above the host in its own document count too where the browser lacks the popover API (Firefox below 125); where the host is a showing popover they do not, since it is drawn and clickable whatever their opacity, filter, transform, mask or visibility, except for a `filter` on the page root on every browser but Chromium, which is refused: it reaches Firefox's top layer, and whether it reaches Safari's is unverified; and what can still hide it (`display: none`, `content-visibility: hidden`, an inherited `zoom`, `inert`, an overlay) changes its size or what is hit. Any change to the host or its popover state by page script closes the list, also when the page stops the popover's events first. Down in the field moves focus into the list only after the field has held focus for 500 ms, or right after the user clicked or tabbed into it, a Tab the page cancelled not counting; a held-down Down never moves into the list; and when focus leaves the list, no row keeps focus, so a page that focuses the list's window cannot direct a key press at a row.

The guarantee differs per browser. On Chrome, IntersectionObserver v2 plus the same-origin ancestor checks; masks are covered by refusing cross-origin ancestors. A pick there is also refused while the browser reports the list occluded or not fully visible, which catches overlays that let clicks through. On Firefox and Safari, which have no such report, the guard is best effort: it misses a visible element drawn over the list that lets clicks through (`pointer-events: none`), and a style the page applies and removes between two of the extension's visibility samples.

#### Scenario: Transparent page root
- **GIVEN** the list open and page script that sets `opacity: 0`, a `filter` or a `transform` on `<html>`
- **WHEN** the user clicks where a row is
- **THEN** on Chrome, where the list is a popover, it is still drawn on top, and the pick fills the form
- **AND** on Firefox with the popover API the pick fills the form for `opacity` or a `transform`, but a `filter`, which reaches Firefox's top layer, refuses it and the list says Keepiq can't fill here
- **AND** on Safari the same as on Firefox with the popover API
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
When the hosting content script cannot confirm the list was visible, or the list page's own visibility check (Chrome's report that the list is occluded or not fully visible) blocks the pick, the extension SHALL leave the list open instead of closing it, and the list SHALL show and focus "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar." (nl "Keepiq kan hier niet invullen, omdat deze pagina aanpast hoe ze wordt weergegeven. Gebruik de Keepiq-knop in de werkbalk."). Nothing is decrypted or filled, and the refusal is no interaction for the vault timeout. Escape or Tab then closes the list and returns focus to the field. Assistive styling can change the page this way and refuse every pick, so the user learns to fill from the toolbar instead: a zoom extension's `zoom` on `<html>` everywhere; on Firefox and Safari a dark-mode extension's `filter` on `<html>`, such as "Invert the page"; and a high-contrast extension's `transform` or opacity on `<html>` where the browser lacks the popover API (Firefox below 125).

#### Scenario: Page styled by an assistive extension
- **GIVEN** a zoom extension that sets `zoom` on `<html>`, or on Firefox or Safari a dark-mode extension's "Invert the page" that puts a `filter` on it, and the list open with "Work"
- **WHEN** the user clicks "Work"
- **THEN** nothing is decrypted or filled, the list stays open and shows and focuses "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar."
- **AND** Escape closes the list and focus is back in the field

#### Scenario: Pick blocked by the list page's own check
- **GIVEN** the list open on Chrome and a page overlay that lets clicks through drawn over it, so Chrome reports the list not fully visible
- **WHEN** the user clicks a row
- **THEN** nothing is decrypted or filled, and the list stays open and shows and focuses "Keepiq can't fill here because this page changes how it is shown. Use the Keepiq button in the toolbar."
