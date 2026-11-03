## MODIFIED Requirements

### Requirement: Autofill suggestions section
The Vault tab SHALL show an "Autofill suggestions" section above the item list, listing the items the background matches to the popup's tab under the active URI match rule (`candidatesFor()` in `src/autofill/matcher.ts`), in the background's order (last used, then name), followed by every card while "Always show cards as Autofill suggestions on Vault view" is on and every identity while "Always show identities as Autofill suggestions on Vault view" is on, each in last used then name order (`card-identity-fill`). Blocked rows are never listed. Clicking a card or its Fill button fills the tab, as autofill and `card-identity-fill` specify; View stays in the card's More menu. When no item is listed, the section shows "No items for <host>". When the tab has no `http:` or `https:` URL, the section shows "Autofill is not available on this page" and offers no item. The section ignores the folder, type and search filters.

#### Scenario: Matching items
- **GIVEN** the active tab is `https://login.example.co.uk/x` and two items have urls under `example.co.uk`
- **WHEN** the Vault tab renders
- **THEN** both items appear under "Autofill suggestions" and also in the main list

#### Scenario: No matching items
- **GIVEN** the active tab is `https://nothing.test` and the vault holds no card or identity
- **WHEN** the Vault tab renders
- **THEN** the section shows "No items for nothing.test"

#### Scenario: Browser page
- **GIVEN** the active tab is `chrome://newtab/`
- **WHEN** the Vault tab renders
- **THEN** the section shows "Autofill is not available on this page" and no item is offered

#### Scenario: Blocked match
- **GIVEN** a matching item is blocked
- **WHEN** the section renders
- **THEN** the item is not listed in the section
- **AND** it still appears in the main list

#### Scenario: Cards and identities after the logins
- **GIVEN** the active tab is `https://shop.test`, one matching login, one card and one identity, both Vault view settings on
- **WHEN** the Vault tab renders
- **THEN** the section lists the login, then the card, then the identity

#### Scenario: Cards turned off
- **GIVEN** "Always show cards as Autofill suggestions on Vault view" off
- **WHEN** the Vault tab renders on any page
- **THEN** no card is listed in the section and cards still appear in the main list
