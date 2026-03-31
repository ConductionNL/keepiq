## 1. Database Migrations and Seed Data

- [ ] 1.1 Create ISchemaWrapper migration `Version000004Date20260331000003` for `doriath_secret_types` table with columns: id (UUID PK), name (string, unique index), label (string), scope (string enum: system/user/global), owner_id (string nullable), created_at (datetime)
- [ ] 1.2 Create ISchemaWrapper migration `Version000005Date20260331000004` for `doriath_folders` table with columns: id (UUID PK), name (string), parent_id (FK nullable), owner_type (string enum: user/application), owner_id (string), created_at (datetime), updated_at (datetime); composite index on (owner_type, owner_id, parent_id)
- [ ] 1.3 Create ISchemaWrapper migration `Version000006Date20260331000005` for `doriath_secrets` table with columns: id (UUID PK), name (string), url (string nullable), type_id (FK to secret_types), folder_id (FK nullable to folders), key (text), login (text nullable), additional_fields (text nullable), encryption_suite_id (FK to encryption_suites), owner_type (string), owner_id (string), possibly_compromised_at (datetime nullable), migration_error (text nullable), created_at (datetime), updated_at (datetime); indexes on (owner_type, owner_id), folder_id, encryption_suite_id
- [ ] 1.4 Create `SeedSecretTypes` IRepairStep that upserts the 6 system SecretTypes with deterministic UUIDs (v5 from namespace `doriath:secret-type:{name}`), scope=system, owner_id=null; idempotent — skips existing types
- [ ] 1.5 Register `SeedSecretTypes` as post-migration repair step in `info.xml`
- [ ] 1.6 Create `SeedDevelopmentSecrets` IRepairStep (debug-only) that creates example secrets (GitHub login, AWS API key, Production Database, SSH Deploy Key, TLS Certificate, Server Room WiFi note) encrypted with the dev user's public certificate, plus two folders (Work, Personal) with secrets distributed across them
- [ ] 1.7 Register `SeedDevelopmentSecrets` as post-migration repair step in `info.xml` (debug-only condition)

## 2. Entities and Mappers

- [ ] 2.1 Create `SecretType` Doctrine entity in `lib/Db/SecretType.php` with all fields, JsonSerializable, and column type annotations
- [ ] 2.2 Create `SecretTypeMapper` extending QBMapper in `lib/Db/SecretTypeMapper.php` with methods: findById(id), findByName(name, scope, ownerId), findSystemTypes(), findAvailableForUser(userId), findByScope(scope)
- [ ] 2.3 Create `Folder` Doctrine entity in `lib/Db/Folder.php` with all fields and JsonSerializable
- [ ] 2.4 Create `FolderMapper` extending QBMapper in `lib/Db/FolderMapper.php` with methods: findById(id), findByOwner(ownerType, ownerId), findChildren(parentId), findRootFolders(ownerType, ownerId), getPath(folderId) using recursive CTE, getSubtreeIds(folderId) using recursive CTE, countSecrets(folderId) direct count, countSecretsRecursive(folderId) via CTE
- [ ] 2.5 Create `Secret` Doctrine entity in `lib/Db/Secret.php` with all fields and JsonSerializable; key, login, and additional_fields stored as encrypted blobs (text columns)
- [ ] 2.6 Create `SecretMapper` extending QBMapper in `lib/Db/SecretMapper.php` with methods: findById(id), findByOwner(ownerType, ownerId, filters, sort, limit, offset), findByFolder(folderId, sort, limit, offset), countByOwner(ownerType, ownerId, filters), countByFolder(folderId), searchByNameOrUrl(userId, term), updateFolderForSecrets(oldFolderId, newFolderId), deleteByFolderId(folderId)

## 3. Services (PHP)

- [ ] 3.1 Create `SecretTypeService` in `lib/Service/SecretTypeService.php` with methods: getAvailableTypes(userId), getSystemLoginType(), createType(name, label, scope, ownerId), updateType(id, label, userId), deleteType(id, userId) with fallback to login type for assigned secrets
- [ ] 3.2 Create `FolderService` in `lib/Service/FolderService.php` with methods: create(name, parentId, ownerType, ownerId), rename(id, name, userId), move(id, newParentId, userId), delete(id, cascade, resolution, userId), getChildren(id, userId), getFolderPath(id), validateOwnership(id, userId)
- [ ] 3.3 Implement folder deletion logic in FolderService: empty folder (direct delete), non-empty without subfolders (cascade=delete or cascade=move), non-empty with subfolders (resolution body required with per-subfolder actions: delete/move/keep, processed depth-first)
- [ ] 3.4 Create `SecretService` in `lib/Service/SecretService.php` with methods: create(data, userId), get(id, userId), update(id, data, userId), delete(id, userId), list(userId, filters, sort, page, limit), search(userId, term, page, limit)
- [ ] 3.5 Implement revoked suite access blocking in SecretService: on get(), check encryption_suite status — return 403 if revoked/compromised; on list(), include blocked secrets with metadata only (omit encrypted blobs, add blocked flag and blocked_reason)
- [ ] 3.6 Implement write lock check in SecretService: on create() and update(), check MigrationService.isWriteLocked(ownerId) — return 423 if locked
- [ ] 3.7 Implement fuzzy search in SecretService: SQL LIKE pre-filter on name and url, PHP levenshtein() post-filter with tolerance (distance <= 1 for terms up to 5 chars, <= 2 for longer), merge and deduplicate results

