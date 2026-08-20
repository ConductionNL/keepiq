## Why

`SecretService::search()` (`lib/Service/SecretService.php:414`) delegates every
fuzzy search to `fuzzyMatch()` (`lib/Service/SecretService.php:448-479`). That
method's "Stage 2" Levenshtein post-filter (lines 464-476) is unbounded by
design:

```php
$total = $this->mapper->countByOwner('user', $userId, null);
$all   = $this->mapper->findByOwner('user', $userId, null, 'name', 'asc', max(1, $total), 0);

foreach ($all as $secret) {
    ...
    if ($this->isFuzzyHit(secret: $secret, termLower: $termLower, tolerance: $tolerance) === true) {
```

Every call to `search()` — which the frontend fires on every debounced keystroke
(`src/views/SecretList.vue:216-220`, 300ms `setTimeout`) — loads **the user's
entire secret set** (`findByOwner` called with `$limit = $total`, i.e. no
limit) and runs a PHP Levenshtein comparison (`isFuzzyHit`) against every row,
every time. `list()` (the plain list endpoint, same file lines 380-401) is
correctly paginated with `clampLimit()`; `search()`'s fuzzy path bypasses that
discipline entirely.

This is not a per-request-negligible cost: Doriath is a password vault, and a
long-lived user's secret count is exactly the kind of value that grows
unbounded over years of use (this is the explicit scaling concern password-health
already reasons about — "large vaults" get a web worker for client-side
scoring, but the *server-side* search path has no equivalent safeguard).
Every keystroke pause during search re-scans and re-Levenshteins the full
vault server-side, for every request, for as long as the session is open.

`searchByNameOrUrl()` (`lib/Db/SecretMapper.php:240-257`, the Stage-1 SQL
substring pre-filter) is unbounded too — no `LIMIT` clause — but is a cheap
indexed-adjacent `iLike` scan; the expensive, unbounded part is Stage 2's
full-vault load + PHP-side Levenshtein.

## What Changes

- Cap the Levenshtein post-filter (`fuzzyMatch()` Stage 2) to a bounded
  candidate set instead of the user's entire vault: read in fixed-size pages
  via the existing `findByOwner(limit, offset)` and stop once enough matches
  to fill the requested result page have been found, or a hard candidate
  ceiling is reached (configurable, default documented in tasks/spec).
- Add a `LIMIT` to `searchByNameOrUrl()`'s SQL pre-filter so Stage 1 itself
  cannot return an unbounded row set to Stage 2.
- Add a PHPUnit test asserting `fuzzyMatch()` issues a bounded number of rows
  from the mapper regardless of the user's total secret count (mock the
  mapper, assert `findByOwner` is never called with the full count as limit).
- No API contract change: `search()`'s public shape (`items`, `total`, `page`,
  `limit`) is unchanged; `total` becomes "matches found within the bounded
  scan" rather than "matches found across the whole vault" — documented as an
  explicit trade-off in the spec delta below (see "Non-breaking accuracy
  trade-off").

## Impact

- **Backend**: `lib/Service/SecretService.php` (`fuzzyMatch`), `lib/Db/SecretMapper.php` (`searchByNameOrUrl`).
- **Tests**: new/updated PHPUnit coverage in `tests/Unit/Service/SecretServiceTest.php` (or wherever the existing search tests live) asserting bounded mapper calls.
- **API**: no route or DTO change. Existing Postman/e2e coverage of `search()`'s happy path continues to pass; add one Postman case with a vault seeded past the candidate ceiling if the environment allows it — otherwise cover via PHPUnit only (annotate `@e2e exclude` with this change's reference if a live large-vault Postman fixture isn't practical).
- **Not BREAKING**: this narrows result completeness for a currently-impossible edge case (a single user with more secrets than the bounded ceiling) in exchange for bounding a per-keystroke O(n) DB+CPU cost; no consumer of `search()`'s response shape changes.
