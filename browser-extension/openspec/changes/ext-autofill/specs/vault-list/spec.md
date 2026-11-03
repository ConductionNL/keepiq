## MODIFIED Requirements

### Requirement: Autofill suggestions section
The Vault tab SHALL show an "Autofill suggestions" section above the item list, listing the items the background matches to the popup's tab under the active URI match rule (`candidatesFor()` in `src/autofill/matcher.ts`), in the background's order (last used, then name). Blocked rows are never listed. Clicking a card or its Fill button fills the tab, as autofill specifies; View stays in the card's More menu. When no item matches, the section shows "No items for <host>". When the tab has no `http:` or `https:` URL, the section shows "Autofill is not available on this page" and offers no item. The section ignores the folder, type and search filters.

#### Scenario: Matching items
- **GIVEN** the active tab is `https://login.example.co.uk/x` and two items have urls under `example.co.uk`
- **WHEN** the Vault tab renders
- **THEN** both items appear under "Autofill suggestions" and also in the main list

#### Scenario: No matching items
- **GIVEN** the active tab is `https://nothing.test`
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

### Requirement: Alphabetical sorting
The list SHALL be sorted by `name` using locale-aware, case-insensitive comparison, with ties broken by `id` so the order is stable across renders. The autofill suggestions keep the background's order: last used, then name.

#### Scenario: Mixed case names
- **GIVEN** items named "bank", "Amazon" and "apple"
- **WHEN** the list renders
- **THEN** the order is Amazon, apple, bank
