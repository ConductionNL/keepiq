## ADDED Requirements

### Requirement: A value change saves every copy in one request
The extension SHALL save an edit whose body carries `key`, `login` or `additionalFields` through `PUT /api/v1/secrets/{id}/share-save`, and MUST save any other edit through `PUT /api/v1/secrets/{id}` as ext-vault-edit specifies. It MUST read `GET /api/v1/secrets/{id}/write-context` first and take exactly one path from it: the owner path when `sourceSecretId` is the edited id, the copy-editor path when it is not and `effectiveGrade` is `owner`, `write` or `manage`, and otherwise none, since that copy is view-only. The first share-save MUST carry the patch, `baseUpdatedAt` and empty lists; on 409 `incomplete` the background MUST encrypt for what the answer requires, drop what it no longer requires and send again, and MUST fail the save with `incomplete` after three rounds, where each `incomplete` answer and each `stage_unknown` restart counts as one. Nothing about a save MUST outlive its request, beyond the form's record of what it opened, which a successful save drops, and verified partner certificates, kept in background memory for up to 5 minutes.

#### Scenario: Password change of an owned, shared item
- **GIVEN** Alice owns "Wifi" and shared it with Bob
- **WHEN** she changes its password in the extension and saves
- **THEN** the first share-save MUST carry no copies, the second MUST carry Bob's copy, and Alice's row and Bob's copy MUST change in the same request
- **AND** no `PUT /api/v1/secrets/{id}` and no `/sync` request MUST be sent

#### Scenario: Rename only
- **GIVEN** Alice owns "Wifi" and shared it with Bob
- **WHEN** she renames it and saves
- **THEN** `write-context` and `PUT /api/v1/secrets/{id}` MUST be sent, and no share-save

#### Scenario: Item that is not shared
- **GIVEN** Alice owns "Bank", which is shared with nobody
- **WHEN** she changes its password
- **THEN** one share-save with empty lists MUST save it, and no certificate MUST be looked up

#### Scenario: The share list keeps changing
- **GIVEN** the server answers three share-saves in a row with `incomplete`, naming a new recipient each time
- **WHEN** Alice saves a new password
- **THEN** the save MUST fail with `incomplete`, the form MUST stay open with "The people this item is shared with kept changing while it was saved. Nothing was saved; please try again.", and no fourth share-save MUST be sent

### Requirement: An owner's change reaches every local copy
On the owner path the extension SHALL fill every copy the `incomplete` answer requires, whether direct, group or team-folder, read recipient certificates with `POST /api/v1/shares/recipient-certificates` in batches of at most 100 distinct user ids, and encrypt to each certificate with RSA-OAEP as ADR-003 defines. Each copy entry MUST carry `secretId` and exactly the value fields the patch carries: `key` when it changed, `login` when it changed (`null` when cleared) and `additionalFields` when it changed, so an unchanged field is never cleared. A recipient answered `shareable: false` MUST be listed under `skipped` with `no_vault_key`; when the server refuses that skip, the background MUST read that recipient's certificate again once. A certificate that does not import MUST fail the save with `bad_certificate`. Use-only and expiring copies MUST be updated like any other, since their limits bind the holder, not the owner. Above 100 entries the save MUST go in staged chunks of at most 100 per list, committed by one final call.

#### Scenario: Group and team-folder copies
- **GIVEN** Alice shared "Wifi" directly with Bob, with the group Staff (Carol, Dan) and through the team folder Ops (Erin)
- **WHEN** she changes its password
- **THEN** one share-save MUST carry a copy entry for each of the four copies, each encrypted to that recipient's certificate

#### Scenario: Only the changed field
- **GIVEN** Alice changed only the username of a shared item
- **WHEN** the save fills the copies
- **THEN** each copy entry MUST contain `secretId` and `login` and MUST NOT contain `key` or `additionalFields`

#### Scenario: Cleared username
- **GIVEN** Alice emptied the username of a shared item
- **WHEN** the save fills the copies
- **THEN** the patch and each copy entry MUST contain `"login": null`

