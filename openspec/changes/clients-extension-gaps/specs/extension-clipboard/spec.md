## ADDED Requirements

### Requirement: Every copy is cleared after a delay the user sets
Every copy from the popup (a password, username or other field, a one-time code, a generated value, a Send link) MUST be cleared from the clipboard after a delay the user picks in Settings: never, 10, 20 or 30 seconds, or 1, 2 or 5 minutes, 30 seconds unless changed. The delay applies to every account in the browser. The worker MUST do the clearing, so it happens also when the popup has closed: Chromium clears through an offscreen document, Firefox from its background page. A newer copy restarts the wait. The clipboard is cleared whatever it holds by then, since reading it would need a permission to read every copy.

#### Scenario: Copy and close
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/clipboard.spec.js ("copies a password from the item detail and has the worker clear it later"), ("clears after 30 seconds by default, through an alarm") and ("clears through an offscreen document in Chromium, made once").
- **GIVEN** an unlocked popup with an item open
- **WHEN** the user copies its password and the popup closes
- **THEN** the worker clears the clipboard 30 seconds later

#### Scenario: Another delay
@e2e exclude Browser-extension worker. Covered by tests/extension/clipboard.spec.js ("uses a timer for a delay shorter than an alarm allows, and restarts on a newer copy") and ("never clears when the user picked Never, and refuses an unknown delay").
- **GIVEN** the delay set to 10 seconds, or to never
- **WHEN** the user copies twice, 8 seconds apart
- **THEN** the clipboard clears 10 seconds after the second copy, or not at all

### Requirement: A large vault is never cut off in silence
When the extension reads the vault page by page (offline caching switched off), it MUST read until it has every item the server counts. When the vault is larger than it reads (100,000 items), it MUST fail with a message that names the limit, so the sync keeps the snapshot it had, instead of storing part of the vault as if it were all of it.

#### Scenario: Page by page
@e2e exclude Browser-extension API client. Covered by tests/extension/clipboard.spec.js ("reads every page of a vault of 250 items") and ("says so instead of returning part of a vault larger than it reads").
- **GIVEN** a vault of 250 items, or one larger than the extension reads
- **WHEN** it is read page by page
- **THEN** all 250 come back, or the read fails with a message
