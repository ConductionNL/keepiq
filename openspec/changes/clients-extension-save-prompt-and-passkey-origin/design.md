# Design: offer to save or update a login at once, and pin passkey requests to the page origin

## Context

At development `156cd800`:

- `browser-extension/src/content/content-script.js:134` `attachSubmitCapture`, `:150` `captureCurrent` (no id), `:192` `injectShim`, `:204-212` relay forwards `data.origin`.
- `browser-extension/src/background/service-worker.js:186` `doSaveCapture`, `:198` updates only with `payload.id`, `:230` passkey routes, `:240` `pendingCapture` in memory.
- `browser-extension/src/popup/popup.js:79` shows the `pending-capture` prompt only in the popup.
- `browser-extension/src/passkey/orchestrator.js:74` `handleCreate` and `handleGet`; `src/passkey/registration.js:23` native proxy registration behind an optional permission that is never requested.

## Goals / Non-Goals

**Goals**
- A submit leads to a visible offer at once, and an update never duplicates.
- A passkey request cannot claim an origin it does not have.

**Non-Goals**
- Store release and Safari (see the two sibling changes).
- Changing passkey storage.

## Decisions

### D1: A shadow root bar, not a popup

The prompt is injected in a closed shadow root and takes no input from the page, so page script cannot read or click it. The popup prompt stays as a fallback.

### D2: Match in the service worker

The service worker already holds the vault cache; it matches by origin and username and returns the id with the capture, so the content script never sees saved passwords.

### D3: Registrable suffix rule

The rpId check follows the WebAuthn rule: equal to the host or a parent domain that is not a public suffix.