#### Scenario: Recipient without a vault key
- **GIVEN** Bob's suite was revoked, so the certificate lookup answers him as not shareable
- **WHEN** Alice changes the password
- **THEN** Bob's copy MUST be listed under `skipped` with `no_vault_key`, and the save MUST go through for everyone else

#### Scenario: Certificate that does not import
- **GIVEN** the server serves Carol a certificate that does not import
- **WHEN** Alice changes the password
- **THEN** nothing MUST be saved, and the form MUST say "Keepiq sent an encryption key for someone this item is shared with that this browser could not read. Nothing was saved."

#### Scenario: Many recipients
- **GIVEN** an item shared through a team folder with 230 members
- **WHEN** its owner changes the password
- **THEN** the certificates MUST be read in three requests of at most 100 user ids
- **AND** the copies MUST go in two chunks of 100 with `stage.final` `false` and a final call with the last 30 and the patch

### Requirement: A team editor's change reaches the owner and every other copy
On the copy-editor path the extension SHALL send the share-save for the editor's own copy with `sourceBaseUpdatedAt` set to the `sourceUpdatedAt` the form's edit fetch read, or, without that record, the `sourceUpdatedAt` of its own `write-context` read. It MUST encrypt the patch's value fields to `ownerCertificate` for the source and to each recipient certificate for every other copy the answer requires, and MUST NOT send partner shares. When the reply says `ownerOnly`, the item's detail MUST say that partner organisations get the change with the owner's next save.

#### Scenario: Group member once the server treats them as a writer
- **GIVEN** Alice shared "Router" with the group Staff, and the server answers Bob's `write-context` with `write` (keepiq#1285)
- **WHEN** Bob changes the password of his copy
- **THEN** the save MUST take the copy-editor path, as for a team-folder editor, and reach Alice's source and every other member's copy

#### Scenario: Editor in a team folder
- **GIVEN** Bob holds a `write` grade on the team folder that holds Alice's "Router" and its copies for Bob and Carol
- **WHEN** Bob changes the password of his copy
- **THEN** the share-save MUST go to Bob's copy id with `sourceBaseUpdatedAt` and copy entries for the source and Carol's copy, and none for Bob's copy

#### Scenario: The original changed after the form opened
- **GIVEN** Bob opened his copy of "Router" for editing, and Alice then changed the source
- **WHEN** Bob saves a new password
- **THEN** the server MUST answer 409 `stale_source`, nothing MUST be saved, and the form MUST say "The original of this shared item was changed after you opened it. Nothing was saved; open it again and redo your change."

#### Scenario: Partners stay with the owner
- **GIVEN** Alice's "Router" is also shared with `bob@cloud.partner.example`, and Carol holds a `write` grade on it
- **WHEN** Carol saves a new password and the reply says `ownerOnly`
- **THEN** no partner certificate MUST be looked up, and the item's detail MUST say "Saved. Recipients at partner organisations get this change when the owner next saves it."

### Requirement: A copy without write permission is view-only
The extension SHALL treat a copy whose `write-context` names another `sourceSecretId` and an `effectiveGrade` other than `owner`, `write` or `manage` as view-only. When an item's detail opens, unless Edit is already unavailable because the item is blocked, use-only or read-only, the popup MUST ask the background with `item.editable`, which reads `write-context` and answers whether the item may be edited; a view-only copy MUST show Edit as unavailable on its detail with "Shared with you to view only. Ask its owner for edit rights to change it.", while Clone and Move stay available. The list row's More menu doesn't ask, so its Edit opens the form, whose edit fetch the background refuses with that text. Until the answer arrives, or when the read fails, Edit stays available. In the background, `item.fetch` for edit MUST refuse a view-only copy with `view_only` before anything is decrypted, and `item.update` MUST refuse any patch on it other than a move to a folder with `view_only`, before anything is written. The extension MUST NOT save anything but a folder move to a view-only copy.

#### Scenario: Viewer opens their copy
- **GIVEN** Carol holds a `read` grade, or a direct share without a grade, on "Router"
- **WHEN** she opens it
- **THEN** Edit MUST be unavailable with "Shared with you to view only. Ask its owner for edit rights to change it.", and Clone and Move MUST be available

