---
kind: code
---

# Favourites, tags and a last-used sort in the vault list

## Why

A vault list in Keepiq can be narrowed by folder and by secret type, and sorted by name, address, date created and date changed (`src/views/SecretList.vue:787-792`, `lib/Db/SecretMapper.php:48-53`). A person with two hundred logins has no way to keep the ten they use daily at hand, no way to label items across folders ("finance", "on call"), and no way to see what they actually used last. `docs/FEATURES.md:74` lists favourite secrets as a V1 feature and `:80` lists tags as an Enterprise feature; neither is built.

The three rows share one screen, the secret list and its filter menu, and one service, the paged list query, so they are one change.

### Matrix rows (keepiq `openspec/parity/capabilities.json`)

| row | capability | Keepiq today |
|---|---|---|
| `vault-09` | Mark items as favourites and filter on them. | `no`: no favourites concept on the entity, store or UI |
| `vault-10` | Label items with tags and filter by tag. | `no`: no tag storage, chip UI or filter |
| `vault-24` | Sort items by date added, date changed or date last used. | `partial`: name, date created and date updated sort; there is no last-used timestamp |

For `vault-24` the missing half is sorting by date last used. Sorting by date added and date changed is built.

### Demand

- `vault-24`: feature request, https://community.bitwarden.com/t/sorting-options-by-date-of-modification-addition-last-use-etc/2484
- `vault-09`, `vault-10`: no demand row. Both are in the core area (vault) with five and three competitors rating yes.

### Competitors rated yes

- `vault-09`, Bitwarden: "libs/common/src/vault/models/view/cipher.view.ts:46 favorite flag; libs/vault/src/services/vault-filter.service.ts:130 'favorites' filter ... Favourite flag per item and a favourites filter in every client."
- `vault-09`, 1Password: "select Add to Favorites... select Favorites in the sidebar" (https://support.1password.com/favorites-tags/).
- `vault-09`, Passbolt: "config/routes.php:81 POST /favorites/resource/{foreignId} ... DisplayResourcesList.js:156 CellFavorite star, ... ResourceWorkspaceContext.js:929 FAVORITE filter."
- `vault-09`, Keeper: "Record Favorites are used to easily identify your most frequently used records. Right-click on a record and select Add to Favorites" (https://docs.keeper.io/user-guides/web-vault#favorites).
- `vault-09`, Nextcloud Passwords: "src/vue/Section/Favorites.vue:32 API.findPasswords({favorite: true}) ... Favourite flag on passwords and folders, with a Favorites section that filters on it."
- `vault-10`, 1Password: "no limit the number of tags", "choose a tag in the sidebar" to filter (https://support.1password.com/favorites-tags/).
- `vault-10`, Passbolt: "plugins/PassboltEe/Tags/config/routes.php:28 POST /tags/{id} ... ResourceWorkspaceContext.js:924 TAG filter, :977 searchByTag ... Tags are a Pro plugin."
- `vault-10`, Nextcloud Passwords: "src/vue/Section/Tags.vue:51 find passwords by tag; src/vue/Dialog/CreatePassword/TagsField.vue ... Tags are first class objects, set in the password dialog or by batch, and the Tags section lists passwords per tag."
- `vault-24`: no competitor rated yes.

## What Changes

- **Favourites.** A star on each list row and in the secret detail sidebar marks the item as a favourite for the person who holds it. A Favourites filter in the list's filter menu shows only starred items.
- **Tags.** The create and edit dialogs get a tags field. Tags show as chips on list rows. The filter menu lists the holder's tags; picking one narrows the list. The bulk strip gets Add tag and Remove tag.
- **Last used.** Keepiq records when the holder last opened a secret's value in the web app or filled it from the browser extension, and the sort menu gets Last used.
- All three are per holder: a recipient's copy of a shared secret carries its own star, tags and last-used time, and the owner's changes never overwrite them.

## Capabilities

### New Capabilities

- `vault-list-organisation`: favourites, tags and a last-used sort on the vault list, per holder.

### Modified Capabilities

- None in delta form. The `secrets` list requirement (`openspec/specs/secrets/spec.md`, list and pagination) keeps its sort columns and gains one through this change's own requirement.

## Impact

- **Backend**: columns `is_favourite` and `last_used_at` on `keepiq_secrets`; a new table `keepiq_secret_tags`; `SecretMapper::findByOwner()` and `countByOwner()` learn a favourite and a tag filter; `SORTABLE_COLUMNS` gains `last_used_at`; `SecretService::get()` stamps `last_used_at`; a new extension route records a fill.
- **Frontend**: star toggle on `SecretListItem.vue` and the detail sidebar, tags field in `SecretCreateDialog.vue` and `SecretEditDialog.vue`, filter and sort options in `SecretList.vue`, bulk tag actions.
- **Browser extension**: after a fill, `service-worker.js` reports the used secret id.
- **Database**: one migration, `<version>` bump.
- **Security**: tags are stored in plain text, like folder names, and the proposal says so to the user in the tags field help text. No secret value is involved.
- **Cross-app**: none.
