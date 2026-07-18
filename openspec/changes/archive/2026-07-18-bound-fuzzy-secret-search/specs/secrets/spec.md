## MODIFIED Requirements

### Requirement: Search
The system MUST allow users to search their secrets by `name` and `url` using fuzzy matching. Search MUST tolerate typos up to a reasonable degree (e.g. Levenshtein distance ≤ 1 for strings up to 5 characters, ≤ 2 for longer strings) but MUST NOT return results with no meaningful similarity to the query.

Received shares MUST be included in search results and treated identically to owned secrets.

Search requires the master password to be in session (the user must be inside the app).

The fuzzy-match scan (SQL substring pre-filter plus in-memory Levenshtein post-filter) MUST be bounded per request — it MUST NOT load or Levenshtein-compare a user's entire secret set regardless of vault size. The scan MUST stop once either the requested result page is filled with enough margin to sort correctly, or a documented candidate ceiling is reached, whichever comes first.

#### Scenario: Search by name
- GIVEN a user has their master password in session
- WHEN they search for "Githb"
- THEN the system MUST return secrets whose name matches "GitHub" (typo tolerance)

#### Scenario: Search by url
- GIVEN a user has their master password in session
- WHEN they search for "github.com"
- THEN the system MUST return secrets whose url contains or fuzzy-matches "github.com"

#### Scenario: No meaningful match
- GIVEN a user searches for a string with no similarity to any name or url
- WHEN the query is processed
- THEN the system MUST return an empty result set

#### Scenario: Search scan is bounded regardless of vault size
- GIVEN a user's vault contains more secrets than the documented candidate ceiling
- WHEN they perform a search
- THEN the system MUST NOT issue a mapper query whose limit equals the user's total secret count
- AND the system MUST still return matches found within the bounded scan without a request timeout or unbounded memory growth
