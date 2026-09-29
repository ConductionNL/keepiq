# Tasks: favourites, tags and a last-used sort in the vault list

## 1. Data

- [ ] 1.1 Add the migration (`is_favourite`, `last_used_at`, table `keepiq_secret_tags`), the entity fields, a `SecretTag` entity and mapper; bump `<version>`. Verify: PHPUnit on the mapper and `occ migrations:status keepiq`.
- [ ] 1.2 Extend `SecretMapper::findByOwner()` and `countByOwner()` with the favourite and tag filters and add `last_used_at` (nulls last) to `SORTABLE_COLUMNS`. Verify: PHPUnit for each filter and the sort order.

## 2. API

- [ ] 2.1 Add `PUT /api/v1/secrets/{id}/favourite` (body `{favourite: bool}`), `PUT /api/v1/secrets/{id}/tags` (body `{tags: string[]}`), `GET /api/v1/tags` (the holder's distinct tags with counts) and the `favourite` and `tag` query parameters on `GET /api/v1/secrets`. Verify: hydra route-auth and no-admin-idor gates, PHPUnit for tag normalisation and the 20-tag cap.
- [ ] 2.2 Stamp `last_used_at` in `SecretService::get()` and add `POST /api/v1/extension/used/{id}`. Verify: PHPUnit that `get()` stamps the time and that the used-route returns 404 for a secret the caller does not hold.
- [ ] 2.3 Add tags and favourites to the GDPR metadata export and the account deletion cascade. Verify: PHPUnit on `AccountDeletionService` and the GDPR package.

## 3. Frontend

- [ ] 3.1 Add the star toggle to `SecretListItem.vue` and the detail sidebar, and a Favourites option to the filter menu in `SecretList.vue`. Verify: vitest on the secret store action and a Playwright flow star, filter, unstar.
- [ ] 3.2 Add a tags field (with the not-encrypted help text) to the create and edit dialogs, tag chips on list rows, the tag list in the filter menu, and Add tag and Remove tag in the bulk strip. Verify: Playwright flow tag two items, filter on the tag, remove it in bulk.
- [ ] 3.3 Add Last used to `sortOptions`. Verify: Playwright flow open a secret, sort by last used, it is first.

## 4. Browser extension

- [ ] 4.1 After a successful `doFill()`, call the used-route. Verify: extension unit test that a fill posts the id once and a failed fill posts nothing.

## Acceptance criteria

- A person stars an item and the Favourites filter shows only starred items.
- A person tags items, sees the tags as chips, filters on a tag and removes a tag in bulk.
- Sorting by Last used puts the item most recently opened or filled first.
- A recipient's star, tags and last-used time are theirs alone and survive the owner's edits.