#### Scenario: A save of a view-only copy from an older form
- **GIVEN** Carol's edit form for "Router" opened before her grade was lowered to `read`
- **WHEN** she saves a new password
- **THEN** nothing MUST be sent but `write-context`, and the form MUST show the view-only text

#### Scenario: Viewer files their copy
- **GIVEN** Carol holds a view-only copy of "Router"
- **WHEN** she moves it to her folder "Network"
- **THEN** the move MUST be saved with `PUT /api/v1/secrets/{id}` carrying only `folderId`

### Requirement: Partner-organisation recipients get the whole value the form showed
On the owner path the extension SHALL, for each partner share the `incomplete` answer requires, look up the recipient's certificate with `POST /api/v1/federation/recipient-certificate` and `cloudId` set to the required share's `recipientCloudId`, verify it in the background, encrypt the whole `key`, `login` and `additionalFields` to it, and list it under `federated` with `certFingerprint`, the lowercase hex SHA-256 of the verified certificate. The whole value MUST be the typed fields plus each other field decrypted from the ciphertext the saving form's own edit fetch opened in full, found by the form's `recordId`; the extension MUST NOT decrypt a fresh row or a reply to build it. When the form did not open every value field in full, its record is gone, or the record is of another version than the save's `baseUpdatedAt`, the save MUST fail with `unverified` before any partner lookup. The edit fetch MUST refuse a row whose ciphertext the vault cache does not hold before or after a vault sync, so the form never opens another item's values.

#### Scenario: A password change reaches Bob at a partner
- **GIVEN** Alice shared "VPN" with `bob@cloud.partner.example`, whose share is active
- **WHEN** she changes its password
- **THEN** the extension MUST verify Bob's certificate and send his share the new value, login and additional fields encrypted to it, with its fingerprint, in the same share-save as her row

#### Scenario: A field the form does not show
- **GIVEN** a card whose `key` holds a `recovery` member, which the card form has no field for, and a partner share with `bob@cloud.partner.example`
- **WHEN** Alice edits only the card's Notes and saves
- **THEN** nothing MUST be saved, no partner MUST be looked up, and the form MUST say "This item is shared with partner organisations, and this browser can't confirm they would get it exactly as you saw it. Nothing was saved; make this change in the Keepiq web app."

#### Scenario: The server answers the edit form with another item
- **GIVEN** the server answers the edit fetch for "Wifi" with the ciphertext of another item, before and after a vault sync
- **WHEN** Alice opens "Wifi" to edit it
- **THEN** nothing MUST be decrypted and the form MUST say "Keepiq sent a version of this item that your vault does not hold, so it was not opened."

#### Scenario: A field the form shows on every type
- **GIVEN** a note with a login and a `notes` member in `additionalFields`, and a partner share
- **WHEN** Alice opens it for editing
- **THEN** the form MUST show Username and Notes with those values, as the Keepiq web app does
- **AND** a save MUST send the partner share the whole value as shown

### Requirement: Partner certificates are verified before anything is encrypted to them
The extension SHALL accept a partner recipient's certificate only when each certificate is exactly one PEM block with nothing outside it and no bytes after its DER, no critical extension it does not understand and no extension twice, its chain is exactly the intermediate and the root, as a partner serves it, with an intermediate that is not self-issued, the leaf has exactly one common name, as a UTF8String, or a PrintableString, TeletexString or IA5String of printable ASCII, the SHA-256 of the chain's last certificate equals `partnerRootFingerprint`, each certificate's issuer equals the next one's subject and its signature verifies with the next one's key (RSASSA-PKCS1-v1_5 with SHA-256, or RSASSA-PSS with SHA-256 and MGF1-SHA-256), every certificate above the leaf carries basicConstraints with `cA=TRUE`, `keyCertSign` when it has `keyUsage`, and a `pathLenConstraint` the chain keeps, the leaf's common name names the share's `recipientCloudId` after scheme, trailing slash and host case are normalised, and the current time lies within the validity of every certificate, allowing 5 minutes of clock skew before `notBefore`, as the web app does. It MUST refuse with one of `malformed`, `untrusted_root`, `bad_chain`, `name_mismatch` or `not_valid_now`, the Keepiq web app's verifier's reasons, MUST run in the background with WebCrypto only, and MUST encrypt to the public key of the leaf it verified.

