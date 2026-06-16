## Context

Doriath is an encrypted secrets manager for Nextcloud. The implement-encryption-suites change provides the cryptographic foundation: EncryptionSuite entity, CertificateAuthorityService, DecryptService/EncryptService, WebCrypto module, session store with lock/unlock, and the lock screen with route guard.

With that foundation in place, the app still has no actual secret storage. This change implements the core data entities (Secret, SecretType, Folder) and their full lifecycle: CRUD operations, list/search/sort/pagination, Nextcloud unified search, and the frontend components to display and manage them.

The existing codebase after implement-encryption-suites will have: three database tables (encryption_suites, ca_certificates, suite_migrations), stateless crypto services, a session store with WebCrypto, a lock screen, and Vue Router with the navigation guard. No secret-related entities, migrations, services, or UI exist yet.

## Goals / Non-Goals

**Goals:**
- Implement Secret, SecretType, and Folder entities with ISchemaWrapper database migrations
- Implement Secret CRUD with RSA encryption of sensitive fields (key, login, additional_fields) on the client side
- Implement SecretType CRUD with 6 immutable system types seeded via repair step
- Implement Folder CRUD with tree hierarchy and subfolder resolution for non-empty deletion
- Implement secret list with search, sort, pagination (50 items/page)
- Implement fuzzy search by name and URL with Levenshtein tolerance
- Register a Nextcloud unified search provider (IProvider) for secrets
- Implement deep-link from search results through the lock screen
- Implement copy-to-clipboard, show/hide toggle, and favicon display in the UI
- Implement revoked suite access blocking (list but not decrypt)
- Seed 6 system SecretTypes and development data via repair steps

**Non-Goals:**
- Sharing (user, link, request) -- separate change, depends on secrets
- Application management and CSR processing -- separate change
- Bulk operations (multi-select delete/move) -- V1 tier
- Secret import/export (CSV, Bitwarden, KeePass) -- V1 tier
- Favorite/pinned secrets -- V1 tier
- Password health scoring -- V1 tier
- Browser extension -- Enterprise tier
- Tags -- Enterprise tier

## Decisions

### D1: Secret Encryption Flow -- Client-Side RSA via WebCrypto

Encrypted fields (key, login, additional_fields) are encrypted in the browser using the owner's RSA public certificate before being sent to the server. The server stores ciphertext blobs and never decrypts them. On read, the server returns the blobs and the browser decrypts them using the CryptoKey held in memory (from the session store).

The encryption flow reuses the RSA-OAEP-SHA256 chunking implementation from implement-encryption-suites (`src/crypto/rsa.js` for WebCrypto, `EncryptService` for PHP). The `additional_fields` JSON blob is stringified before encryption, producing a single ciphertext blob regardless of how many key-value pairs it contains.

**Why:** ADR-003 mandates always-E2E. The server is a ciphertext store -- it can index and search unencrypted metadata (name, url) but cannot access sensitive values. This is the same pattern used for private key encryption in implement-encryption-suites.

**Alternatives considered:**
- Server-side encryption with session AES key: Rejected -- violates ADR-003. The AES key would need to travel to the server, expanding the attack surface.
- Hybrid AES+RSA per-secret: Each secret gets a random AES key, encrypted with RSA, and the secret payload is AES-encrypted. This is more efficient for large payloads but adds complexity. Rejected for MVP -- RSA chunking handles the expected payload sizes (passwords and short JSON blobs). Can be revisited if large additional_fields become common.

### D2: Database Migration Versioning -- Continue Sequence from Encryption Suites

Migrations continue the version numbering from implement-encryption-suites:
- `Version000004Date20260331000003` -- `doriath_secret_types` table
- `Version000005Date20260331000004` -- `doriath_folders` table
- `Version000006Date20260331000005` -- `doriath_secrets` table

SecretTypes are created first (secrets reference type_id), folders second (secrets reference folder_id), secrets last.

**Why:** Nextcloud executes migrations in alphabetical order by class name. Sequential numbering ensures correct dependency ordering. Secrets depends on both types and folders.

### D3: System SecretType Seeding -- IRepairStep with Idempotent Upsert

The 6 system SecretTypes are seeded by a `SeedSecretTypes` repair step registered as `post-migration` in `info.xml`. The repair step uses an idempotent upsert pattern: for each system type, check if a row with that `name` and `scope=system` exists; if not, insert it.

System types have deterministic UUIDs generated from a namespace UUID + type name (UUID v5), so they are stable across instances and re-runs.

