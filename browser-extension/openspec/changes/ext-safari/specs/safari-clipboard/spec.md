## Purpose

Lets the Safari extension copy and clear the clipboard through its containing app, since Safari's background is a service worker without a document and Safari has no offscreen document.

## ADDED Requirements

### Requirement: Copy through the containing app
On Safari, every copy the extension makes, from the popup, the passkey window, the context menu or after a fill, SHALL write the system pasteboard through a native message to the containing app. The pasteboard gets the text as a plain string for this Mac only, so Universal Clipboard does not send it to the user's other devices, and marked with `org.nspasteboard.ConcealedType` so clipboard managers leave it out of their history. When the native message fails and the copy is the popup's, a production build MUST NOT write the value another way, since a `navigator.clipboard` write is neither for this Mac only nor marked, and shows the existing copy-failed toast. A popup copy whose outcome is unknown, because the app did not answer in time or answered in another shape, shows that toast too, but MUST still have a clear without a count scheduled, since the app may have written. Only a development build, as loaded without its app, writes with `navigator.clipboard` instead, and shows that toast when that fails too. A background copy that the app refused or that could not reach the app copies nothing and leaves any pending clear in place, as on the other browsers. A background copy whose outcome is unknown, because the app did not answer in time or answered in another shape, also counts as failed, but MUST schedule a clear without a count after the account's delay, since the app may still write, and when the copy is a one-time code, MUST keep the code's account so its lock clears it, also when the delay is Never.

#### Scenario: Copy from the context menu
- **GIVEN** the Safari build inside its app, an unlocked vault and a matching login
- **WHEN** the user chooses Copy password from the Keepiq context menu
- **THEN** the pasteboard holds the password and the clear is scheduled after the account's delay

#### Scenario: Popup copy the app fails
- **GIVEN** the Safari production build, whose app refuses the write, cannot be reached or gives no known outcome
- **WHEN** the user copies a password in the popup
- **THEN** the popup writes nothing itself and toasts "Could not copy"
- **AND** when the outcome is unknown, a clear without a count is scheduled after the account's delay

#### Scenario: Development build without its app
- **GIVEN** a Safari development build loaded without its containing app
- **WHEN** the user copies a password in the popup
- **THEN** the popup writes it with `navigator.clipboard` and toasts "Password copied"

### Requirement: The clear only clears what Keepiq wrote
On Safari the scheduled clear and the clear at a lock SHALL clear the pasteboard only while it still holds Keepiq's last write, judged by `NSPasteboard.changeCount`, never by reading the value. A copy that produced no count, such as the popup's `navigator.clipboard` fallback in a development build, is cleared unconditionally, as on Chrome and Firefox. The count is kept in `storage.session` as `clipboardChangeCount`, never the value, and is removed by the clear, by a copy without a count and by a browser restart.

#### Scenario: Nothing copied since
- **GIVEN** a password copied 30 seconds ago with a clear delay of 30 seconds
- **WHEN** the clear runs
- **THEN** the pasteboard is empty

#### Scenario: The user copied something else
- **GIVEN** a password copied with a clear delay of 30 seconds
- **WHEN** the user copies a sentence in another app 10 seconds later and the clear runs
- **THEN** the pasteboard still holds the sentence

#### Scenario: Lock clears a copied code
- **GIVEN** a one-time code the background copied for an account
- **WHEN** that account locks before the delay ends
- **THEN** the pasteboard is cleared at once, unless something was copied since

### Requirement: What crosses the native boundary
The extension SHALL send the containing app only `{ kind: 'clipboard.write', text }` and `{ kind: 'clipboard.clear', changeCount? }`, from the background or the popup, never from a content script. The app SHALL answer every message, also an unknown or malformed one: `{ ok: true, changeCount }` to a write, `{ ok: true, cleared }` to a clear, and `{ ok: false, error }` with `write_failed`, `bad_request` or `unknown_kind` otherwise. The extension treats the reply as untrusted, and a reply of another shape, a rejection or no reply within 2 seconds as a failed call. The app stores nothing and never logs the text.

#### Scenario: Unknown kind
- **WHEN** the app receives `{ kind: 'clipboard.read' }`
- **THEN** it answers `{ ok: false, error: 'unknown_kind' }` and leaves the pasteboard unchanged

#### Scenario: App does not answer
- **GIVEN** a native call that has not settled after 2 seconds
- **WHEN** the background was copying for the context menu
- **THEN** the copy counts as failed
- **AND** a clear without a count is scheduled after the account's delay, replacing any earlier one
- **AND** a one-time code's account is kept, so its lock clears the pasteboard, also when the delay is Never

#### Scenario: App refuses the write
- **GIVEN** a native call answered with `{ ok: false, error: 'write_failed' }`
- **WHEN** the background was copying for the context menu
- **THEN** the copy counts as failed and any pending clear stays as it was

#### Scenario: A clear during a copy
- **GIVEN** a scheduled clear that fires while the background is copying a new value through the app
- **WHEN** both finish
- **THEN** the clear neither removes nor uses the new value's `clipboardChangeCount`
