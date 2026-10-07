## MODIFIED Requirements

### Requirement: Offline and Native Boundaries
The feature MUST NOT introduce offline caching, offline vault access, an app-shell service worker, a Web Share Target or push notifications. Offline read caching is the `offline-readonly-cache` feature's responsibility. The native iOS and Android apps and their store listings are the `mobile-*` capabilities' responsibility, so this web app MUST NOT duplicate them, for example by registering as a system autofill provider.

#### Scenario: No offline caching is introduced
- GIVEN the installed PWA without connectivity
- WHEN the user opens it
- THEN it MUST behave like the live-fetch web app with no cached secrets, offline caching remaining with `offline-readonly-cache`

#### Scenario: The web app stays a web app next to the native apps
- GIVEN a phone with the Keepiq app installed from a store and the web app added to the home screen
- WHEN the user opens the web app
- THEN it MUST work as before, without offering system autofill or a store install of its own
