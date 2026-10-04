## ADDED Requirements

### Requirement: A fill reaches only frames on the matched site
Each content script MUST tell the worker when its frame loads. The worker MUST record the frame's host from the browser's sender record, never from the page's message, and a new top-level page MUST replace the frames recorded for the tab before. A fill and a one-time code MUST be sent only to the frames of the tab whose recorded host is the matched host. Without a record (the worker restarted), only the top frame receives it, after the worker has checked that the tab is on the matched site. Each frame still checks its own host before it fills.

#### Scenario: A page with frames of several sites
@e2e exclude Browser-extension worker frame routing. Covered by tests/extension/fillCapture.spec.js ("reaches only the frames the browser places on the matched site") and ("falls back to the top frame alone when no frame was recorded").
- **GIVEN** a tab on example.com with an advert frame, a frame of another site that claims example.com, and a second example.com frame
- **WHEN** the user fills a login for example.com
- **THEN** only the top frame and the second example.com frame receive it

### Requirement: Blocked items are never offered
The worker MUST leave out a row the server marks blocked (its key cannot be used here) from the logins it offers for a site, online as well as offline. The server already sends such a row without ciphertext.

#### Scenario: The server returns a blocked row
@e2e exclude Browser-extension worker. Covered by tests/extension/fillCapture.spec.js ("never offers a blocked row, even when the server returns it").
- **GIVEN** a site match that includes a blocked row
- **WHEN** the popup asks for logins for the site
- **THEN** the blocked row is not among them

### Requirement: Ask before filling an https login into an http page
When a login was saved for an https address and the page is plain http, the worker MUST NOT fill until the user confirms in the popup. The popup MUST say the page is not secure and that anyone on the network could read what is filled in. Nothing fills when the user declines.

#### Scenario: An http page
@e2e exclude Browser-extension worker and popup. Covered by tests/extension/fillCapture.spec.js ("asks before filling an https login into a plain http page") and ("shows the question in the popup and fills nothing on no").
- **GIVEN** a login saved for https://example.com and a tab on http://example.com
- **WHEN** the user picks it
- **THEN** the popup asks first, and fills only on yes

### Requirement: The save prompt trusts the browser, not the page
A submitted login MUST be held for the tab it was submitted in, with its site taken from the browser's sender record, never from the page's message. It MUST expire after five minutes and be dropped when its tab closes or its account locks. The popup's prompt and the in-page decision MUST act only on the capture of their own tab. The popup MUST NOT receive the captured password, and saving MUST store what the browser saw submitted, whatever the popup sends.

#### Scenario: A page lies about its site
@e2e exclude Browser-extension worker. Covered by tests/extension/fillCapture.spec.js ("takes the site from the browser, not from what the page says").
- **GIVEN** a page on evil.example that reports bank.example
- **WHEN** a login is submitted there
- **THEN** the capture is held for evil.example and the popup sees no password

#### Scenario: Tabs and time
@e2e exclude Browser-extension worker. Covered by tests/extension/fillCapture.spec.js ("holds a capture for its own tab only"), ("lets a capture expire after five minutes") and ("drops a capture when its tab closes").
- **GIVEN** a login submitted in one tab
- **WHEN** the popup opens over another tab, five minutes pass, or the tab closes
- **THEN** no capture is offered

#### Scenario: Saving
@e2e exclude Browser-extension worker. Covered by tests/extension/fillCapture.spec.js ("saves only what the browser saw submitted, and nothing without a capture").
- **GIVEN** a held capture, or none
- **WHEN** the popup asks to save with a password of its own
- **THEN** the held capture is saved under its own site, and without one nothing is saved
