## MODIFIED Requirements

### Requirement: The bar's buttons act only while it is plainly visible
The extension SHALL apply the inline list's visibility guard (`inline-list` spec, design.md § Visibility guard) to the bar: the host is a popover in the top layer where the browser has the popover API, placed inside the modal dialog or `aria-modal` container the page is in; it closes on any change to the host it did not make and when it stops being `:popover-open`; it is sampled every animation frame with the same untouched checks, the `<html>` filter exception on every browser but Chromium included. Save, Update and "Never for this site" MUST act only after the background asks the host with `capture.verify` and the host confirms that the bar was visible for the 500 ms before the press and is what the press point hits. The bar page also refuses a press within 500 ms of being shown or moved and, on Chrome, while IntersectionObserver v2 reports it covered. A refused press does nothing, keeps the capture, and the bar says "Keepiq can't do this here because this page changes how it is shown. Close this and add the login from the Keepiq button in the toolbar." (nl "Keepiq kan dit hier niet doen, omdat deze pagina aanpast hoe ze wordt weergegeven. Sluit dit en voeg de login toe via Keepiq in de werkbalk."), since the popup cannot answer a pending capture; the notice replaces the question and controls, leaving only Close, and takes focus. Close needs no guard.

#### Scenario: Page covers the bar
- **GIVEN** a save bar and a page element drawn over its Save button
- **WHEN** a click lands on Save
- **THEN** nothing is saved and the capture is kept

#### Scenario: Page fades the bar
- **GIVEN** a save bar whose host the page restyles to be transparent
- **WHEN** the user clicks where Save was
- **THEN** the bar is closed and nothing is saved