| Name | Label | UUID (v5 from `doriath:secret-type:{name}`) |
|------|-------|------|
| `login` | Login | deterministic |
| `api_key` | API Key | deterministic |
| `ssh_key` | SSH Key | deterministic |
| `certificate` | Certificate | deterministic |
| `note` | Secure Note | deterministic |
| `database` | Database | deterministic |

**Why:** Repair steps are the Nextcloud-standard way to seed data (ADR-016). Deterministic UUIDs ensure the `login` type has the same ID on every instance, simplifying the default-type assignment. Idempotent upsert means `occ maintenance:repair` is safe to re-run.

**Alternatives considered:**
- Seed in the migration itself: Rejected -- migrations should only define schema. Data seeding belongs in repair steps (same pattern as CA bootstrap in implement-encryption-suites).
- Auto-increment integer IDs for system types: Rejected -- UUIDs are the standard for all Doriath entities; deterministic UUIDs give the stability benefits of fixed IDs.

### D4: Folder Tree Traversal -- Recursive CTE for Path Resolution

Folder paths (e.g., `personal/email/work`) are derived at query time by traversing `parent_id` links. For display, a single recursive CTE query builds the full path:

```sql
WITH RECURSIVE folder_path AS (
  SELECT id, name, parent_id, CAST(name AS TEXT) AS path
  FROM doriath_folders WHERE id = :folderId
  UNION ALL
  SELECT f.id, f.name, f.parent_id, CONCAT(f.name, '/', fp.path)
  FROM doriath_folders f JOIN folder_path fp ON f.id = fp.parent_id
)
SELECT path FROM folder_path WHERE parent_id IS NULL;
```

For listing folders as a tree, a top-down CTE retrieves all descendants of a given root. PostgreSQL supports recursive CTEs natively.

**Why:** No stored path strings (spec requirement). CTE is efficient for moderate-depth trees (vault folders rarely exceed 5-10 levels). Avoids materialized path or nested set complexity.

**Alternatives considered:**
- Materialized path column (e.g., `/root/sub1/sub2`): Simpler queries but requires updating all descendants on rename/move. Rejected per spec: "paths are derived by traversing parents and are never stored directly."
- Application-level traversal (N+1 queries): Simple but O(depth) queries per path resolution. Rejected for performance.

### D5: Fuzzy Search -- SQL LIKE + PHP Levenshtein Post-Filter

Search is implemented in two stages:
1. **SQL pre-filter**: `WHERE (name ILIKE '%{term}%' OR url ILIKE '%{term}%')` retrieves candidates that contain the search term as a substring (catches exact and partial matches).
2. **PHP post-filter**: For results below a threshold count, also query all user secrets and compute `levenshtein($term, $name)` and `levenshtein($term, $url)` with tolerance bounds (distance <= 1 for terms up to 5 chars, distance <= 2 for longer terms). Merge with SQL results, deduplicate.

For users with small vaults (< 500 secrets), the Levenshtein pass is fast enough in PHP. For large vaults, the SQL LIKE pre-filter catches most matches and the Levenshtein pass adds typo tolerance.

**Why:** PostgreSQL has `levenshtein()` in the `fuzzystrmatch` extension, but this extension may not be installed on all Nextcloud deployments. PHP's built-in `levenshtein()` function is reliable and requires no database extensions. The two-stage approach balances correctness with compatibility.

**Alternatives considered:**
- PostgreSQL `pg_trgm` trigram extension: Excellent for fuzzy search but requires extension installation. Not acceptable for a Nextcloud app that must work on any PostgreSQL/MySQL/SQLite instance.
- Full-text search: Overkill for short strings like names and URLs. Does not provide typo tolerance.
- Client-side search only: Would require loading all secrets into the browser. Not feasible for large vaults and does not support unified search.

### D6: Nextcloud Unified Search Provider -- IProvider with Direct DB Query

Register `SecretSearchProvider` implementing `OCP\Search\IProvider`. The provider queries `name` and `url` columns directly via the SecretMapper -- no master password or AES key required (these fields are unencrypted).

Results are scoped to the authenticated user via `IUser::getUID()`. Each result includes:
- Title: secret name
- Subtitle: secret URL (or type label if no URL)
- Icon: favicon from URL (if available) or type icon
- URL: deep-link to `apps/doriath/#/secrets/{id}`

When the user clicks a result:
- If the Doriath session is active (CryptoKey in memory): navigate directly to the secret detail view
- If the session is not active: the route guard redirects to `/lock?returnUrl=/secrets/{id}`, and after unlock the user is redirected to the secret