#### Scenario: Verified certificate
- **GIVEN** the Keepiq test chain for `bob@cloud.partner.example` and its pinned root
- **WHEN** it is verified
- **THEN** it MUST be accepted with the leaf's SHA-256 as lowercase hex and the leaf's public key

#### Scenario: Someone else's certificate
- **GIVEN** a chain that verifies but whose leaf names `mallory@cloud.partner.example`
- **WHEN** it is verified for Bob's share
- **THEN** it MUST be refused with `name_mismatch`

#### Scenario: Expired certificate
- **GIVEN** Bob's leaf certificate expired yesterday
- **WHEN** it is verified
- **THEN** it MUST be refused with `not_valid_now`

#### Scenario: A user's certificate as issuer
- **GIVEN** a leaf naming Bob signed with Mallory's own leaf key, followed by Mallory's leaf and the pinned chain
- **WHEN** it is verified for Bob's share
- **THEN** it MUST be refused with `bad_chain`

#### Scenario: Another certificate in front of Bob's
- **GIVEN** the lookup's `certificate` holds base64 of another certificate in front of Bob's PEM block
- **WHEN** it is verified
- **THEN** it MUST be refused with `malformed` and nothing MUST be encrypted to either key

### Requirement: A partner the save can't reach stops it, unless the user saves without them
A partner share whose certificate fails verification, or whose lookup answers `unknown_recipient`, `not_a_partner` or the server's own `bad_chain`, SHALL be listed under `suspend` with that reason and MUST NOT be sent ciphertext; the save goes on. A lookup answered 502 `partner_unreachable` MUST fail the save with `partner_unreachable` and the shares not reached, by share id and cloud id, unless the `item.update` named that share in `withoutUnreachable`; then it MUST be listed under `suspend` with `partner_unreachable`. The form MUST name the partners not reached and offer Save without them, saying that it pauses sharing with them until the item is shared with them again. A lookup answered 429 MUST fail the save with `partner_lookup_limit` without waiting, and one answered 409 `federation_unavailable` with `federation_unavailable`, without an offer to try again. A certificate verified within the last 5 minutes for the same share and `recipientCloudId` MAY be used again without a lookup; kept certificates MUST live in background memory only and MUST be forgotten on every lock, logout and account switch. Any other lookup failure, including a 200 answer without a `certificate` string, a `chain` of two strings and a 64-digit hex `partnerRootFingerprint`, is this server's and MUST fail the save with its ordinary write code, suspending nothing.

#### Scenario: Certificate from another root
- **GIVEN** the lookup for Bob returns a chain that ends at a root other than the pinned one
- **WHEN** Alice saves a new password
- **THEN** no ciphertext MUST be sent for Bob, his share MUST be listed under `suspend` with reason `untrusted_root`, and the save MUST go through
- **AND** the item's detail MUST say "Sharing with bob@cloud.partner.example is paused, because their certificate could not be verified or their organisation no longer accepts it. The Keepiq web app shows the share."

#### Scenario: The partner serves the wrong chain
- **GIVEN** Alice's server answers Bob's lookup with 502 `bad_chain`
- **WHEN** Alice saves a new password
- **THEN** Bob's share MUST be listed under `suspend` with `bad_chain`, and the save MUST go through

#### Scenario: Partner unreachable
- **GIVEN** Bob's partner instance does not answer, so the lookup returns 502 `partner_unreachable`
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, and the form MUST name `bob@cloud.partner.example` and offer Save without them
- **AND** when Alice presses it, the save MUST go through with Bob's share under `suspend` with `partner_unreachable`, looking up again only partners not verified within the last 5 minutes
- **AND** the item's detail MUST say "Sharing with bob@cloud.partner.example is paused until you share this item with them again."

#### Scenario: The server can't share with partners
- **GIVEN** Alice's server answers the partner lookup with 409 `federation_unavailable`
- **WHEN** Alice saves a new password of an item with a partner share
- **THEN** nothing MUST be saved, and the form MUST say "Your Keepiq server can't share with partner organisations, so this item can't be saved here. Nothing was saved; ask your administrator." without Try again

