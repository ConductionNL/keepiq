## Context

Expiry on a secret request is half-built: enforced on access, never set by default, never swept, and unrepresentable in the status vocabulary.

- **Enforced.** `SecretRequestPolicy::requireOpenByToken()` calls `SecretRequest::isExpired()` and answers 408 once `expires_at` has passed. This works.
- **Never set.** `expires_at` has exactly one source — a requester typing into an optional `datetime-local` input that defaults to `''`. Nothing else populates it: the service never defaults it, and the machine surface leaves it null when omitted.
- **Never swept.** No job looks at `expires_at`. A lapsed request stays `pending` forever and keeps whatever placeholder Secret it created.
- **Unrepresentable.** Statuses are `pending`, `fulfilled`, `declined`, `locked`. There is no way to record that a request lapsed, nor to tell a lapse from a cancellation.

The order matters: a sweeper alone would be nearly inert, because almost nothing has an expiry to sweep. That is why this change sets an expiry as well as acting on one.

## Goals / Non-Goals

**Goals:**
- Requests created through the UI carry an expiry unless the requester clears it.
- A lapsed request is swept: token dead, placeholder gone, record kept.
- A lapse is distinguishable from a cancellation after the fact.
- A lapsed request is refused on access whether or not the sweeper has run.
- Requests with no expiry keep working exactly as they do today.

**Non-Goals:**
- An admin-configurable instance-wide default expiry. `rotation-expiry-policies` holds that pattern ("an instance-wide admin default (shipped disabled) … and a per-user override") and it belongs there, overriding the client default when it exists. The client-side default here is deliberately the lighter half.
- Backfilling an expiry onto requests that already exist without one. They stay manual-only, which is what Optional Expiry says.
- Notifying the requester that a request lapsed. Defensible, but it is a notification-surface decision with its own preference gating; out of scope.
- Changing what the machine create surface accepts. It already takes an optional `expiresAt` and benefits from the sweeper for free.
- The request-first creation flow, the placeholder invariant, or the outstanding-request indicator. Those live in `request-first-secret-requests`.

## Decisions

### A client-side suggested default, not a server policy

The create surface pre-fills the expiry field with a suggested value; the requester can change or clear it. Reasons for putting it there rather than in the service:

- It changes no server semantics, needs no admin setting and no migration, and cannot alter the meaning of a request created by an API client.
- It stays spec-legal. Optional Expiry says "The requester MAY set an `expires_at`" — a pre-filled field the requester can clear is still the requester choosing. A perpetual request remains available to anyone who wants one; it just stops being what you get by accident.
- The failure it fixes is a UX failure. An optional datetime field with no default is rarely filled, and that is the interface's fault rather than the user's.

The concrete default interval is deliberately left to the implementer to confirm with the product owner rather than baked into this design; the spec says "a suggested expiry" and asserts only that it must be clearable.

### A terminal `expired` status rather than a silent revoke

The sweeper could simply call the existing revoke path. It does not, because revoke means "the requester changed their mind" and expiry means "time ran out", and the two have different consequences for someone looking at a vault that is missing a row.

`expired` is therefore added as a terminal status. On sweep: the token is invalidated, a fresh request's placeholder Secret is deleted, and the request row survives as the record. A re-request's Secret and its current values are preserved — the request lapsing must never cost the user a working credential. `status` is already a string column, so this needs no migration.

The audit actor is the system. Recording the requester as the actor for something they did not do would corrupt the trail that `secret-requests` shares with the rest of the audit surface.

### The sweeper is cleanup; the gate is enforcement

These two must not be confused, and the interval is why. A `TimedJob` runs hourly (matching `ExpireMachineLeasesJob`'s `setInterval(seconds: 3600)`), so a request that lapsed a minute ago still reads `pending` in the database. Any gate that answered from the stored status alone would accept a submission after the expiry the requester set.

The expiry evaluation is therefore hoisted above the status switch in `requireOpenByToken()`, applying to any status. What that hoist buys has to be stated precisely, because the weaker claim is the true one and the implementation's own test proved it: the `pending` branch **already** checked expiry, so a lapsed pending request was refused before this change. The hoist is not closing a live hole. It buys two things:

- **Precedence.** A lapsed request now reports EXPIRY even when another status would also refuse it. A locked request whose expiry passed says "expired" rather than "temporarily unavailable" — truer, since locked invites a retry that can never succeed.
- **Safety against omission.** A status added later cannot bypass expiry by forgetting to check it, because the check no longer lives inside a per-status branch.

Stated this way on purpose. The earlier framing here — that the sweeper's schedule would otherwise become part of the security boundary — overstated the fix by describing a gate this codebase never had.

The same hoist also fixes a defect this change would otherwise introduce. The switch ends in:

```
default:
    throw new InvalidArgumentException(message: 'Request is in an unknown state', code: 500);
```

A new `expired` status with no case of its own falls into that arm, so every legitimately expired link would answer **500** instead of telling the recipient it expired. `expired` gets an explicit arm in the 410 family alongside `fulfilled` and `declined`.

### Only requests that opted into an expiry are swept

The job's query is narrow on purpose: `status = pending` AND `expires_at IS NOT NULL` AND `expires_at < now`. A request with no expiry is never touched, because Optional Expiry already promises it "remains open until fulfilled or manually revoked". Widening the sweep to cover perpetual requests would break that promise and silently delete vault rows nobody agreed to give up.

## Risks / Trade-offs

- **The first run may sweep a batch.** Requests that lapsed long ago are all eligible at once. That is the intended cleanup, but the first run on an established instance may transition and delete more than an operator expects, so it is worth stating in the release notes rather than discovering.
- **Placeholder deletion is irreversible.** A swept fresh request takes its empty Secret with it. Since the Secret never held a value this loses nothing, but the request row must survive to explain the disappearance — which is the reason for the terminal status rather than a hard delete.
- **A pre-filled default changes the character of new requests.** Requests that would once have stayed open forever now lapse. That is the point, and it is why the field must remain clearable; a requester who wants a perpetual link must still be able to have one in one action.
- **No notification on lapse.** A requester may discover a request lapsed only when they look. Deferred deliberately (see Non-Goals), and the terminal status is what makes adding it later straightforward.
- **Shared file with `request-first-secret-requests`.** Both edit `SecretRequestCreateDialog.vue`. Whichever lands second absorbs a small conflict; this change touches one field, so it is the cheaper one to rebase.
