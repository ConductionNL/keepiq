# Tasks: offer to save or update a login at once, and pin passkey requests to the page origin

## 1. Save prompt

- [x] 1.1 Return a matched secret id from the service worker for a capture and pass it to `doSaveCapture`. Verify: node tests on the service worker for match, no match and unchanged password.
- [x] 1.2 Inject the closed shadow root prompt from the content script with Save, Update and Not now. Verify: extension test in the existing `tests/extension` harness (`saveCapture.spec.js`). The Playwright flow with the extension loaded is excluded (browser service not available to this lane); the scenarios carry the reason.

## 2. Passkey origin

- [x] 2.1 Use `location.origin` in the content script relay and add the rpId suffix check in the orchestrator. Verify: node tests for own rpId, parent domain, foreign rpId and a public suffix.
- [x] 2.2 Request the optional native proxy permission on enable, or delete the path. Done: deleted (`registration.js` and the optional `webAuthenticationProxy` permission); the shim relay covers every browser, see design D4. Verify: `grep -rn webAuthenticationProxy browser-extension` finds nothing.

## 3. Close out

- [x] 3.1 Set rows `clients-03` and `clients-05` to built and clear their defects, archive the change. Verify: parity_verify --strict.