#### Scenario: A second partner unreachable after Save without them
- **GIVEN** Alice pressed Save without them for Bob, and that save fails because Carol can't be reached either
- **WHEN** Alice presses Save without them again
- **THEN** the save MUST send both Bob's and Carol's share ids in `withoutUnreachable`, and the form MUST have named both
- **AND** after Alice changes the password, username or fields, the form MUST ask again for each partner

#### Scenario: Another partner fails only on Save without them
- **GIVEN** Alice pressed Save without them for Bob, and Carol's partner instance stops answering before that save
- **WHEN** the save looks Carol up
- **THEN** nothing MUST be saved, and the form MUST name Carol and offer Save without them again

#### Scenario: Lookup rate limit
- **GIVEN** an item with 70 partner shares, and the lookup answers 429 after 60
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, no further lookup MUST be sent, and the form MUST say "Too many partner lookups. Nothing was saved; try again in a minute."

#### Scenario: A local error never suspends a partner
- **GIVEN** Alice's own server answers Bob's certificate lookup with 500
- **WHEN** Alice saves a new password
- **THEN** nothing MUST be saved, Bob's share MUST NOT be listed under `suspend`, and the form MUST show the ordinary server error

### Requirement: A failed save writes nothing and says why
A save the server refuses, or that fails before the final share-save is answered, SHALL leave the form open with the draft and the reason, and MUST NOT be retried by the extension on its own. A 409 `stale` MUST be reported as `conflict`, `stale_source` as `stale_source`, no answer as `offline` and a 5xx as `server_error`, through the same mapping writes use; the share-save's own `not_allowed` or `forbidden` refusal MUST be reported as `forbidden`, and a policy refusal as `policy`.

#### Scenario: Changed elsewhere meanwhile
- **GIVEN** another browser saved "Wifi" after Alice opened it
- **WHEN** Alice saves a new password
- **THEN** the server MUST answer 409 `stale`, nothing MUST be saved, and the form MUST show the conflict message

#### Scenario: Server unreachable during the save
- **GIVEN** the server stops answering after the first `incomplete` answer
- **WHEN** the save sends the filled share-save
- **THEN** the form MUST stay open with the offline message, and saving again MUST send the whole save again

### Requirement: Item detail shows what the save left to others
The extension SHALL, after a save whose reply carries `shareSave`, close the form and open the item's detail with the "Item saved" toast as before, plus its notes: "Saved. Recipients at partner organisations get this change when the owner next saves it." for `ownerOnly`, the suspended partners' warning, naming their cloud ids, for `suspended`, and "Sharing with <cloud ids> is paused until you share this item with them again." for `paused`, the shares the user suspended through Save without them. The note MUST be held in popup memory for that item only and MUST be gone when the popup closes or another item's detail opens. A save that reached everyone carries no `shareSave` and shows nothing beyond the toast.

#### Scenario: Fully reached
- **GIVEN** Alice shared "Wifi" with Bob and Carol
- **WHEN** her new password reaches both
- **THEN** the detail MUST show only the "Item saved" toast

#### Scenario: Popup closed
- **GIVEN** the detail shows the note that partners get the change with the owner's next save
- **WHEN** Carol closes and reopens the popup and opens the item
- **THEN** no note MUST be shown

### Requirement: Plaintext and recipient keys stay in background memory
The decrypted value a save encrypts SHALL exist only in background memory for the request that needs it, and MUST NOT be written to any storage, sent to the popup or sent to a content script. Recipient certificates and lookup answers live in background memory for one save, except a verified partner certificate, kept there for up to 5 minutes as the previous requirement says. The popup receives only the note and failure codes, with partner share ids and cloud ids. Nothing new is written to `storage.local`; `storage.session` holds only the ciphertext an edit form opened and the row and source versions it read, per form, cleared when the key is. Every request goes from the background through the API client with `credentials: 'omit'`.

#### Scenario: What the popup sees
- **GIVEN** a save that reached Bob and suspended a partner share
- **WHEN** the popup receives the reply
- **THEN** it MUST contain no ciphertext, plaintext or certificate