**Why:** The lock screen already supports `returnUrl` (from implement-encryption-suites D9). The unified search provider queries unencrypted metadata only, so it works without any vault session state. This is by design -- ADR-003 explicitly keeps name and url unencrypted to enable search.

### D7: Folder Deletion -- Subfolder Resolution via Two-Phase API

Non-empty folder deletion uses a two-phase approach:

**Phase 1: Query children** -- `GET /folders/{id}/children` returns:
```json
{
  "directSecretCount": 2,
  "subfolders": [
    { "id": "uuid", "name": "subfolder-1", "secretCount": 5, "subfolderCount": 1 }
  ]
}
```

`secretCount` is a recursive count (all secrets in the subtree). Computed via a recursive CTE:
```sql
WITH RECURSIVE subtree AS (
  SELECT id FROM doriath_folders WHERE id = :folderId
  UNION ALL
  SELECT f.id FROM doriath_folders f JOIN subtree s ON f.parent_id = s.id
)
SELECT COUNT(*) FROM doriath_secrets WHERE folder_id IN (SELECT id FROM subtree);
```

**Phase 2: Delete with resolution** -- `DELETE /folders/{id}` with JSON body:
```json
{
  "directSecrets": "delete",
  "subfolders": {
    "uuid-1": "keep",
    "uuid-2": "delete",
    "uuid-3": "move"
  }
}
```

The server validates that every direct subfolder is accounted for in the resolution map (400 if not). Actions are processed depth-first:
- `delete`: Recursively delete subfolder, all nested subfolders, and all secrets
- `move`: Recursively collect all secrets from the subtree, re-parent them to the deleted folder's parent (or root), then delete the subfolder tree
- `keep`: Re-parent the subfolder to the deleted folder's parent (or root)

For folders without subfolders, the simple `?cascade=delete` or `?cascade=move` query parameter shorthand works as described in the spec.

**Why:** The spec defines this two-phase protocol explicitly. The resolution dialog gives users fine-grained control over what happens to each subfolder, preventing accidental data loss. Recursive CTEs ensure correct subtree handling.

### D8: Service Layer Architecture

```
SecretController
  └── SecretService (business logic)
        ├── SecretMapper (DB)
        ├── SecretTypeService (type resolution, default assignment)
        ├── EncryptionSuiteService (suite status check, from implement-encryption-suites)
        └── MigrationService (write lock check, from implement-encryption-suites)

SecretTypeController
  └── SecretTypeService
        └── SecretTypeMapper (DB)

FolderController
  └── FolderService (tree operations, cascade logic)
        ├── FolderMapper (DB)
        └── SecretMapper (for cascade operations)

SecretSearchProvider (IProvider)
  └── SecretMapper (direct DB query for name/url)
```

All controllers extend `OCSController`. All services follow the Controller -> Service -> Mapper layering (ADR-008). The SecretService injects EncryptionSuiteService to check suite status on read (revoked suite blocking) and MigrationService to enforce write locks during compromise recovery.

### D9: Frontend Architecture -- Pinia Stores with Client-Side Decrypt

Three new Pinia stores, each calling Doriath's REST API:

**useSecretStore:**
- State: secrets (array), currentSecret, totalCount, loading, filters, sort, page
- Actions: fetchSecrets(filters, sort, page), fetchSecret(id), createSecret(data), updateSecret(id, data), deleteSecret(id), searchSecrets(query)
- The `fetchSecret` action receives encrypted blobs from the API, then calls `rsaDecrypt()` from the WebCrypto module using the CryptoKey from the session store to produce plaintext values for the UI

**useSecretTypeStore:**
- State: types (array), loading
- Actions: fetchTypes(), createType(data), updateType(id, data), deleteType(id)

**useFolderStore:**
- State: folders (array), folderTree (computed), currentFolder, loading
- Actions: fetchFolders(), createFolder(data), updateFolder(id, data), deleteFolder(id, resolution), fetchChildren(id)

**Why:** Doriath does NOT use `useObjectStore` (that is OpenRegister-specific). Own stores with explicit decrypt logic keep the E2E model clear: the store knows it receives ciphertext from the API and must decrypt before exposing to components.

### D10: UI Component Architecture

