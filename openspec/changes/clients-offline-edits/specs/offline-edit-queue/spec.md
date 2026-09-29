## ADDED Requirements

### Requirement: Offline changes go into a sealed local queue

When the administrator setting `offline_edits_enabled` is true and the vault is served from the offline cache, the web app MUST let the user create, edit, move and delete secrets, and MUST store each change as a queue entry in IndexedDB. Each entry's values and index fields (`name`, `url`, `typeId`, `folderId`) MUST be sealed to the owner's own certificate; only the entry id, the operation, the secret id, `baseUpdatedAt`, the queue time and the suite id MAY be stored in plain. The vault view MUST show queued changes marked as not synced.

#### Scenario: A field worker rotates a password with no signal

- **GIVEN** a vault owner reading their vault offline with offline edits enabled
- **WHEN** they edit the password of the secret "Pump station router" in the secret list at /secrets and save
- **THEN** the secret MUST show the new value marked "Not synced yet"
- **AND** the IndexedDB queue MUST hold one entry whose stored form contains neither the new password nor the name "Pump station router" in plain

#### Scenario: Offline edits disabled keeps the cache read-only

- **GIVEN** offline edits are disabled by the administrator
- **WHEN** a vault owner reading offline tries to edit a secret
- **THEN** the action MUST be prevented with an explanation that Keepiq is read-only offline

### Requirement: Sharing and membership actions stay online-only

The web app MUST keep sharing, unsharing, link shares, sends, team-folder membership, folder create and delete, and attachment actions unavailable while the vault is served from the offline cache, whatever the offline edits setting, and MUST explain why.

#### Scenario: Share is disabled offline

- **GIVEN** a vault owner reading offline with offline edits enabled
- **WHEN** they open the share dialog for a secret
- **THEN** the share action MUST be disabled with an explanation that sharing needs a connection

### Requirement: Replay runs through the online paths with a fresh fan-out

When the browser is online and the vault is unlocked, the web app MUST replay queued entries oldest first through the same create, update and delete actions an online edit uses. For a secret with recipients it MUST fetch the write context at replay time and encrypt the new value for each recipient certificate returned at that moment. The queue MUST NOT contain, and replay MUST NOT send, recipient ciphertext made before the replay.

#### Scenario: A recipient added while offline receives the change

- **GIVEN** a vault owner who edited a shared secret offline, and a second recipient added to that secret by a co-owner in the meantime
- **WHEN** the owner reconnects and unlocks
- **THEN** the replay MUST encrypt the new value for both the original and the new recipient using certificates fetched at replay time
- **AND** both recipients MUST see the new value

### Requirement: Concurrent server changes are never overwritten silently

`PUT /api/v1/secrets/{id}` and `DELETE /api/v1/secrets/{id}` MUST accept an optional `baseUpdatedAt`; when it is present and differs from the stored `updatedAt`, the server MUST change nothing and answer `409 Conflict` with the current row. On a 409 the web app MUST stop that entry and let the user keep their offline version or the server version.

#### Scenario: The secret changed online meanwhile

- **GIVEN** a vault owner who edited a secret offline, and the same secret changed from another browser after the snapshot was taken
- **WHEN** the owner reconnects and the replay sends the update with the snapshot's `baseUpdatedAt`
- **THEN** the server MUST answer 409 and leave the secret unchanged
- **AND** the web app MUST show both versions and apply the owner's choice

#### Scenario: Clients without the precondition are unaffected

- **GIVEN** an online client that sends `PUT /api/v1/secrets/{id}` without `baseUpdatedAt`
- **WHEN** the request is processed
- **THEN** the server MUST update the secret as it did before this change

### Requirement: Failed entries are kept, never dropped silently

A replay answered with 403 or 404 MUST move the entry to a failed list where the user can copy their values (decrypted in the browser) or discard the entry. A 423 or a network error MUST keep the entry queued. An entry whose owner row saved but whose recipient sync failed MUST retry only the sync step.

#### Scenario: Write grade removed while offline

- **GIVEN** a team-folder member who edited a folder secret offline, and whose grade was lowered to read meanwhile
- **WHEN** the replay's sync request is refused with 403
- **THEN** the entry MUST appear under "Changes that could not sync" with copy and discard actions

### Requirement: Pending changes block logout and rotation

The web app MUST warn before logout while entries are pending, MUST refuse to start a suite rotation while entries are pending, and after a rotation made on another device MUST ask once for the previous master password to reopen the entries with the cached old suite envelope, or let the user discard them.

#### Scenario: Rotation waits for the queue

- **GIVEN** a vault owner with two pending offline changes
- **WHEN** they start a compromise recovery rotation
- **THEN** the web app MUST refuse and ask them to sync or discard the changes first

### Requirement: Administrators control offline edits

The system MUST provide an admin setting `offline_edits_enabled`, default false, shown next to `offline_cache_enabled` and carried in the offline manifest. When offline caching is disabled, the next online unlock MUST replay the queue before purging the cache and the queue.

#### Scenario: Administrator enables offline edits

- **GIVEN** an administrator on the offline cache section of the Keepiq admin settings
- **WHEN** they turn on offline edits and a user next syncs online
- **THEN** the offline manifest MUST report `offlineEditsEnabled` true and the user's offline view MUST allow edits
