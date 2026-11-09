## MODIFIED Requirements

### Requirement: Fallback to the browser
"Use your device or hardware key" SHALL end the Keepiq ceremony and make the page script call the browser's original method with the page's original options and signal, so the browser's own dialog takes over. The page then receives whatever the browser returns. A window for a conditional request does not offer it, and a `passkey.fallback` from that window acts as its Cancel.

The page script runs in the page's `MAIN` world on every target but Firefox: Chrome, Safari and every other Chromium target, such as Edge. Firefox injects it.

On Safari, which starts WebAuthn only while the page's window has focus, a fallback the content script answered for a modal request MUST wait until the top window has focus before calling the original method, as Bitwarden's page script does: at once when it has focus, after 500 ms when there is no top window or reading its focus throws, else on the first `focus` event on the top window or on the calling window, as a browser fires it only on the focused frame's window. The page's `AbortSignal` ends the wait with the signal's reason, and the ceremony's existing deadline with `NotAllowedError`. A request the page script hands to the browser before reaching the content script, and the browser's own request racing a conditional one, do not wait.

#### Scenario: Fallback from the list
- **GIVEN** the sign-in window with one passkey listed
- **WHEN** the user clicks "Use your device or hardware key"
- **THEN** the window closes and the browser's own passkey dialog opens for the same request

#### Scenario: Safari waits for focus
- **GIVEN** Safari, the sign-in window open and the page's window without focus
- **WHEN** the user clicks "Use your device or hardware key" and the page's window regains focus a second later
- **THEN** the original `get` runs only after the page's window has focus, with the page's options

#### Scenario: Safari waits for focus in a same-origin frame
- **GIVEN** Safari, a same-origin frame that called `create`, the sign-in window open and the page's window without focus
- **WHEN** the user clicks "Use your device or hardware key" and focus returns to the frame
- **THEN** the original `create` runs on the frame's `focus` event, once, without waiting for the deadline

#### Scenario: Page aborts during the wait
- **GIVEN** Safari and a fallback waiting for focus
- **WHEN** the page aborts the request's `AbortSignal`
- **THEN** the call rejects with the signal's reason and the original method never runs
