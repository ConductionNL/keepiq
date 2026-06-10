## 1. Database Migrations and Seed Data

- [x] 1.1 Create ISchemaWrapper migration `Version000006Date20260604000000` for `doriath_secret_types` table with columns: id (UUID PK), name (string, unique index), label (string), scope (string enum: system/user/global), owner_id (string nullable), created_at (datetime)
- [x] 1.2 Create ISchemaWrapper migration `Version000007Date20260604000001` for `doriath_folders` table with columns: id (UUID PK), name (string), parent_id (FK nullable), owner_type (string enum: user/application), owner_id (string), created_at (datetime), updated_at (datetime); composite index on (owner_type, owner_id, parent_id)
- [x] 1.3 Create ISchemaWrapper migration `Version000008Date20260604000002` for `doriath_secrets` table with columns: id (UUID PK), name (string), url (string nullable), type_id (FK to secret_types), folder_id (FK nullable to folders), key (text), login (text nullable), additional_fields (text nullable), encryption_suite_id (FK to encryption_suites), owner_type (string), owner_id (string), possibly_compromised_at (datetime nullable), migration_error (text nullable), created_at (datetime), updated_at (datetime); indexes on (owner_type, owner_id), folder_id, encryption_suite_id
- [x] 1.4 Create `SeedSecretTypes` IRepairStep that upserts the 6 system SecretTypes with deterministic UUIDs (v5 from namespace `doriath:secret-type:{name}`), scope=system, owner_id=null; idempotent — skips existing types
- [x] 1.5 Register `SeedSecretTypes` as post-migration repair step in `info.xml`
- [x] 1.6 Create `SeedDevelopmentSecrets` IRepairStep (debug-only) that creates example secrets (GitHub login, AWS API key, Production Database, SSH Deploy Key, TLS Certificate, Server Room WiFi note) encrypted with the dev user's public certificate, plus two folders (Work, Personal) with secrets distributed across them
- [x] 1.7 Register `SeedDevelopmentSecrets` as post-migration repair step in `info.xml` (debug-only condition)

## 2. Entities and Mappers

- [x] 2.1 Create `SecretType` Doctrine entity in `lib/Db/SecretType.php` with all fields, JsonSerializable, and column type annotations
- [x] 2.2 Create `SecretTypeMapper` extending QBMapper in `lib/Db/SecretTypeMapper.php` with methods: findById(id), findByName(name), findSystemTypes(), findAvailableForUser(userId), findByScope(scope), countByName(name)
- [x] 2.3 Create `Folder` Doctrine entity in `lib/Db/Folder.php` with all fields and JsonSerializable
- [x] 2.4 Create `FolderMapper` extending QBMapper in `lib/Db/FolderMapper.php` with methods: findById(id), findByOwner(ownerType, ownerId), findChildren(parentId), findRootFolders(ownerType, ownerId), getPath(folderId) and getSubtreeIds(folderId) via iterative breadth-first traversal (DB-portable across PostgreSQL/MySQL/SQLite). Recursive secret counts are computed in the service via SecretMapper::countByFolderIds.
- [x] 2.5 Create `Secret` Doctrine entity in `lib/Db/Secret.php` with all fields and JsonSerializable; key, login, and additional_fields stored as encrypted blobs (text columns); jsonSerializeBlocked() omits ciphertext
- [x] 2.6 Create `SecretMapper` extending QBMapper in `lib/Db/SecretMapper.php` with methods: findById(id), findByOwner(...filters/sort/limit/offset), countByOwner, countByFolder, countByFolderIds, searchByNameOrUrl, findForUnifiedSearch, updateFolderForSecrets, deleteByFolderId, reassignType

## 3. Services (PHP)

