## ADDED Requirements

### Requirement: Add form for one type
The extension SHALL open the add form fixed to one type when the context menu asks for it (`context-menu` § Add entries open a form for one type), for `login`, `card` or `identity`, resolved by the cached system type of that name. The form MUST NOT show the Type select, and its title is "New login" (nl "Nieuwe login"), "New card" (nl "Nieuwe kaart") or "New identity" (nl "Nieuwe identiteit"). Otherwise it is the Add item form for that type, prefilled as from the New button, with Save and Cancel; Cancel returns to the vault list. When the cached types hold no such system type, the popup opens on the vault list.

#### Scenario: Card form
- **GIVEN** the context menu asked for a new card
- **WHEN** the popup opens
- **THEN** it shows "New card" with Name, the card fields, Folder, Additional fields and Notes, and no Type select

#### Scenario: Save
- **GIVEN** the "New identity" form with a name and an email
- **WHEN** the user saves
- **THEN** `item.create` sends an `identity` item, and the popup opens the new item, as from the New button