## 4. Controllers and API Routes

- [ ] 4.1 Create `SecretController` extending OCSController in `lib/Controller/SecretController.php` with endpoints: index (list), show (get), create, update, destroy (delete)
- [ ] 4.2 Create `SecretTypeController` extending OCSController in `lib/Controller/SecretTypeController.php` with endpoints: index (list available types), create, update, destroy
- [ ] 4.3 Create `FolderController` extending OCSController in `lib/Controller/FolderController.php` with endpoints: index (list), create, update, destroy (with cascade/resolution), children
- [ ] 4.4 Register all API routes in `appinfo/routes.php` under `/api/v1/`: secrets CRUD, secret-types CRUD, folders CRUD + children endpoint
- [ ] 4.5 Add owner authorization checks: users can only access their own secrets, folders, and user-scoped types; admins can manage global types
- [ ] 4.6 Add request validation: folder name no slashes, required fields for secret creation (name, key), cascade parameter validation, resolution body validation for folder deletion

## 5. Nextcloud Unified Search Provider

- [ ] 5.1 Create `SecretSearchProvider` implementing `OCP\Search\IProvider` in `lib/Search/SecretSearchProvider.php` that queries name and url columns via SecretMapper without requiring master password
- [ ] 5.2 Implement search result formatting: title (secret name), subtitle (url or type label), icon (favicon URL or type icon), deep-link URL (`apps/doriath/#/secrets/{id}`)
- [ ] 5.3 Register the search provider in `info.xml` or via `OCP\Search\ISearchProviderRegistry`

## 6. Pinia Stores (Frontend)

- [ ] 6.1 Create `src/store/modules/secret.js` (useSecretStore) with state: secrets, currentSecret, totalCount, loading, filters, sort, page; actions: fetchSecrets, fetchSecret (with client-side RSA decryption of key/login/additional_fields via session CryptoKey), createSecret (with client-side encryption before API call), updateSecret, deleteSecret, searchSecrets
- [ ] 6.2 Create `src/store/modules/secretType.js` (useSecretTypeStore) with state: types, loading; actions: fetchTypes, createType, updateType, deleteType
- [ ] 6.3 Create `src/store/modules/folder.js` (useFolderStore) with state: folders, folderTree (computed from flat list), currentFolder, loading; actions: fetchFolders, createFolder, updateFolder, deleteFolder (with cascade/resolution), fetchChildren
- [ ] 6.4 Implement client-side encryption in useSecretStore.createSecret: encrypt key, login, and additional_fields using rsaEncrypt() from src/crypto/rsa.js with the owner's public certificate before sending to the API
- [ ] 6.5 Implement client-side decryption in useSecretStore.fetchSecret: decrypt key, login, and additional_fields using rsaDecrypt() from src/crypto/rsa.js with the CryptoKey from the session store

## 7. Vue Components (Frontend)

- [ ] 7.1 Create `src/views/SecretList.vue` using CnDataTable, CnFilterBar, CnPagination, and CnEmptyState; supports search input, sort controls, folder filter, 50 items/page pagination
- [ ] 7.2 Create `src/views/SecretDetail.vue` using CnDetailPage, CnDetailCard, and CnObjectSidebar; shows type-specific field layout with encrypted fields decrypted on load
- [ ] 7.3 Create `src/components/SecretListItem.vue` for CnDataTable rows: favicon/type icon, secret name, masked password with show/hide toggle, URL, copy-to-clipboard button
- [ ] 7.4 Create `src/components/FolderTree.vue` using nested NcAppNavigationItem components; renders user's folder hierarchy in the app sidebar with click-to-navigate
- [ ] 7.5 Create `src/components/SubfolderResolutionDialog.vue` using NcDialog and NcSelect; shows direct secret count and subfolder list with per-subfolder action dropdowns (delete/move/keep) and recursive secret counts
- [ ] 7.6 Create `src/components/CopyButton.vue` using NcButton; copies decrypted value to clipboard via navigator.clipboard.writeText(), shows visual confirmation, auto-clears clipboard after 30 seconds
- [ ] 7.7 Create `src/components/PasswordField.vue` using NcInputField with eye icon toggle; defaults to masked, triggers decryption on first show