| Component | Library Components Used | Purpose |
|-----------|------------------------|---------|
| `SecretList.vue` | CnDataTable, CnFilterBar, CnPagination, CnEmptyState | Main vault view with search, sort, pagination |
| `SecretDetail.vue` | CnDetailPage, CnDetailCard, CnObjectSidebar | Secret detail with type-specific field layout |
| `FolderTree.vue` | NcAppNavigationItem (nested) | Sidebar folder tree navigation |
| `SubfolderResolutionDialog.vue` | NcDialog, NcSelect | Dialog for choosing per-subfolder action on folder delete |
| `SecretListItem.vue` | (internal to CnDataTable row) | Row component with favicon, copy button, show/hide toggle |
| `CopyButton.vue` | NcButton | Copy-to-clipboard with visual feedback |
| `PasswordField.vue` | NcInputField | Input with show/hide eye icon toggle |

**Copy-to-clipboard:** Uses `navigator.clipboard.writeText()` with a fallback to `document.execCommand('copy')`. The copy action triggers decryption of the `key` field (if not already decrypted) before copying.

**Favicon resolution:** Configurable via admin setting `favicon_service_url` (default: empty/disabled — no external calls out of the box). When disabled, only type-specific icons are shown. Admins can configure a favicon service URL using `{domain}` as a placeholder, e.g. `https://icons.duckduckgo.com/ip3/{domain}.ico` (privacy-respecting) or a self-hosted instance like Favicone (`https://favicone.dev/icon?url={domain}&size=32`). The setting is exposed in the Doriath admin settings page. Falls back to type icons when the service is disabled, the URL is empty, or the favicon fails to load.

**Show/hide toggle:** Password fields default to hidden (masked with dots). The eye icon toggles between `type="password"` and `type="text"`. Decryption happens on first show -- until toggled, the field displays dots without decrypting (performance optimization for lists).

### D11: API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| GET | `/api/v1/secrets` | List secrets (paginated, filterable, sortable) |
| POST | `/api/v1/secrets` | Create secret |
| GET | `/api/v1/secrets/{id}` | Get single secret |
| PUT | `/api/v1/secrets/{id}` | Update secret |
| DELETE | `/api/v1/secrets/{id}` | Delete secret (cascade to shares/requests) |
| GET | `/api/v1/secret-types` | List secret types |
| POST | `/api/v1/secret-types` | Create custom type |
| PUT | `/api/v1/secret-types/{id}` | Update custom type |
| DELETE | `/api/v1/secret-types/{id}` | Delete custom type (fallback to login) |
| GET | `/api/v1/folders` | List folders for current user |
| POST | `/api/v1/folders` | Create folder |
| PUT | `/api/v1/folders/{id}` | Update folder (rename/move) |
| DELETE | `/api/v1/folders/{id}` | Delete folder (with cascade/resolution) |
| GET | `/api/v1/folders/{id}/children` | Get folder children for resolution dialog |

Query parameters for `GET /secrets`:
- `folder_id` -- filter by folder (null = root, omit = all)
- `search` -- fuzzy search term
- `sort` -- field name (name, url, created_at, updated_at)
- `direction` -- asc/desc
- `page` -- page number (1-based)
- `limit` -- items per page (default 50, max 100)

Response format for list endpoints includes `total` count for pagination controls.

### D12: Revoked Suite Access Blocking

When a secret's `encryption_suite_id` points to a suite with status `revoked` or `compromised`:
- **List endpoints**: The secret appears in results with name, url, type, and folder metadata. Encrypted fields (key, login, additional_fields) are omitted from the response. A `blocked` boolean field is set to `true` and a `blocked_reason` string explains why.
- **Detail endpoint**: Returns 403 with an error message indicating the suite is revoked/compromised.
- **After reinstatement**: The suite status returns to `active` and all secrets are accessible again -- no migration needed (the RSA key pair is unchanged).

The check is performed in `SecretService.getSecret()` and `SecretService.listSecrets()` by joining with the encryption_suites table on `encryption_suite_id`.

## Seed Data

Since Doriath uses its own database (not OpenRegister), seed data is handled through repair steps:

### 1. System SecretTypes (repair step -- always runs)

The `SeedSecretTypes` repair step seeds the 6 system types on every install and upgrade. Uses deterministic UUIDs (v5) so IDs are stable across instances.

| Name | Label | Scope |
|------|-------|-------|
| `login` | Login | system |
| `api_key` | API Key | system |
| `ssh_key` | SSH Key | system |
| `certificate` | Certificate | system |
| `note` | Secure Note | system |
| `database` | Database | system |

### 2. Development Seed Data (repair step -- debug mode only)

