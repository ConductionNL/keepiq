# Browser extension permissions

Each permission the Keepiq extension asks for, and why. The store listings use these lines as the justification for review.

| Permission | Why the extension needs it |
|---|---|
| `storage` | Keeps the connected accounts (server, user, app password, label, idle delay) and, for at most five minutes, which one-time code to fill on the next login step. |
| `activeTab` | Fills the login you pick into the tab you are on. |
| `tabs` | Reads the address of the current tab to show matching logins, and sends the fill to that tab only. |
| `clipboardWrite` | Copies a password, username, one-time code or Send link so you can paste it. |
| `offscreen` | Clears the clipboard again after the delay you picked (30 seconds unless you change it), also when the popup has closed. A Chromium service worker has no clipboard, so it clears through a hidden page. Firefox needs no permission for this. |
| `alarms` | Syncs the vault every 15 minutes while it is unlocked, and clears the clipboard after a longer delay. |
| `idle` | Locks the vault when your computer locks or after the idle delay you picked. |
| `windows` | Opens the small window that asks before a passkey is used, and the window for fingerprint or face unlock. Firefox needs no permission for this. |
| Access to all `http` and `https` sites | Finds login, one-time code and passkey fields on the sites you use. The extension fills only after you pick a login in its popup, or a one-time code on the step right after a login fill on the same site. |

A test checks that every permission in `browser-extension/manifest.json` has a call site in the extension code (`tests/extension/storeRelease.spec.js`), so an unused permission fails the build.