## 8. Favicon and Icon Support

- [ ] 8.1 Create `src/utils/favicon.js` utility that resolves favicon URL from a secret's domain using `https://www.google.com/s2/favicons?domain={domain}&sz=32` with error handling for missing favicons
- [ ] 8.2 Create type-specific fallback icons mapping: login (key icon), api_key (code icon), ssh_key (terminal icon), certificate (shield icon), note (note icon), database (database icon)
- [ ] 8.3 Integrate favicon/icon display in SecretListItem and SecretDetail components with fallback chain: favicon from URL -> type icon -> generic key icon

## 9. Vue Router Integration

- [ ] 9.1 Add routes to `src/router/index.js`: `/secrets` (SecretList), `/secrets/:id` (SecretDetail), `/folders/:id` (SecretList with folderId prop)
- [ ] 9.2 Add route props functions: SecretDetail gets `secretId` from params, FolderView gets `folderId` from params
- [ ] 9.3 Ensure lock screen returnUrl parameter works for deep-links from unified search (e.g., `/lock?returnUrl=/secrets/{id}` redirects to secret after unlock)
- [ ] 9.4 Add folder navigation in the app sidebar: clicking a folder navigates to `/folders/{id}`, showing only that folder's secrets

## 10. Internationalization

- [ ] 10.1 Add English translations for all new UI strings: secret list headers, detail labels, folder actions, search placeholder, empty states, error messages, type labels, dialog text
- [ ] 10.2 Add Dutch translations for all new UI strings
- [ ] 10.3 Use `t()` / `n()` translation functions in all Vue components and PHP controllers/services

## 11. Unit Tests (PHP)

- [ ] 11.1 Write unit tests for `SecretTypeService`: create user type, create global type, delete with fallback, system type immutability, unique name enforcement
- [ ] 11.2 Write unit tests for `FolderService`: create, rename, move, delete empty, delete with cascade=delete, delete with cascade=move, delete with subfolder resolution (all three actions), children endpoint, ownership validation
- [ ] 11.3 Write unit tests for `SecretService`: create with encryption suite link, get with revoked suite blocking (403), list with blocked secrets metadata, update with write lock check (423), delete with cascade, default type assignment
- [ ] 11.4 Write unit tests for fuzzy search: exact match, substring match, Levenshtein distance 1, Levenshtein distance 2, no match, empty query
- [ ] 11.5 Write unit tests for `SeedSecretTypes` repair step: creates 6 types on first run, idempotent on re-run, deterministic UUIDs match expected values

## 12. Integration Tests (PHP)

- [ ] 12.1 Write integration tests for Secret API: create, get (returns encrypted blobs, NOT decrypted values), update, delete, list with pagination, list filtered by folder, list sorted by each field
- [ ] 12.2 Write integration tests for SecretType API: list available types (system + global + own user types), create user type, create global type (admin only), update, delete with fallback, system type immutability
- [ ] 12.3 Write integration tests for Folder API: create root, create subfolder, rename, move, delete empty, delete with cascade, delete with resolution body, children endpoint
- [ ] 12.4 Write integration test: API never returns decrypted secret values — verify key, login, and additional_fields in response are encrypted blobs
- [ ] 12.5 Write integration test: user cannot access another user's secrets (403), folders (403), or user-scoped types (403)
- [ ] 12.6 Write integration test: secret with revoked suite appears in list with blocked flag but detail returns 403
- [ ] 12.7 Write integration test: secret creation rejected during write lock (423)
- [ ] 12.8 Write integration test: unified search provider returns results for name and URL queries without requiring vault session

## 13. Frontend Tests

- [ ] 13.1 Write unit tests for useSecretStore: fetchSecrets, fetchSecret with decryption, createSecret with encryption, deleteSecret, search
- [ ] 13.2 Write unit tests for useFolderStore: fetchFolders, folderTree computation, deleteFolder with resolution
- [ ] 13.3 Write unit tests for useSecretTypeStore: fetchTypes, createType, deleteType
- [ ] 13.4 Write component tests for SecretList: renders table, pagination, search input, empty state
- [ ] 13.5 Write component tests for SecretDetail: renders type-specific fields, show/hide toggle, copy button
- [ ] 13.6 Write component tests for SubfolderResolutionDialog: renders subfolder list with counts, action selection, submit
- [ ] 13.7 Write component tests for CopyButton: copies to clipboard, shows confirmation, clears after timeout
