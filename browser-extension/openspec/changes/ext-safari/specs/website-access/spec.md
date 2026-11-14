## Purpose

Gets Keepiq access to websites in Safari, which runs content scripts and background requests only for sites the user allowed, so the inline list, save bar, passkeys and sync work.

## ADDED Requirements

### Requirement: Website access state
On Safari the popup SHALL read the website access state from the browser each time it renders it: "server" while the active account's server origin (`<origin>/*`) is not granted, otherwise "sites" while `https://*/*` and `http://*/*` are not both granted, otherwise "all". It reads again when a permission is added or removed while the popup is open and after each of its buttons. Other browsers have no such state and show none of this capability's text.

#### Scenario: Chrome
- **GIVEN** the extension on Chrome
- **WHEN** the Vault tab and the Autofill view render
- **THEN** neither shows a website access card or entry

#### Scenario: Allowed on every website
- **GIVEN** Safari with Keepiq allowed on every website
- **WHEN** the Vault tab renders
- **THEN** no website access card is shown and Settings → Autofill shows "Website access" "Every website"

### Requirement: Website access card
While unlocked on Safari, the Vault tab SHALL show a card above Autofill suggestions:
- in state "sites", unless dismissed: the title "Let Keepiq work on websites" (nl "Laat Keepiq werken op websites"), the text "Safari runs Keepiq only on websites you allow. Allow it on every website to fill in logins, save new ones and use passkeys wherever you sign in." (nl "Safari laat Keepiq alleen werken op websites die je toestaat. Sta het toe op alle websites om overal inloggegevens in te vullen, nieuwe op te slaan en passkeys te gebruiken."), a primary button "Allow on every website" (nl "Op alle websites toestaan") and a button "Not now" (nl "Niet nu");
- in state "server", never dismissible: the title "Keepiq can't reach {host}" (nl "Keepiq kan {host} niet bereiken"), the text "Safari doesn't let Keepiq reach your server, so your vault can't sync." (nl "Safari geeft Keepiq geen toegang tot je server, dus je kluis kan niet synchroniseren.") and a primary button "Allow {host}" (nl "{host} toestaan").

"Not now" stores `websiteAccessDismissed: true` in `settings.global` in `storage.local`, kept until the extension is removed, and hides the "sites" card only.

#### Scenario: Some sites only
- **GIVEN** Safari, an unlocked account whose server is granted, and Keepiq not allowed on every website
- **WHEN** the Vault tab renders
- **THEN** the "Let Keepiq work on websites" card is shown above Autofill suggestions

#### Scenario: Not now
- **GIVEN** the "sites" card on the Vault tab
- **WHEN** the user chooses Not now
- **THEN** the card goes, and stays gone on the next popup open and after a browser restart

#### Scenario: Server not granted
- **GIVEN** Safari and the active account's server set to Deny in Safari's settings
- **WHEN** the Vault tab renders
- **THEN** the "Keepiq can't reach <host>" card is shown without Not now, also after an earlier Not now

### Requirement: Asking for access
"Allow on every website" SHALL request the origins `https://*/*` and `http://*/*` with `permissions.request` in the click, and "Allow {host}" the origin `<server origin>/*`. Afterwards the state is read again. When it still is not granted, the card and the Settings entry show, as a status message, "In Safari, choose Safari > Settings, then Websites. Select Keepiq in the list and set "When visiting other websites" to Allow." (nl "Kies in Safari Safari > Instellingen en dan Websites. Selecteer Keepiq in de lijst en zet "Bij bezoeken van andere websites" op Sta toe.") and "To allow one site at a time, click the Keepiq button on that site and choose to always allow it." (nl "Wil je per website toestaan? Klik op die website op de Keepiq-knop en kies om het altijd toe te staan.").

#### Scenario: Safari grants
- **GIVEN** the "sites" card
- **WHEN** the user chooses Allow on every website and Safari grants it
- **THEN** the card goes

#### Scenario: Safari does not grant every website
- **GIVEN** the "sites" card
- **WHEN** the user chooses Allow on every website and both origins still read as not granted
- **THEN** the card stays and shows the Settings steps and the one-site-at-a-time line

### Requirement: Website access in Settings
On Safari, Settings → Autofill SHALL show a "Website access" (nl "Toegang tot websites") entry after the keyboard shortcut, reading "Every website" (nl "Alle websites"), "Only websites you allowed" (nl "Alleen websites die je toestond") or "Not your Keepiq server" (nl "Niet je Keepiq-server") for the three states. Unless the state is "all", it offers the matching Allow button and, after a request that granted nothing, the steps; it ignores `websiteAccessDismissed`.

#### Scenario: After Not now
- **GIVEN** the "sites" card dismissed with Not now
- **WHEN** the user opens Settings → Autofill
- **THEN** "Website access" reads "Only websites you allowed" with Allow on every website