The `SeedDevelopmentSecrets` repair step (registered only when `debug=true`) creates realistic example secrets for the development user's vault. It depends on the development EncryptionSuite from implement-encryption-suites (`SeedDevelopmentData` with master password `Doriath-Dev-2024!`).

Example secrets seeded:

| Name | URL | Type | Key (plaintext before encryption) | Login |
|------|-----|------|----------------------------------|-------|
| GitHub | https://github.com | login | `gh_dev_P@ssw0rd!2024` | `dev-user` |
| AWS Console | https://aws.amazon.com | api_key | `AKIAIOSFODNN7EXAMPLE` | `dev-access-key` |
| Production Database | postgresql://db.internal:5432/prod | database | `Pr0d-DB-$ecret!` | `app_service` |
| SSH Deploy Key | ssh://git@github.com | ssh_key | `-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEA...` | `deploy` |
| TLS Wildcard Certificate | https://example.com | certificate | `-----BEGIN CERTIFICATE-----\nMIIE...` | `*.example.com` |
| Server Room WiFi | n/a | note | `Combination: 42-17-89. Door code: 5523#` | (empty) |

These secrets are encrypted with the development user's public certificate. The repair step also creates two folders (`Work` and `Personal`) and places secrets into them (GitHub and AWS in Work, WiFi note in Personal, others at root).

## Risks / Trade-offs

- **[Risk] Levenshtein search performance on large vaults** -- PHP `levenshtein()` is O(n*m) per comparison, applied to all user secrets. Mitigated by the SQL LIKE pre-filter catching most matches and the Levenshtein pass only running when the LIKE results are below a threshold. For vaults > 1000 secrets, consider adding a configurable search mode that disables fuzzy matching.

- **[Risk] Favicon service dependency** -- Using Google's favicon service (`google.com/s2/favicons`) introduces an external dependency and a privacy concern (Google sees which domains the user has secrets for). Mitigated by falling back to local type icons when the service is unavailable. Future: self-hosted favicon resolution via a Doriath API endpoint that caches favicons.

- **[Risk] RSA chunking for large additional_fields** -- `additional_fields` is a JSON blob encrypted as a single RSA payload with chunking. If a user stores very large additional fields (> 10KB), the chunk count becomes high and encryption/decryption slows down. Mitigated by the ADR-003 note on chunk limits. MVP accepts this limitation; a future change could introduce per-secret AES encryption for large payloads.

- **[Risk] Recursive CTE compatibility** -- Recursive CTEs are supported by PostgreSQL, MySQL 8.0+, and SQLite 3.8.3+. Older MySQL versions (5.7) do not support them. Mitigated by Nextcloud 28+ requiring MySQL 8.0 or MariaDB 10.6 (both support CTEs).

- **[Trade-off] Unencrypted name and URL** -- Storing name and URL in plaintext enables search and unified search but means these values are visible in the database. This is an explicit architectural decision (ADR-003, spec notes). Users are informed in the UI that names and URLs are not encrypted.

- **[Trade-off] Client-side decryption latency** -- Decrypting multiple secrets on the list page requires RSA operations per secret. Mitigated by lazy decryption: only decrypt when the user expands a row or clicks show. The list view shows only unencrypted metadata (name, url, type) plus a masked password indicator. Copy-to-clipboard triggers on-demand decryption.

- **[Trade-off] Google favicon service privacy** -- The simplest approach exposes user domains to Google. For privacy-conscious deployments, an admin setting to disable external favicon fetching (using only type icons) should be added as a fast follow.

## Migration Plan

1. **Database migrations**: Run `occ upgrade` to execute ISchemaWrapper migrations creating `doriath_secret_types`, `doriath_folders`, and `doriath_secrets` tables (in that order)
2. **System type seeding**: The `SeedSecretTypes` repair step runs post-migration, creating the 6 system types
3. **Development data**: If `debug=true`, the `SeedDevelopmentSecrets` repair step creates example secrets and folders for the dev user
4. **No data migration**: Greenfield -- no existing secret data to migrate
5. **Rollback**: Disable the app via `occ app:disable doriath`. Tables remain but are inert. Re-enable to resume.

## Open Questions

- ~~Should the favicon service URL be configurable in admin settings?~~ **Resolved.** Configurable via `favicon_service_url` admin setting. Disabled by default (no external calls). Admins opt-in to DuckDuckGo, Favicone, or a self-hosted service.
- Should the search Levenshtein threshold be configurable? Current decision: fixed thresholds (distance <= 1 for terms up to 5 chars, <= 2 for longer) -- simplest approach for MVP.