- [x] 3.1 Create `SecretTypeService` with methods: getAvailableTypes(userId), getSystemLoginType(), resolveTypeForSecret(typeId, userId), createType(...), updateType(...), deleteType(...) with fallback to login type for assigned secrets
- [x] 3.2 Create `FolderService` with methods: create, rename, move, delete, getChildren, getOwned (ownership validation), getPath via mapper
- [x] 3.3 Implement folder deletion logic in FolderService: empty folder (direct delete), non-empty without subfolders (cascade=delete or cascade=move), non-empty with subfolders (resolution body required with per-subfolder actions: delete/move/keep, processed depth-first)
- [x] 3.4 Create `SecretService` with methods: create, get, update, delete, list, search, fuzzyMatch
- [x] 3.5 Implement revoked suite access blocking in SecretService: on get(), 403 if revoked/compromised; on list(), include blocked secrets with metadata only (omit encrypted blobs, add blocked flag and blockedReason)
- [x] 3.6 Implement write lock check in SecretService: on create() and update(), check MigrationService.isWriteLocked(ownerType, ownerId) — return 423 if locked
- [x] 3.7 Implement fuzzy search in SecretService: SQL LIKE pre-filter on name and url, PHP levenshtein() post-filter with tolerance (distance <= 1 for terms up to 5 chars, <= 2 for longer), merge and deduplicate results

## 4. Controllers and API Routes

- [x] 4.1 Create `SecretController` extending OCSController with endpoints: index (list/search), show (get), create, update, destroy (delete)
- [x] 4.2 Create `SecretTypeController` extending OCSController with endpoints: index, create, update, destroy
- [x] 4.3 Create `FolderController` extending OCSController with endpoints: index, create, update, destroy (with cascade/resolution), children
- [x] 4.4 Register all API routes in `appinfo/routes.php` under `/api/v1/` (specific/verb routes before {id} wildcards and before the SPA catch-all): secrets CRUD, secret-types CRUD, folders CRUD + children endpoint
- [x] 4.5 Add owner authorization checks: users can only access their own secrets, folders, and user-scoped types; admins can manage global types (IGroupManager::isAdmin)
- [x] 4.6 Add request validation: folder name no slashes, required fields for secret creation (name, key), cascade parameter validation, resolution body validation for folder deletion

## 5. Nextcloud Unified Search Provider

- [x] 5.1 Create `SecretSearchProvider` implementing `OCP\Search\IProvider` in `lib/Search/SecretSearchProvider.php` that queries name and url columns via SecretMapper without requiring master password
- [x] 5.2 Implement search result formatting: title (secret name), subtitle (url or type label), icon (app icon), deep-link URL (`apps/doriath/#/secrets/{id}`)
- [x] 5.3 Register the search provider via `IRegistrationContext::registerSearchProvider` in Application.php

## 6. Pinia Stores (Frontend)

- [x] 6.1 Create `src/store/modules/secret.js` (useSecretStore) with state and actions: fetchSecrets, fetchSecret (client-side RSA decryption), createSecret (client-side encryption), updateSecret, deleteSecret, searchSecrets
- [x] 6.2 Create `src/store/modules/secretType.js` (useSecretTypeStore): fetchTypes, createType, updateType, deleteType, typesById getter
- [x] 6.3 Create `src/store/modules/folder.js` (useFolderStore): folderTree getter (computed from flat list), fetchFolders, createFolder, updateFolder, deleteFolder (cascade/resolution), fetchChildren
- [x] 6.4 Implement client-side encryption in useSecretStore.createSecret: encrypt key/login/additionalFields using rsaEncrypt() with the owner's public certificate before the API call
- [x] 6.5 Implement client-side decryption in useSecretStore.fetchSecret: decrypt key/login/additionalFields using rsaDecrypt() with the CryptoKey from the session store

## 7. Vue Components (Frontend)

- [x] 7.1 Create `src/views/SecretList.vue`: folder sidebar + searchable/sortable/paginated secret list (50/page), empty state, loading (manifest-v2 custom page)
- [x] 7.2 Create `src/views/SecretDetail.vue`: type-specific field layout, decrypted fields, show/hide key, delete, revoked-suite handling
- [x] 7.3 Create `src/components/SecretListItem.vue`: favicon/type icon, name, url, copy-to-clipboard, blocked indicator
- [x] 7.4 Create `src/components/FolderTree.vue`: recursive nested folder navigation with click-to-filter
- [x] 7.5 Create `src/modals/SubfolderResolutionDialog.vue` using NcDialog and NcSelect; direct secret count, per-subfolder action dropdowns (delete/move/keep), recursive secret counts
- [x] 7.6 Create `src/components/CopyButton.vue` using NcButton; navigator.clipboard.writeText() with execCommand fallback, visual confirmation, auto-clears clipboard after 30 seconds
- [x] 7.7 Create `src/components/PasswordField.vue` using NcInputField with eye toggle; defaults masked, decrypts on first show

