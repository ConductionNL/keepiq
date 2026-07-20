---
status: proposed
---

# Offline Read-only Cache

## Purpose

Give Doriath users read-only access to their vault with no network, via an
encrypted local cache (IndexedDB) refreshed on each online session and a service
worker that serves the app shell offline — preserving ADR-003's zero-knowledge
model (cached ciphertext stays encrypted; offline unlock re-derives the master
key locally) and giving admins an org-wide off switch.

## ADDED Requirements

### Requirement: Online sessions write through an encrypted local snapshot

Doriath SHALL, on each successful online unlock (when offline caching is enabled),
write an encrypted IndexedDB snapshot of the active suite's wrapped private-key
blob and KDF parameters, every secret's RSA ciphertext, and the folder tree — with
plaintext secret names/URLs and folder metadata encrypted at rest under the vault
unlock key, and the whole snapshot replaced atomically.

#### Scenario: Unlocking online populates the cache

- **GIVEN** offline caching is enabled and a user unlocks their vault online
- **WHEN** the write-through completes
- **THEN** the IndexedDB snapshot MUST contain the wrapped private-key blob, its
  KDF parameters, every secret's RSA ciphertext, the folder tree, and a server
  sync timestamp
- **AND** secret names/URLs and folder names MUST be stored encrypted at rest under
  the vault unlock key, never as plaintext

#### Scenario: Refresh replaces the prior snapshot atomically

- **GIVEN** a user with an existing cached snapshot
- **WHEN** a new online refresh runs
- **THEN** the new snapshot MUST replace the old one in a single transaction with
  no half-updated intermediate state

### Requirement: Offline unlock re-derives the master key locally and opens the vault read-only

Doriath SHALL let a user unlock and read their vault with no network by deriving
the AES key from the entered master password using the cached KDF parameters,
unwrapping the cached private key into a non-extractable `CryptoKey`, and decrypting
cached ciphertext client-side — with the master password never leaving the browser.

#### Scenario: Offline unlock opens the vault for reading

- **GIVEN** a populated cache and no network connectivity
- **WHEN** the user enters their correct master password on the lock screen
- **THEN** the browser MUST derive the AES key from the cached KDF parameters,
  unwrap the private key, import a non-extractable `CryptoKey`, and decrypt cached
  metadata and secret ciphertext for display
- **AND** no server request MUST be required to complete the unlock

#### Scenario: App shell loads with no network

- **GIVEN** a registered service worker and no network connectivity
- **WHEN** the user opens Doriath
- **THEN** the app shell (HTML/JS/CSS) MUST load from the service worker cache
- **AND** the service worker MUST NOT cache any decrypted secret material

### Requirement: Offline mode is strictly read-only

Doriath SHALL disable all create, update, share, and delete actions while offline
and SHALL explain why, rather than queuing writes.

#### Scenario: Write actions are disabled offline

- **GIVEN** a user viewing their vault offline
- **WHEN** they attempt to create, edit, share, or delete a secret
- **THEN** the action MUST be prevented
- **AND** the user MUST be shown an explanation that Doriath is read-only offline

### Requirement: A stale-data banner shows the last sync time when serving from cache

Doriath SHALL display a stale-data banner with the last successful sync time
whenever vault data is served from the local cache rather than a fresh online fetch.

#### Scenario: Banner appears when reading cached data offline

- **GIVEN** a user reading their vault from the offline cache
- **WHEN** the vault view renders
- **THEN** a banner MUST indicate the data is offline/cached and show the last
  successful sync time

#### Scenario: Banner clears after a fresh online refresh

- **GIVEN** a user who was offline and reconnects
- **WHEN** an online refresh completes successfully
- **THEN** the stale-data banner MUST clear

### Requirement: An admin can disable offline caching org-wide

Doriath SHALL provide an admin setting that disables offline caching for the whole
instance, and when disabled SHALL write no cache and purge any existing cache on
next load.

#### Scenario: Disabling offline caching purges existing caches

- **GIVEN** an admin sets `offline_cache_enabled` to false
- **WHEN** a user next loads Doriath
- **THEN** no new snapshot MUST be written
- **AND** any existing local snapshot MUST be purged

### Requirement: The cache is evicted on lock, logout, and suite rotation

Doriath SHALL clear the local snapshot on vault lock, on logout, and on suite
rotation or compromise recovery, so cached ciphertext never outlives the key that
protects it.

#### Scenario: Locking the vault clears the cache metadata key and snapshot

- **GIVEN** a user with a populated offline cache
- **WHEN** they lock the vault or log out
- **THEN** the local snapshot MUST be cleared

#### Scenario: Suite rotation evicts the now-undecryptable cache

- **GIVEN** a user with a populated offline cache
- **WHEN** compromise recovery rotates their EncryptionSuite to a new RSA key pair
- **THEN** the local snapshot MUST be evicted because its cached ciphertext can no
  longer be decrypted with the new suite
