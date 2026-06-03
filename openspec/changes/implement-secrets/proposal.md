## Why

The EncryptionSuite foundation (implement-encryption-suites) provides Doriath's cryptographic infrastructure but stores no actual secrets. Without the Secret entity and its supporting structures (SecretType, Folder), the app is an encryption engine with nothing to encrypt. Secrets are the core data entity — every user-facing feature (sharing, requests, application credentials, search) depends on secrets existing. This is the next MVP-tier blocker.

## What Changes

- Implement Secret entity with RSA-encrypted fields (key, login, additional_fields) and unencrypted metadata (name, url, folder_id)
- Implement SecretType entity with 6 immutable system types seeded on install, plus user-scoped and admin-global custom types
- Implement Folder entity with tree hierarchy per user/application (parent_id traversal, no stored path strings)
- Secret CRUD: create (with encryption), read (with decryption gated by session), update (re-encrypt changed fields), delete (with cascade to shares and requests)
- Folder CRUD: create, rename, move, delete with subfolder resolution dialog for non-empty folders
- Secret list with search, sort (name, url, created_at, updated_at), and pagination (50 items/page)
- Fuzzy search by name and URL with Levenshtein tolerance
- Nextcloud unified search integration (IProvider) querying name+url without master password
- Deep-link from unified search results via lock screen with return URL
- Copy-to-clipboard and show/hide toggle on password fields
- Favicon/icon display next to secrets by URL
- Revoked suite access blocking (secrets visible in list but encrypted fields withheld)
- Type-specific field presentation in the secret detail view
- Database migrations for `doriath_secrets`, `doriath_secret_types`, and `doriath_folders` tables

## Capabilities

### New Capabilities
- `secrets`: Secret CRUD with RSA-encrypted fields, type assignment, folder placement, list/search/sort/pagination, revoked suite blocking, and Nextcloud unified search integration
- `secret-types`: SecretType CRUD with 6 system types seeded on install, user-scoped custom types, admin-global types, and fallback-to-login on custom type deletion
- `folders`: Folder CRUD with tree hierarchy, subfolder resolution dialog for non-empty deletion, and children endpoint for resolution UI

### Modified Capabilities
_(none — this is the first implementation of secrets, types, and folders)_

## Impact

- **Database**: Three new tables (`doriath_secrets`, `doriath_secret_types`, `doriath_folders`) via ISchemaWrapper migrations with indexes per ARCHITECTURE.md
- **Backend**: New entities (Secret, SecretType, Folder), mappers, services (SecretService, SecretTypeService, FolderService), controllers (SecretController, SecretTypeController, FolderController), a repair step for seeding system SecretTypes, and a Nextcloud search provider (SecretSearchProvider)
- **Frontend**: New Pinia stores (useSecretStore, useSecretTypeStore, useFolderStore), Vue components (SecretList, SecretDetail, FolderTree, SubfolderResolutionDialog), CnDataTable/CnFilterBar/CnDetailPage integration, copy-to-clipboard utility, show/hide toggle, favicon resolution
- **API**: REST endpoints for secret CRUD, secret type CRUD, folder CRUD, folder children, and search
- **Dependencies**: Depends on implement-encryption-suites (EncryptionSuite entity, CertificateAuthorityService, session store, lock screen, WebCrypto module)
- **Cross-app**: Secrets are what OpenConnector will ultimately store and retrieve as application credentials
- **Security**: Encrypted fields (key, login, additional_fields) are RSA-encrypted with the owner's public certificate; the server never decrypts them; the browser decrypts using WebCrypto. Revoked suite secrets are listed but not decryptable.
