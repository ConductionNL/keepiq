# Tasks: more website addresses per login, a site preview and a change-password link

## 1. Addresses

- [x] 1.1 Add the migration and a `SecretUrl` entity and mapper; accept `additionalUrls` (at most 20) on create and update in `SecretController` and `SecretService`; bump `<version>`. Verify: PHPUnit for store, replace and the cap.
      > `Version001020Date20261114090000` (`keepiq_secret_urls`), `SecretUrl` / `SecretUrlMapper`, and `SecretUrlService` (cap 20, trimmed, without repeats, at most 512 characters each, owner only). `SecretController::create` and `SecretUpdateController::update` take `additionalUrls`; the list is checked before anything is written. `show` answers it. Purge and account deletion remove it. PHPUnit: `SecretUrlServiceTest`, `SecretUrlMapperTest`, `SecretAddressesControllerTest`.
- [x] 1.2 Search the table in `searchByNameOrUrl()` and `findForUnifiedSearch()`, and return the list in the match endpoint. Verify: PHPUnit that a login is found and matched by an extra address.
      > Both searches match an extra address through a subquery on `keepiq_secret_urls`, and the extension's match answers `additionalUrls` for each login. PHPUnit: `SecretMapperQueriesTest`, the re-recorded query golden, and `SecretAddressesControllerTest::testTheMatchCarriesEveryAddress`.
- [x] 1.3 Copy the list onto share, group-share and team-folder copies at creation and on the owner's change. Verify: PHPUnit that a recipient copy gets the owner's new list.
      > `RecipientSecretCopyFactory::createCopy()` (direct, group and team-folder copies all go through it) gives a new copy its source's list (`SecretUrlService::copyOnto()`). `SecretUrlService::replace()` writes the owner's new list onto every copy whose share row the pending scan does not hold back and whose target holds it, through `ShareRowJudge`, and refuses a change from the holder of a received copy. PHPUnit: `SecretUrlServiceTest::testTheOwnersNewListReachesEveryRecipientCopy`, `testTheHolderOfAReceivedCopyIsRefused`, `testANewCopyGetsItsSourcesList`, `RecipientSecretCopyServiceTest::testANewCopyCarriesTheSourcesExtraAddresses`.
- [x] 1.4 Add the address list to the create and edit dialogs and the detail sidebar; include it in backup export, restore, the GDPR export and account deletion. Verify: Playwright flow add an extra address, search for it, the login is found; vitest on the serializer round trip.
      > `AdditionalUrlsField.vue` (one row per address, at most 20, plain-text help) in the create and edit dialogs, read-only on a received copy; the sidebar lists the extra addresses, linking only web addresses. The list endpoint answers `additionalUrls` per row, so the vault export carries them; `serializeVault` writes them, the backup parser reads them and the restore sends them to `ImportService`, which stores them after the login is created. The instance backup registry and the consolidated schema know `keepiq_secret_urls`; the GDPR package lists each login's extra addresses; account deletion already removed them (1.1). Vitest: `AdditionalUrlsField.spec.js`, the serializer round trip in `import-backupParser.spec.js`, the re-recorded payload-form golden. PHPUnit: `ImportServiceTest`, `SecretAddressesControllerTest::testTheListAnswersEachRowsExtraAddresses`, `SecretOrganisationDataTest::testGdprPackageCarriesTheExtraAddresses`. The Playwright flow is owed with the live pass.
- [x] 1.5 Score every address in `browser-extension/src/lib/match.js`. Verify: extension unit test that a login matches on its second address.
      > The matcher moved to `browser-extension/src/autofill/matcher.ts`: `candidatesFor()` offers a login when any of its addresses matches the page under the chosen rule; the rows come from the list endpoint, which now answers `additionalUrls`. Vitest: `matcher.test.ts` 'matches a login on its second address, under the same rule as the first'.

## 2. Site images

- [ ] 2.1 Add `site_preview_service_url` to `AdminSettingsService` and a `SiteImagesSection.vue` admin section for both templates with the privacy text. Verify: PHPUnit on validation (https only, placeholder present), and a Playwright check of the section.
- [ ] 2.2 Show the preview image in the detail sidebar when the template is set, with no-referrer. Verify: vitest that nothing is requested when the template is empty.

## 3. Change password

- [ ] 3.1 Add Change password to the detail sidebar for logins with an address and to weak, reused and breached findings in `HealthCategory.vue`, opening the host's well-known address in a new tab. Verify: vitest on the URL built from the address, and a Playwright check that the link target is `/.well-known/change-password` on the host.

## 4. Docs

- [ ] 4.1 Document extra addresses, site images with their privacy note, and the change-password link. Verify: docs build.

## Acceptance criteria

- A login with extra addresses is found by search and suggested by the extension on each of them.
- Site previews appear only after an administrator sets a preview service, and the admin section says what leaves the instance.
- Change password opens the site's well-known change-password address.
