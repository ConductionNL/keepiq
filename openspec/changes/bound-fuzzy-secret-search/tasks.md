## 1. Backend — bound the fuzzy search scan

- [ ] 1.1 In `lib/Db/SecretMapper.php::searchByNameOrUrl()` (line 240), add a
      `$limit` parameter (default e.g. 200) and `->setMaxResults($limit)` on
      the query builder so Stage 1 itself is bounded.
- [ ] 1.2 In `lib/Service/SecretService.php::fuzzyMatch()` (line 448), replace
      the unbounded `countByOwner` + `findByOwner($total)` pair (lines
      464-466) with a paged scan: read fixed-size pages (reuse `clampLimit()`'s
      default page size or a dedicated `FUZZY_SCAN_PAGE_SIZE` constant),
      accumulate Levenshtein hits, and stop once either (a) the requested
      result window (`$page`/`$limit` from `search()`) is filled with margin
      for sorting, or (b) a hard candidate ceiling (`FUZZY_SCAN_MAX_CANDIDATES`,
      default 2000) is reached — whichever comes first.
- [ ] 1.3 Document the ceiling + page-size constants with a docblock
      explaining the trade-off (bounded cost vs. theoretical completeness
      past the ceiling) and reference this change's proposal.md.
- [ ] 1.4 Keep `search()`'s public return shape (`items`, `total`, `page`,
      `limit`) unchanged.

## 2. Tests

- [ ] 2.1 Add a PHPUnit test that seeds a mock `SecretMapper` returning more
      rows than `FUZZY_SCAN_MAX_CANDIDATES` would ever need loaded, and
      asserts `findByOwner()` is never invoked with a `$limit` equal to the
      full row count (regression guard for the exact bug this change fixes).
- [ ] 2.2 Add a PHPUnit test asserting a search term that exists past the
      first scanned page is still found within the candidate ceiling
      (bounded but not broken).
- [ ] 2.3 Re-run existing search-related tests (`tests/Unit/Service/*Secret*`,
      the Postman search cases) to confirm no behavioural regression on
      typical (small) vaults.

## 3. Spec sync

- [ ] 3.1 Apply the `specs/secrets/spec.md` delta in this change onto
      `openspec/specs/secrets/spec.md` once merged (`/opsx-sync` or manual).
