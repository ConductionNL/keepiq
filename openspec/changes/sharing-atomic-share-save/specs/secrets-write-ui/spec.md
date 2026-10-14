## ADDED Requirements

### Requirement: A failed share-save is shown and nothing is saved
The edit dialog MUST send the `updatedAt` of the version it opened on as `baseUpdatedAt` and, on a copy, the source version it opened on as `sourceBaseUpdatedAt`; offline that is the source version the cached row carries. When no source version is known, the share-save MUST take the source version of its own moment. A merge write-back of the secret still running when the dialog saves MUST be waited for, and both versions read again after it. When the share-save fails, the dialog MUST stay open and show a translated reason: the secret changed meanwhile (`stale`, `stale_source`), a recipient changed during the save (`incomplete` after three attempts), a partner can't be reached, or not being allowed to save for everyone it is shared with. A refusal that is not the share-save's own, such as a vault policy, MUST show the server's own message. After a copy editor's successful save, the dialog MUST say when partner shares receive the change only with the owner's next save.

#### Scenario: The secret changed while the dialog was open
- GIVEN a shared secret open in the edit dialog, and another browser saved it in the meantime
- WHEN the user saves a new password
- THEN the dialog MUST stay open with a message that the secret changed meanwhile, and nothing MUST be saved

#### Scenario: A vault policy keeps its own message
- GIVEN a work login in a team folder that a vault policy keeps there
- WHEN the user moves it out and saves
- THEN the dialog MUST show the policy's own refusal, not the sharing refusal
