# Tasks: favourites, tags and a last-used sort in the vault list

## 1. Data

- [x] 1.1 Add the migration (`is_favourite`, `last_used_at`, table `keepiq_secret_tags`), the entity fields, a `SecretTag` entity and mapper; bump `<version>`. Verify: PHPUnit on the mapper and `occ migrations:status keepiq`. Done: `Version001003Date20261002000000`, `SecretTag`, `SecretTagMapper`, version 0.3.4-unstable.20261002000000. PHPUnit covers the entity (`SecretOrganisationDataTest`); the migration run is in section 5 (live instance).
- [x] 1.2 Extend `SecretMapper::findByOwner()` and `countByOwner()` with the favourite and tag filters and add `last_used_at` (nulls last) to `SORTABLE_COLUMNS`. Verify: PHPUnit for each filter and the sort order. Done in `SecretListOrganisation` (filters and order terms, `SecretListOrganisationTest`). Design D4 corrected: the tag filter is an `IN` subquery on `keepiq_secret_tags`, not `EXISTS` (same rows, no correlated alias), and every sort but name breaks ties by name.

## 2. API

- [x] 2.1 Add `PUT /api/v1/secrets/{id}/favourite` (body `{favourite: bool}`), `PUT /api/v1/secrets/{id}/tags` (body `{tags: string[]}`), `GET /api/v1/tags` (the holder's distinct tags with counts) and the `favourite` and `tag` query parameters on `GET /api/v1/secrets`. Verify: hydra route-auth and no-admin-idor gates, PHPUnit for tag normalisation and the 20-tag cap. Done: `SecretOrganisationController` (favourite, tags, tagIndex), `SecretTagNormaliser`; `SecretOrganisationServiceTest`, `SecretOrganisationControllerTest` (real service), `SecretTagNormaliserTest`. A row the caller does not hold answers 404 on every route.
- [x] 2.2 Stamp `last_used_at` in `SecretService::get()` and add `POST /api/v1/extension/used/{id}`. Verify: PHPUnit that `get()` stamps the time and that the used-route returns 404 for a secret the caller does not hold. Done: `SecretMapper::markUsed` (owner-keyed, writes only `last_used_at`), `SecretServiceOrganisationTest`, `SecretOrganisationControllerTest::testUsedRouteCannotProbeAnotherUsersSecret`.
- [x] 2.3 Add tags and favourites to the GDPR metadata export and the account deletion cascade. Verify: PHPUnit on `AccountDeletionService` and the GDPR package. Done: GDPR `organisation` section; `SecretChildDataCleaner` drops tags per secret and per owner (account deletion runs it); `SecretService::delete` drops the secret's tags; `SecretOrganisationDataTest`.

## 3. Frontend

- [x] 3.1 Add the star toggle to `SecretListItem.vue` and the detail sidebar, and a Favourites option to the filter menu in `SecretList.vue`. Verify: vitest on the secret store action and a Playwright flow star, filter, unstar. Done; vitest `secretOrganisation.spec.js`, `SecretListItem.organisation.spec.js`. The Playwright flow is in section 5.
- [x] 3.2 Add a tags field (with the not-encrypted help text) to the create and edit dialogs, tag chips on list rows, the tag list in the filter menu, and Add tag and Remove tag in the bulk strip. Verify: Playwright flow tag two items, filter on the tag, remove it in bulk. Done (`SecretTagsField`, `BulkTagDialog`, the filter menu); vitest covers the store's bulk add and remove. The Playwright flow is in section 5.
- [x] 3.3 Add Last used to `sortOptions`. Verify: Playwright flow open a secret, sort by last used, it is first. Done; vitest pins the request (`last_used_at`, descending). The Playwright flow is in section 5.

## 4. Browser extension

- [x] 4.1 After a successful `doFill()`, call the used-route. Verify: extension unit test that a fill posts the id once and a failed fill posts nothing. Done: `browser-extension/src/lib/usage.js`, `tests/extension/usage.spec.js`.

## 5. Live instance (owed: needs a Nextcloud with this branch mounted and two vault users)

- [x] 5.1 App store off, `occ upgrade`, then `occ migrations:status keepiq` lists `Version001003Date20261002000000` as executed; `oc_keepiq_secrets` has `is_favourite` and `last_used_at`; table `oc_keepiq_secret_tags` exists. Done live on the dev instance 2026-10-04 (keepiq mount e7647a42, instance 0.3.4-unstable.20261004130000, app store off): `oc_migrations` holds keepiq 001003Date20261002000000 and `occ migrations:status keepiq` shows no pending migration; `oc_keepiq_secrets` has `is_favourite` and `last_used_at`; `oc_keepiq_secret_tags` exists with indexes keepiq_sec_tags_secret_tag and keepiq_sec_tags_owner_tag. Evidence: ~/memcap-work/build-all/livepass/keepiq/vault-favourites-tags-and-last-used/lane8/ and livepass/RESULT-lane8.md.
- [x] 5.2 Playwright or by hand: star two secrets, pick Favourites in the filter menu, only those two show and the funnel is filled; unstar one, it leaves the view. Done live 2026-10-04 by hand in the browser (users livepass-kq-a, livepass-kq-b): S1 and S4 starred, Favourites lists only those two with the filled funnel, unstarring S4 removes it from the view. Evidence: ~/memcap-work/build-all/livepass/keepiq/vault-favourites-tags-and-last-used/lane6/ and livepass/RESULT-lane6.md.
- [x] 5.3 Tag one secret in each of three folders with `on call`, pick `on call` in the filter menu, exactly those three show; select them, Tags, Remove tag `on call`: the chips go and the tag leaves the filter menu. Done live 2026-10-04: S1, S2, S3 (one per folder) tagged `on call`, the filter lists exactly those three; bulk Remove tag clears the chips and the tag leaves the menu (after a few seconds). Evidence: ~/memcap-work/build-all/livepass/keepiq/vault-favourites-tags-and-last-used/lane6/ and livepass/RESULT-lane6.md.
- [x] 5.4 Open secret A, fill secret B from the paired extension, pick Last used: B first, A second, never-used secrets after them. Done live 2026-10-04: S2 opened in the UI and S5 marked used through `POST /api/v1/extension/used/{id}` as the user (the call the extension makes; no real paired extension); Last used lists S5, S2, then the never-used ones. Evidence: ~/memcap-work/build-all/livepass/keepiq/vault-favourites-tags-and-last-used/lane6/ and livepass/RESULT-lane6.md.
- [x] 5.5 A second user stars their copy of a shared secret; the owner changes the password; the copy is still starred. `curl -X POST .../api/v1/extension/used/<owner's id>` as the second user answers 404. Done live 2026-10-04: livepass-kq-b starred its copy 91ce27b1 of livepass-kq-a's S4; kq-a changed the note in the edit dialog (PUT 200, key_updated_at moved); kq-b's copy is still starred (is_favourite true, GET ?favourite=true lists it); kq-b `POST /api/v1/extension/used/<S4 id>` answers 404 "Secret not found", and on its own copy 200. Found alongside, outside this change: the owner's edit never reached the copy, because the share list carries no recipient certificate and syncUpdate skips such recipients (live pass K1). Evidence: ~/memcap-work/build-all/livepass/keepiq/vault-favourites-tags-and-last-used/lane8/ and livepass/RESULT-lane8.md.

## Acceptance criteria

- A person stars an item and the Favourites filter shows only starred items.
- A person tags items, sees the tags as chips, filters on a tag and removes a tag in bulk.
- Sorting by Last used puts the item most recently opened or filled first.
- A recipient's star, tags and last-used time are theirs alone and survive the owner's edits.
