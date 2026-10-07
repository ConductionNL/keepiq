## ADDED Requirements

### Requirement: Favourite items per holder

The system MUST let the holder of a secret, owner or recipient, mark it as a favourite through `PUT /api/v1/secrets/{id}/favourite` and from a star on the secret list row and in the secret detail sidebar. The flag MUST belong to the holder's own row: marking a shared copy MUST NOT change the owner's row or any other recipient's row, and an owner's edit of the secret MUST NOT clear a recipient's flag. The secret list MUST offer a Favourites filter that shows only the holder's favourites.

#### Scenario: A vault user stars a login and filters on favourites

- **GIVEN** a vault user with 120 secrets on the secret list at /secrets
- **WHEN** the user clicks the star on two secrets and picks Favourites in the filter menu
- **THEN** the list shows exactly those two secrets
- **AND** the filter button shows that a filter is active

#### Scenario: A recipient's star survives the owner's edit

- **GIVEN** a colleague who starred their copy of a shared secret
- **WHEN** the owner changes the secret's password
- **THEN** the colleague's copy is still starred

### Requirement: Tags per holder

The system MUST let the holder of a secret set tags on it in the create and edit dialogs, through `PUT /api/v1/secrets/{id}/tags`, and in bulk from the selection strip. Tags MUST be trimmed, lowercased, at most 32 characters each and at most 20 per secret. Tags MUST be stored in plain text and the tags field MUST say so. The secret list MUST show tags as chips on each row and MUST offer the holder's tags in the filter menu; picking a tag MUST narrow the list to secrets with that tag.

#### Scenario: A vault user labels items across folders and filters by tag

- **GIVEN** a vault user with secrets in three folders
- **WHEN** the user tags one secret in each folder with `on call` and picks `on call` in the filter menu
- **THEN** the list shows those three secrets and no others

#### Scenario: A vault user removes a tag in bulk

- **GIVEN** three secrets tagged `finance`
- **WHEN** the user selects them and chooses Remove tag `finance` in the selection strip
- **THEN** none of the three shows the `finance` chip and the tag no longer appears in the filter menu

### Requirement: Sort by date last used

The system MUST record `last_used_at` on the holder's row when the holder opens the secret's value through `GET /api/v1/secrets/{id}` or fills it from the browser extension, which reports the fill through `POST /api/v1/extension/used/{id}`. The used-route MUST return 404 for a secret the caller does not hold. Loading the list or searching MUST NOT change `last_used_at`. The secret list MUST offer Last used in its sort options, with never-used secrets last.

#### Scenario: A vault user sorts by last used

- **GIVEN** a vault user who opened secret A yesterday and filled secret B from the extension an hour ago
- **WHEN** the user picks Last used in the sort menu of the secret list
- **THEN** B is first and A is second
- **AND** secrets never opened or filled come after all used ones

#### Scenario: The used-route cannot probe another user's secret

- **GIVEN** a paired browser extension of one user
- **WHEN** it calls `POST /api/v1/extension/used/{id}` with the id of another user's secret
- **THEN** the response is 404 and nothing is recorded