## 8. Favicon and Icon Support

- [x] 8.1 Create `src/utils/favicon.js`: resolves favicon URL from a secret's domain using the admin-configured `favicon_service_url` ({domain} placeholder), privacy-respecting default (disabled). Provided via IInitialState in DashboardController.
- [x] 8.2 Type-specific fallback icons mapping (login/api_key/ssh_key/certificate/note/database) via typeIconName()
- [x] 8.3 Integrate favicon/icon display in SecretListItem with fallback chain: favicon from URL -> type icon -> generic key icon

## 9. Vue Router Integration

- [x] 9.1 Register pages in `src/manifest.json` (manifest-v2 — this app has no `src/router/index.js`): `/secrets` (SecretList), `/secrets/:id` (SecretDetail), `/folders/:folderId` (SecretList filtered)
- [x] 9.2 Route params consumed via `$route.params` in the views (id for detail, folderId for folder filter)
- [x] 9.3 Lock screen returnUrl already supported by the existing LockScreen (`/lock?returnUrl=...`); unified-search deep-links resolve through it after unlock
- [x] 9.4 Folder navigation in the sidebar: clicking a folder navigates to `/folders/{id}`, showing only that folder's secrets

## 10. Internationalization

- [x] 10.1 Add English translations for all new UI strings (l10n/en.json)
- [x] 10.2 Add Dutch translations for all new UI strings (l10n/nl.json)
- [x] 10.3 Use `t()` / `n()` translation functions in all Vue components

## 11. Unit Tests (PHP)

- [x] 11.1 Unit tests for `SecretTypeService`: create user type, create global type, delete with fallback, system type immutability, unique name enforcement, default resolution
- [x] 11.2 Unit tests for `FolderService`: create, rename, move (cycle rejection), delete empty, cascade=delete, cascade=move, subfolder resolution (keep/missing entry), children endpoint, ownership validation
- [x] 11.3 Unit tests for `SecretService`: create with encryption suite link, get with revoked suite blocking (403), list with blocked secrets metadata, update with write lock check (423), delete with cascade, default type assignment
- [x] 11.4 Unit tests for fuzzy search: exact match, substring match, Levenshtein distance 1, no match, empty query
- [x] 11.5 Unit tests for `SeedSecretTypes` repair step: creates 6 types on first run, idempotent on re-run, deterministic UUIDs match expected values

## 12. Integration Tests (PHP)

- [x] 12.1 Controller tests for the Secret API: show returns encrypted blobs (NOT decrypted), create returns 201, destroy, index list
- [x] 12.4 Controller test: API never returns decrypted secret values — show/list assert key is the stored ciphertext blob
- [x] 12.5 Controller/service tests: user cannot access another user's secret (403)
- [x] 12.6 Service + controller tests: secret with revoked suite appears in list with blocked flag but detail returns 403
- [x] 12.7 Service + controller tests: secret create/update rejected during write lock (423)
- [~] 12.2 / 12.3 / 12.8 Full HTTP integration tests (live Nextcloud + DB) for the SecretType/Folder APIs and the unified-search provider — DEFERRED: this app's PHP test suite is unit-level (mocked mappers, no live DB/HTTP harness). Behavior is covered by the service + controller unit tests above; a live-instance integration suite is out of scope for this change.

## 13. Frontend Tests

- [~] 13.1–13.7 Frontend unit/component tests — DEFERRED: the doriath repo has no JS test runner (no vitest/jest config, no node_modules in CI for unit tests). Adding a frontend test harness is out of scope for this change; the store logic is thin axios + WebCrypto wrappers and the components use the app's established manifest-v2 patterns. To be picked up when a JS test harness lands fleet-wide.
