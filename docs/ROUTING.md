---
sidebar_position: 4
---

# Folder Routing

Doriath uses human-readable, path-based URLs for folder navigation, following the same `?dir=` convention as Nextcloud Files.

## URL Scheme

| URL | What it shows |
|-----|---------------|
| `/folders?dir=/` | Root folder (secrets without a parent folder) |
| `/folders?dir=/Work` | The "Work" folder at root level |
| `/folders?dir=/Work/Credentials` | The "Credentials" subfolder inside "Work" |
| `/folders` | Same as `?dir=/` (root folder) |
| `/secrets` | All secrets regardless of folder |

## Path ↔ ID Resolution

URLs show human-readable folder paths, but internally the app works exclusively with UUID folder IDs. The translation between the two happens at the routing boundary via two functions in `src/utils/folderPath.js`:

### Encoding: ID → Path (`folderIdToPath`)

Given a folder ID and the `foldersById` lookup map, walks up the `parentId` chain collecting folder names, then joins them with `/`.

```
Folder { id: "a1b2", name: "Credentials", parentId: "c3d4" }
  → parent: { id: "c3d4", name: "Work", parentId: null }
  → root reached

Collected names (reversed): ["Work", "Credentials"]
Result: /Work/Credentials
```

### Decoding: Path → ID (`pathToFolderId`)

Given a path string and the flat folders array, splits on `/` and walks down from root, matching each segment to a child folder by name (case-insensitive). Returns:

- A folder ID string if the path resolves successfully
- `null` for the root path (`/`)
- `undefined` if the path is invalid (triggers a redirect to root)

### Convenience: `folderDirQuery`

Returns a route-ready query object `{ dir: '/path/to/folder' }` for use with `$router.push()`.

## Uniqueness Assumption

Path-based routing relies on folder names being **unique within their parent folder**. This constraint is enforced by the frontend (the `isDuplicateName` getter in the folder store checks for case-insensitive sibling name collisions). The backend does not enforce this, so the frontend validation is the source of truth.

## URL Encoding

The `?dir=` value uses minimal encoding to keep URLs readable. Only three characters are encoded because they have special meaning in query strings:

| Character | Encoded as | Reason |
|-----------|------------|--------|
| `&` | `%26` | Separates query parameters |
| `=` | `%3D` | Separates key from value |
| `#` | `%23` | Starts the URL fragment |

All other characters — including spaces, unicode, and special characters — remain unencoded. Slashes (`/`) between folder name segments are preserved literally.

This is implemented via a custom `stringifyQuery` function on the Vue Router instance (`src/router/index.js`). The default `parseQuery` handles decoding automatically since we use standard percent-encoding.

## Edge Cases

| Scenario | Behavior |
|----------|----------|
| Invalid or stale path (e.g. folder was deleted) | Redirects to root (`?dir=/`) |
| Folders not yet loaded from API | `loadSecrets()` awaits `fetchFolders()` before resolving the path |
| Folder renamed while viewing it | `FolderTree.onRenamed()` detects the stale URL and replaces it with the updated path |
| `/folders` without `?dir` parameter | Treated as `?dir=/` (root folder) |
| Browser back/forward | Vue Router updates `$route.query.dir`, which triggers the `dirPath` watcher to reload |
| Lock screen redirect | The full URL including `?dir=` is preserved in `returnUrl` |

## Key Files

| File | Role |
|------|------|
| `src/utils/folderPath.js` | Pure functions for path ↔ ID conversion |
| `src/router/index.js` | Route definition and custom `stringifyQuery` |
| `src/views/SecretList.vue` | Resolves `dirPath` prop to folder ID, loads secrets |
| `src/navigation/MainMenu.vue` | Derives `currentFolderId` from `?dir` query param |
| `src/components/FolderTree.vue` | Converts folder IDs to paths when navigating |
| `src/store/modules/folder.js` | `foldersById` getter used for path resolution |
