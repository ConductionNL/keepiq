## 1. Backend — bound the fuzzy search scan

- [x] 1.1 In `lib/Db/SecretMapper.php::searchByNameOrUrl()` (line 240), add a
      `$limit` parameter (default e.g. 200) and `->setMaxResults($limit)` on
      the query builder so Stage 1 itself is bounded — added `int $limit=200`
      + `->setMaxResults(max(1, $limit))`; the service calls it with
      `FUZZY_SCAN_MAX_CANDIDATES`
- [x] 1.2 In `lib/Service/SecretService.php::fuzzyMatch()` (line 448), replace
      the unbounded `countByOwner` + `findByOwner($total)` pair with a paged
      scan: read fixed-size pages (`FUZZY_SCAN_PAGE_SIZE` = 200), accumulate
      Levenshtein hits, and stop once either (a) the requested result window
      (passed as `$targetCount` from `search()`) is filled with margin, or (b)
      the hard ceiling (`FUZZY_SCAN_MAX_CANDIDATES` = 2000) is reached
- [x] 1.3 Document the ceiling + page-size constants with a docblock
      explaining the trade-off and referencing this change's proposal.md —
      done on both `FUZZY_SCAN_PAGE_SIZE` and `FUZZY_SCAN_MAX_CANDIDATES`
- [x] 1.4 Keep `search()`'s public return shape (`items`, `total`, `page`,
      `limit`) unchanged — unchanged; only the internal `fuzzyMatch` signature
      gained an optional `$targetCount=0` (backward compatible)

## 2. Tests

- [x] 2.1 Add a PHPUnit test that seeds a mock `SecretMapper` returning more
      rows than the ceiling would need, and asserts `findByOwner()` is never
      invoked with a `$limit` equal to the full row count —
      `testFuzzyScanIsBoundedByCandidateCeiling`: every page request uses the
      fixed page size (never `countByOwner`'s 1,000,000), total scanned ≤ ceiling
- [x] 2.2 Add a PHPUnit test asserting a term that exists past the first
      scanned page is still found within the candidate ceiling —
      `testFuzzyFindsMatchOnLaterPage`: target on page 2 (offset 200) is found
- [x] 2.3 Re-run existing search-related tests to confirm no behavioural
      regression on typical (small) vaults — full SecretServiceTest 21/21 green
      (the 3 pre-existing fuzzy tests still pass unchanged). Postman large-vault
      case requires a live seeded instance; not run here (`@e2e exclude` —
      needs a live vault seeded past the ceiling)

## 3. Spec sync

- [ ] 3.1 Apply the `specs/secrets/spec.md` delta onto
      `openspec/specs/secrets/spec.md` once merged (`/opsx-sync` or manual) —
      DEFERRED: spec-sync/archive is a post-merge Hydra step, out of scope for
      the apply pass
