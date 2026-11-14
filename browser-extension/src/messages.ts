/**
 * Every message envelope that crosses a runtime boundary, in one place. Payloads
 * arrive as `unknown` (WXT-AND-BROWSERS.md § 3), so each listener casts to one of
 * these unions once, at its boundary.
 */

import type { AccountSettings, VaultTimeout } from '@/src/accounts/settings'
import type { GeneratorHistoryEntry, GeneratorOptions, GeneratorPolicy } from '@/src/generator/options'
import type { CredentialJson, EnrolmentBody, EnrolOptions, ReleasedKey, UnlockOptions } from '@/src/vault/passkey-unlock'

/** A kind set that must name every kind of the union `K`, and nothing else. */
export const kindSet = <K extends string>(kinds: Record<K, true>): ReadonlySet<string> => new Set(Object.keys(kinds))

/** Content script → background. Tab, frame and URL come from `sender`, never from the message. */
export type ContentToBackground =
	/** Fire-and-forget. `passwordField`: the top frame has a login form with one, for fill on page load. */
	| { kind: 'page_ready'; url: string; passwordField: boolean }
	| InlineOpen
	| InlineClosed
	| CaptureSubmitted
	/**
	 * Fire-and-forget, top frame only. The host closed its bar to show it inside a modal the page opened, on a browser
	 * that cannot move an iframe without reloading it (no `moveBefore`); the background answers with a fresh `capture.show`.
	 */
	| { kind: 'capture.reshow' }
	| PasskeyContentToBackground

/** The only kinds a content script may send. */
export const CONTENT_KINDS = kindSet<ContentToBackground['kind']>({
	'page_ready': true, 'inline.open': true, 'inline.closed': true, 'capture.submitted': true, 'capture.reshow': true,
	'passkey.start': true, 'passkey.abort': true, 'passkey.available': true,
})

// Passkeys, ext-passkey-provider design.md § Messages. Every byte string is base64url.

/** A `PublicKeyCredentialDescriptor`. */
export interface PasskeyDescriptor {
	type: string
	id: string
	transports?: string[]
}

/** The `publicKey` members of `create()` Keepiq reads; other extensions than `credProps` are left out. */
export interface PasskeyCreateOptions {
	rp: { id?: string; name: string }
	user: { id: string; name: string; displayName: string }
	challenge: string
	pubKeyCredParams: Array<{ type: string; alg: number }>
	timeout?: number
	excludeCredentials: PasskeyDescriptor[]
	authenticatorSelection?: { authenticatorAttachment?: string; residentKey?: string; requireResidentKey?: boolean; userVerification?: string }
	attestation?: string
	extensions?: { credProps?: boolean }
}

/** The `publicKey` members of `get()` Keepiq reads. */
export interface PasskeyGetOptions {
	challenge: string
	timeout?: number
	rpId?: string
	allowCredentials: PasskeyDescriptor[]
	userVerification?: string
}

/** `conditional` only with `get`; `silent` and `immediate` go to the browser. */
export type PasskeyMediation = 'optional' | 'required' | 'conditional'

export type PasskeyRequest =
	| { type: 'create'; options: PasskeyCreateOptions; mediation?: PasskeyMediation }
	| { type: 'get'; options: PasskeyGetOptions; mediation?: PasskeyMediation }

/** WebAuthn's `RegistrationResponseJSON` response; `publicKey` is the SPKI. */
export interface PasskeyAttestationJSON {
	clientDataJSON: string
	attestationObject: string
	authenticatorData: string
	publicKey: string
	publicKeyAlgorithm: number
	transports: string[]
}

/** WebAuthn's `AuthenticationResponseJSON` response. */
export interface PasskeyAssertionJSON {
	clientDataJSON: string
	authenticatorData: string
	signature: string
	userHandle: string | null
}

export interface PasskeyCredentialJSON {
	id: string
	rawId: string
	type: 'public-key'
	authenticatorAttachment: 'platform'
	clientExtensionResults: { credProps?: { rk: boolean } }
	/** By the ceremony's kind: an attestation for `create`, an assertion for `get`. */
	response: PasskeyAttestationJSON | PasskeyAssertionJSON
}

export const PASSKEY_ERRORS = ['NotAllowedError', 'InvalidStateError', 'AbortError'] as const
export type PasskeyErrorName = typeof PASSKEY_ERRORS[number]

/** `fallback`: the page script calls the browser's own method with the page's arguments. */
export type PasskeyResult =
	| { type: 'credential'; credential: PasskeyCredentialJSON }
	| { type: 'error'; name: PasskeyErrorName }
	| { type: 'fallback' }

/** `crossOriginAncestor`: from the isolated world; tab, frame and origin come from `sender`. */
export type PasskeyContentToBackground =
	/** Request/response, reply `PasskeyStartReply`; the result follows as `passkey.result`. */
	| { kind: 'passkey.start'; request: PasskeyRequest; crossOriginAncestor: boolean }
	/** Fire-and-forget: the page aborted, or the frame went away. */
	| { kind: 'passkey.abort'; ceremonyId: string }
	/** Request/response, reply `boolean`: whether the provider would handle a request from this frame. */
	| { kind: 'passkey.available'; crossOriginAncestor: boolean }

export type PasskeyStartReply = { ceremonyId: string } | { fallback: true }

/** Page script → relay, by `window.postMessage` with the call's own `MessageChannel` port. */
export type PageToRelay =
	| { channel: 'keepiq.passkey'; kind: 'create'; id: string; options: PasskeyCreateOptions; mediation?: string }
	| { channel: 'keepiq.passkey'; kind: 'get'; id: string; options: PasskeyGetOptions; mediation?: string }
	| { channel: 'keepiq.passkey'; kind: 'available'; id: string }

/** Page script → relay over the port. */
export type PagePortMessage = { kind: 'abort'; id: string }

/** Relay → page script over the port: `ack` at once, then one answer. `available`: answer `true`. */
export type RelayToPage = { type: 'ack' } | { type: 'available' } | PasskeyResult

/** The passkey window → background. Each names its ceremony, so a stale window cannot answer a newer one. */
export type PasskeyPopupRequest = { ceremonyId: string } & (
	| { kind: 'passkey.state' }
	| { kind: 'passkey.pick'; itemId: string; credentialId: string }
	/** `replace`: the user confirmed replacing the login's passkey for the same relying party and user. */
	| { kind: 'passkey.save'; target: { itemId: string; replace?: boolean } | { name: string; folderId: string | null } }
	/** "Confirm it's you", and the window's unlock: only an unlock through here sets the user-verified flag. */
	| { kind: 'passkey.verify'; method: UnlockMethod }
	| { kind: 'passkey.retry' }
	| { kind: 'passkey.fallback' }
	| { kind: 'passkey.cancel' }
)

/** A passkey offered for a sign-in: the login's name and the passkey's user name, nothing secret. */
export interface PasskeyRow {
	itemId: string
	credentialId: string
	name: string
	userName: string
}

/** A login a `create` may save to. `replace`: it holds a passkey for this RP and user, named by that passkey's user name. */
export interface PasskeySaveTarget {
	itemId: string
	name: string
	username: string
	replace: { userName: string } | null
}

/** What the passkey window shows of its ceremony; `rpName` and `userName` are the page's own, to show as text. */
export interface PasskeyView {
	kind: 'create' | 'get'
	/** A conditional `get` opened from the inline list: no "Use your device or hardware key", and Cancel keeps the request. */
	conditional: boolean
	rpId: string
	host: string
	rpName: string
	userName: string
	locked: boolean
	/** "Confirm it's you" comes before a pick or a save. */
	verify: boolean
	/** A `get`'s passkeys; always empty while locked. */
	rows: PasskeyRow[]
	/** A `create`'s matching logins, last used first, then "Save as new login" with these folders by path; empty while locked. */
	targets: PasskeySaveTarget[]
	folders: Array<{ id: string; name: string }>
	/** A `create` excluding a passkey the vault holds: "already saved", whose Close (`passkey.cancel`) answers `InvalidStateError`. */
	alreadySaved: boolean
	/** The last pick or save failed; `passkey.retry` runs it again. Null while locked. */
	failure: VaultWriteError | null
	/** A passkey picked in the inline list, one of `rows`: the window opens on "Confirm it's you" for it. Null while locked. */
	pending: PasskeyRow | null
}

/**
 * `ended`: the ceremony is over or was never this window's. `verify_required`: "Confirm it's you" first.
 * `stale`: the passkey or login picked is no longer offered. `replace_required`: ask Replace first.
 * `write_failed`: the counter save or the passkey save failed, as `error` says; the ceremony stays open for Retry.
 * An `ErrorCode` comes from unlocking at `passkey.verify`.
 */
export type PasskeyFailure = 'ended' | 'locked' | 'verify_required' | 'stale' | 'already_saved' | 'replace_required' | 'write_failed' | ErrorCode

export type PasskeyWindowReply =
	| { ok: true; view?: PasskeyView }
	| { ok: false; code: PasskeyFailure; attemptsLeft?: number; error?: VaultWriteError }

export const PASSKEY_POPUP_KINDS = kindSet<PasskeyPopupRequest['kind']>({
	'passkey.state': true, 'passkey.pick': true, 'passkey.save': true, 'passkey.verify': true,
	'passkey.retry': true, 'passkey.fallback': true, 'passkey.cancel': true,
})

// Autofill, ext-autofill design.md § Message contract.

export type FillOutcome = 'both' | 'username' | 'password' | 'none'

/**
 * Popup → background, and the menu, command and page-load paths in the background. `tabId` omitted means the active tab.
 * `confirmed`: the answers to a card's or identity's `needsFormConfirm`.
 */
export type FillRequest = { kind: 'fill.request'; itemId: string; tabId?: number; confirmedInsecure?: boolean; confirmed?: FormConfirmation }
/**
 * `code`: a one-time code was filled; a fill filled something when `filled !== 'none' || code`.
 * `form`: a card or identity filled at least one field. `noFields`: no frame of the tab had a field for the item.
 */
export type FillResult = { filled: FillOutcome | 'form'; code: boolean; needsInsecureConfirm?: boolean; needsFormConfirm?: FormQuestion; noFields?: true }

/** What a menu fill without a form says in the page (ext-context-menu). */
export type PageNoticeCode = 'no_login_form' | 'no_card_form' | 'no_identity_form'

export const PAGE_NOTICE_CODES: readonly PageNoticeCode[] = ['no_login_form', 'no_card_form', 'no_identity_form']

/** The add form the context menu's Add asked for. */
export type AddIntent = { add: 'login' | 'card' | 'identity' }

// Cards and identities, ext-card-identity-fill design.md § Message contract.

export type FormKind = 'card' | 'identity'

export const FORM_ROLES = {
	card: ['cardholder', 'number', 'expiry', 'expMonth', 'expYear', 'securityCode', 'brand'],
	identity: ['fullName', 'firstName', 'lastName', 'email', 'phone', 'streetAddress', 'addressLine1'],
} as const satisfies Record<FormKind, readonly string[]>

export type CardRole = typeof FORM_ROLES.card[number]
export type IdentityRole = typeof FORM_ROLES.identity[number]
export type FormRole = CardRole | IdentityRole

export const isFormRole = (kind: FormKind, role: unknown): role is FormRole => (FORM_ROLES[kind] as readonly unknown[]).includes(role)

export type FormMember =
	| 'cardholder' | 'number' | 'expMonth' | 'expYear' | 'cvv' | 'brand' | 'brandId'
	| 'fullName' | 'firstName' | 'lastName' | 'email' | 'phone' | 'streetAddress' | 'addressLine1'
/**
 * Only the members the receiving frame's roles need: `number` digits only, `expMonth` "01" to "12", `expYear` four digits, `streetAddress` with its line breaks.
 * `brand` is the brand's name, `brandId` its `card-brands.json` id, which picks its codes in a `<select>`.
 * Never a card's PIN or an identity's BSN.
 */
export type FormValues = Partial<Record<FormMember, string>>

/**
 * `frames: 'sameSite'` is "Only {host}". It carries every answer so far, since the background keeps none.
 * `origin`: the question's, echoed back; the background ignores the answers on any other top origin.
 */
export type FormConfirmation = { insecure?: true; frames: 'all' | 'sameSite'; frameHosts: string[]; origin: string }
/** What is still unanswered: `insecure` the card question, `frameHosts` the cross-site one when not empty. `host` to name, `origin` to echo: the top frame's. */
export type FormQuestion = { insecure: boolean; host: string; origin: string; frameHosts: string[] }

/** Background → one frame, before anything is decrypted; carries no vault data. Reply `FillProbeReply`. */
export type FillProbe = { kind: 'fill.probe'; form: FormKind; anchorKey?: string }
/** `origin`: the frame's own, so roles from a frame that left the recorded origin count for nothing. */
export type FillProbeReply = { roles: FormRole[]; origin: string }
/** Background → one frame; with `fill.execute` the only message carrying vault data. Reply `FillExecuteFormReply`. */
export type FillExecuteForm = { kind: 'fill.executeForm'; form: FormKind; values: FormValues; origin: string; anchorKey?: string }
export type FillExecuteFormReply = { filled: number }
/** Background → the top frame, for a context menu fill. Reply: whether the user answered OK to every question. */
export type FillConfirmForm = { kind: 'fill.confirmForm' } & FormQuestion

/** Background → the top frame. Reply: whether the user accepted. */
export type FillConfirmInsecure = { kind: 'fill.confirmInsecure'; itemHost: string }

/**
 * Background → one frame; the only message to a content script carrying vault data.
 * `origin`: the frame origin the background matched. `anchorKey`: an inline pick, filling only the form of the list with this key.
 * `code`: the login's current code, `null` on page load or without one.
 */
export type FillExecute = { kind: 'fill.execute'; username: string | null; password: string | null; code: string | null; origin: string; anchorKey?: string }
export type FillExecuteReply = { filled: FillOutcome; code: boolean }

/** What the list shows: logins, logins with a code on a code field, or the cards or identities of a card or identity field. */
export type InlineMode = 'login' | 'code' | FormKind
export const INLINE_MODES: readonly InlineMode[] = ['login', 'code', 'card', 'identity']
export const isFormKind = (mode: unknown): mode is FormKind => mode === 'card' || mode === 'identity'
/** The list frame's title key per mode, for the host's frame and the list page's document alike. */
export const INLINE_FRAME_TITLES = {
	login: 'inlineList.frameTitle',
	code: 'inlineList.code.frameTitle',
	card: 'inlineList.card.frameTitle',
	identity: 'inlineList.identity.frameTitle',
} as const satisfies Record<InlineMode, string>
/**
 * Request/response. Without a valid `mode` it reads as `login`. `webauthn`: a field that qualifies only by its
 * `autocomplete` `webauthn` token, which opens a list only while a conditional passkey request of the frame is pending.
 */
export type InlineOpen = { kind: 'inline.open'; mode: InlineMode; webauthn?: boolean }
/**
 * `null`: no list, for no logged-in account, `inlineSuggestions` or the kind's setting off, a page that is not http(s) or a `webauthn`
 * field without a pending conditional request. `height`: the list's, by `inlineHeight`, which the page can read from
 * the host anyway; passkey rows count as logins do.
 */
export type InlineOpenReply = { key: string; height: number } | null
/** Fire-and-forget. `key`: the closed list's, so it never drops a newer session. */
export type InlineClosed = { kind: 'inline.closed'; key: string }

/** Content script → its own list iframe, by `postMessage` to the extension origin. */
export type ContentToInlineList =
	| { kind: 'inline.init'; key: string }
	| { kind: 'inline.focus'; key: string }
	| { kind: 'inline.moved'; key: string }

/**
 * The list's size, shared by the background, the host and the list page.
 * It is as tall as its rows, up to `INLINE_MAX_ROWS`, `INLINE_GAP_PX` apart, with `INLINE_INSET_PX` of edge and padding above and below;
 * the locked and no-match messages are one row, as one match.
 * The questions and the not-visible notice, shown only after a pick, ask for what they need with `inline.resize`, up to `INLINE_MAX_HEIGHT_PX`.
 * At least `INLINE_MIN_WIDTH_PX` wide.
 */
export const INLINE_ROW_PX = 48
export const INLINE_GAP_PX = 2
export const INLINE_INSET_PX = 6
export const INLINE_MAX_ROWS = 5
export const INLINE_MIN_WIDTH_PX = 240
/** The list's corners, which the host clips the frame to. */
export const INLINE_RADIUS_PX = 10

export const inlineHeight = (rows: number) => {
	const shown = Math.min(Math.max(Math.floor(rows), 1), INLINE_MAX_ROWS)
	return 2 * INLINE_INSET_PX + shown * INLINE_ROW_PX + (shown - 1) * INLINE_GAP_PX
}

/** One row: with less room on both sides of the field, the list does not open. */
export const INLINE_MIN_HEIGHT_PX = inlineHeight(1)
export const INLINE_MAX_HEIGHT_PX = inlineHeight(INLINE_MAX_ROWS)

/** A height the list asked for, within the list's bounds; `null` for anything but a number. */
export const inlineClampHeight = (height: unknown): number | null =>
	typeof height === 'number' && Number.isFinite(height) ? Math.min(Math.max(Math.ceil(height), INLINE_MIN_HEIGHT_PX), INLINE_MAX_HEIGHT_PX) : null

/** `null` digits: not a valid seed. `validUntil`: epoch ms the code ends. */
export interface InlineCode {
	digits: string | null
	period: number
	validUntil: number
}

export interface InlineRow {
	id: string
	name: string
	username: string | null
	/** Code mode only. */
	code?: InlineCode
	/** Identity mode: a full name or an email; `null` for a use-only copy or an item without members. */
	detail?: string | null
	/** Card mode: what the list page words as "Visa, *1111"; absent for a use-only copy or a number under four digits. */
	card?: InlineCard
}

/** `brand`: null where the list says "Card". `last4`: four digits. */
export interface InlineCard {
	brand: string | null
	last4: string
}

/** `at`: `Date.now()` of the pointerdown or keydown. `x`, `y`: that point, or the focused row's centre, in list coordinates. */
export interface PickInput {
	kind: 'pointer' | 'key'
	at: number
	x: number
	y: number
}

/** List page → background. Tab and frame come from `sender`. */
export type InlineListToBackground =
	| { kind: 'inline.state'; key: string }
	/** With `credentialId`, a passkey row: `itemId` is its login's. */
	| { kind: 'inline.pick'; key: string; itemId: string; credentialId?: string; input: PickInput; confirmedInsecure?: boolean; confirmed?: FormConfirmation }
	/** These four are fire-and-forget. */
	| { kind: 'inline.refocus'; key: string }
	| { kind: 'inline.close'; key: string; refocus: boolean }
	| { kind: 'inline.unlock'; key: string }
	/** `height`: what the list's content needs, for the background to pass on to the host. */
	| { kind: 'inline.resize'; key: string; height: number }

/**
 * `null`: refused, for a wrong key, tab or frame. `host`: of the field's frame. `passkeys`: only while unlocked and a
 * conditional passkey request of the frame is pending, the passkeys it offers, shown above the logins.
 */
export type InlineStateReply = { state: 'locked' | 'unlocked'; mode: InlineMode; host: string; rows: InlineRow[]; passkeys?: PasskeyRow[]; theme: Theme } | null
/**
 * `filled`, `locked`, `stale` and `passkey` come with the background closing the list; after `needsInsecureConfirm` and
 * `not_visible` it stays open, for the question or the explanation. `passkey`: `signed`, or `window` when the passkey
 * window opened on verification or a failed counter save. `null` for a wrong key, tab or frame.
 */
export type InlinePickReply =
	| { filled: FillResult['filled']; code: boolean } | { needsInsecureConfirm: true } | { needsFormConfirm: FormQuestion }
	| { refused: 'locked' | 'not_visible' | 'stale' } | { passkey: 'signed' | 'window' } | null

export const INLINE_LIST_KINDS = kindSet<InlineListToBackground['kind']>({
	'inline.state': true, 'inline.pick': true, 'inline.refocus': true, 'inline.close': true, 'inline.unlock': true, 'inline.resize': true,
})

/** Fire-and-forget. URL comes from `sender.url`. `changed`: from a password change group. */
export type CaptureSubmitted = { kind: 'capture.submitted'; username: string | null; password: string; changed: boolean }

/** Content script → its own bar iframe, by `postMessage` to the extension origin, like `ContentToInlineList`. */
export type ContentToNotification =
	| { kind: 'capture.init'; key: string }
	/** The `focus_notification_bar` command: focus the first control. */
	| { kind: 'capture.focus'; key: string }
	/** The host moved between top and bottom or changed size: the bar restarts its 500 ms guard. */
	| { kind: 'capture.moved'; key: string }

/**
 * Notification page → background. Request/response; the tab comes from `sender.tab`. `key`: from `capture.init`; the
 * first frame presenting it is bound, and anything else gets `null`. `input`: the press, as for `inline.pick`.
 */
export type NotificationToBackground =
	| { kind: 'capture.state'; key: string }
	| { kind: 'capture.save'; key: string; folderId: string | null; input: PickInput }
	| { kind: 'capture.update'; key: string; input: PickInput }
	/** `input` is required with `never: true`; Close needs none. */
	| { kind: 'capture.dismiss'; key: string; never: boolean; input?: PickInput }
	/** The user focused or pressed the compact bar: the host grows it to its full height until focus leaves. */
	| { kind: 'capture.expand'; key: string }

/** The notice mode of the same page → background. Its Close, relayed to the top frame as `notice.hide`; no key or guard, as closing is all it can do. */
export type NoticeToBackground = { kind: 'notice.close' }

/**
 * `host`: of the submitting frame. `tabHost`: the top frame's at the submit, only where it differs ("on {tabHost}").
 * `itemName` and `username` (when the item has one): update mode only. `folders`: depth-first, each named by its path ("Work / Clients").
 * `shortcut`: the `focus_notification_bar` key as the browser names it ("Alt+Shift+K"), absent when the user unassigned it.
 */
export type CaptureStateReply = {
	mode: 'add' | 'update'
	host: string
	tabHost?: string
	itemName?: string
	username?: string
	folders: Array<{ id: string; name: string }>
	theme: Theme
	shortcut?: string
}

/** The host could not confirm the bar was visible at the press; nothing was done and the capture stays. */
export type CaptureRefused = { refused: 'not_visible' }

/** `null`: no pending capture, or a key or frame the background did not bind. */
export interface NotificationReplies {
	'capture.state': CaptureStateReply | null
	'capture.save': WriteResult<null> | CaptureRefused | null
	'capture.update': WriteResult<null> | CaptureRefused | null
	'capture.dismiss': CaptureRefused | null
	'capture.expand': null
}

/**
 * The bar is a card `BAR_INSET_PX` in from the right edge and the top or bottom of the top frame's viewport. Its size
 * follows that viewport alone, never its mode or text, which would tell the page whether the username is saved. It
 * fits a question of two lines at its full width, three once the viewport narrows it, above the folder or item row and
 * the buttons; longer text scrolls.
 */
export const BAR_INSET_PX = 12
export const BAR_WIDTH_PX = 400
export const BAR_HEIGHT_PX = 148
export const BAR_NARROW_HEIGHT_PX = 168
/** The card's corners, which the host clips the frame to. */
export const BAR_RADIUS_PX = 12

export const barWidth = (viewportWidth: number) => Math.max(0, Math.min(BAR_WIDTH_PX, viewportWidth - 2 * BAR_INSET_PX))

export const barHeight = (viewportWidth: number) => barWidth(viewportWidth) < BAR_WIDTH_PX ? BAR_NARROW_HEIGHT_PX : BAR_HEIGHT_PX

/** One row, for a viewport too short for `barHeight` (high zoom): the bar takes at most this share of it until the user expands it. */
export const BAR_COMPACT_HEIGHT_PX = 40
export const BAR_MAX_VIEWPORT_SHARE = 1 / 2

/**
 * The host's height: compact while `barHeight` would cover more than half of the viewport, unless the user expanded
 * it; expanded, no taller than the viewport leaves between the insets, with the question scrolling.
 */
export const barHostHeight = (viewportWidth: number, viewportHeight: number, expanded: boolean) => {
	const full = barHeight(viewportWidth)
	if (full <= viewportHeight * BAR_MAX_VIEWPORT_SHARE) return full
	return expanded ? Math.max(BAR_COMPACT_HEIGHT_PX, Math.min(full, viewportHeight - 2 * BAR_INSET_PX)) : BAR_COMPACT_HEIGHT_PX
}

export const NOTIFICATION_KINDS = kindSet<NotificationToBackground['kind'] | NoticeToBackground['kind']>({
	'capture.state': true, 'capture.save': true, 'capture.update': true, 'capture.dismiss': true, 'capture.expand': true, 'notice.close': true,
})

/** Background → content script, through `tabs.sendMessage` with a `frameId`. */
export type BackgroundToContent =
	/** Request/response, reply `boolean`. */
	| FillConfirmInsecure
	/** Request/response, reply `FillExecuteReply`. */
	| FillExecute
	| FillProbe
	| FillExecuteForm
	| FillConfirmForm
	/**
	 * To the frame hosting the list; `inline.verify` replies `boolean`, the others are fire-and-forget.
	 * `key`: the session's, so a message meant for a list that has since closed cannot reach the next one.
	 */
	| { kind: 'inline.ready'; key: string }
	| { kind: 'inline.verify'; key: string; input: PickInput }
	| { kind: 'inline.refocus'; key: string }
	| { kind: 'inline.close'; key: string; refocus: boolean }
	/** `height`: the list's request, clamped by `inlineClampHeight`. */
	| { kind: 'inline.resize'; key: string; height: number }
	/**
	 * To the top frame only (`frameId: 0`). `capture.verify` replies `boolean`, `capture.show` the key its bar holds,
	 * the others are fire-and-forget. `key`: the bar's, posted to its frame as `capture.init`. `capture`: the capture's
	 * id, so a show for another capture replaces the bar and one for the same capture keeps it, with its own key, which
	 * the background hides and shows anew unless it issued that key for this capture. `done`: after a save or update,
	 * so the host waits for focus and pointer to leave the bar.
	 */
	| { kind: 'capture.show'; key: string; capture: string }
	| { kind: 'capture.hide'; key: string; done?: true }
	| { kind: 'capture.verify'; key: string; input: PickInput }
	/** The `focus_notification_bar` command. */
	| { kind: 'capture.focus' }
	/** From the bar's `capture.expand`, relayed: the host grows to the full height until focus leaves it. */
	| { kind: 'capture.expand'; key: string }
	/** Fire-and-forget, to the frame that started the ceremony. `origin`: the ceremony's, which the relay checks. */
	| { kind: 'passkey.result'; ceremonyId: string; origin: string; result: PasskeyResult }
	/** Fire-and-forget, to the top frame only; a code, never vault data, so the page learns nothing it could not see. */
	| { kind: 'notice.show'; notice: PageNoticeCode }
	| { kind: 'notice.hide' }

export type AccountStatus = 'unlocked' | 'locked' | 'logged_out'

/** Here rather than in the account store, so the popup can name it without importing storage code. */
export const MAX_ACCOUNTS = 5

export interface AccountSummary {
	id: string
	serverUrl: string
	host: string
	uid: string
	displayName: string
	avatarDataUrl: string | null
	status: AccountStatus
	active: boolean
	/** The PIN's session copy exists, so the PIN field can work. */
	pin: boolean
	/** Only the seal exists, after a restart: the master password once, then the PIN. */
	pinAfterRestart: boolean
	/** A passkey was enrolled for this extension and the server still offered it at the last look. */
	passkeyUnlock: boolean
}

export type PopupScreen = 'add_account' | 'reauthenticate' | 'unlock' | 'unlocked'

export interface PopupState {
	screen: PopupScreen
	accounts: AccountSummary[]
	active: AccountSummary | null
	/** One-shot banner. */
	notice: NoticeCode | null
	canAddAccount: boolean
	/** The tab to open on, so the first frame already shows it. */
	lastTab: PopupTab
}

export type ErrorCode =
	| 'insecure_url' | 'invalid_url' | 'permission_denied' | 'unreachable' | 'not_nextcloud'
	| 'unauthorized' | 'keepiq_missing' | 'no_active_suite' | 'unlock_blocked' | 'duplicate'
	| 'limit_reached' | 'invalid_master_password' | 'offline_no_cache' | 'session_revoked'
	| 'write_locked' | 'server_error' | 'not_responding' | 'unknown'
	/** Keepiq is older than `MIN_KEEPIQ_VERSION`. */
	| 'keepiq_outdated'
	/** The master password opened the vault key, but it isn't one PEM private key. */
	| 'key_unreadable'
	/** Carries `attemptsLeft`. */
	| 'invalid_pin'
	/** The fifth wrong PIN in a row forgot it, or another window's did. */
	| 'pin_forgotten'
	/** The PIN wraps a key from another suite or epoch than the cached one, so it was forgotten. */
	| 'pin_key_changed'
	/** A passkey ceremony was refused or its key did not open the vault; the master password still does. */
	| 'passkey_failed'

// Codes, never text: the popup words them in the browser's language (ext-i18n).
/** `file_access`: shown until the first unlock of an account added through the browser sign-in. */
export type NoticeCode = 'session_revoked' | 'key_changed' | 'file_access'

export type BlockReason = 'suite_missing' | 'suite_revoked' | 'suite_compromised' | 'migration_failed'

export type Result = { ok: true; state: PopupState } | { ok: false; code: ErrorCode; attemptsLeft?: number }

export type UnlockMethod =
	| { type: 'masterPassword'; masterPassword: string }
	| { type: 'pin'; pin: string }
	/** base64 of the raw unlock key a passkey released, unwrapped in the extension window. */
	| { type: 'unlockKey'; unlockKey: string }

/** In code points, any characters. Here so the popup can name it without importing the PIN code. */
export const PIN_MIN_LENGTH = 6

/** Popup → background. Request/response, every arm resolves to `Result`. */
export type PopupToBackground =
	| { kind: 'accounts.list' }
	| { kind: 'accounts.add'; serverUrl: string; username: string; appPassword: string }
	/** Starts Nextcloud Login Flow v2; the background adds the account once the user signed in. */
	| { kind: 'accounts.loginFlow'; serverUrl: string }
	| { kind: 'accounts.reauthenticate'; accountId: string; appPassword: string }
	| { kind: 'accounts.remove'; accountId: string }
	| { kind: 'accounts.removeAll' }
	| { kind: 'accounts.switch'; accountId: string }
	| { kind: 'vault.unlock'; accountId: string; method: UnlockMethod }
	| { kind: 'vault.lock'; accountId: string }
	| { kind: 'vault.lockAll' }
	/** `passive`: the popup reacting to the background, which must not count as interaction. */
	| { kind: 'vault.status'; passive?: boolean }

export const POPUP_MESSAGE_KINDS = kindSet<PopupToBackground['kind']>({
	'accounts.list': true, 'accounts.add': true, 'accounts.loginFlow': true, 'accounts.reauthenticate': true, 'accounts.remove': true, 'accounts.removeAll': true,
	'accounts.switch': true, 'vault.unlock': true, 'vault.lock': true, 'vault.lockAll': true, 'vault.status': true,
})

export type PopupTab = 'vault' | 'generator' | 'send' | 'settings'

/** Autofill behaviours ext-autofill and ext-card-identity-fill deliver; an entry for one not yet delivered shows "Coming later". */
export type AutofillFeature = 'pageLoad' | 'uriMatch' | 'inlineSuggestions' | 'contextMenu' | 'savePrompts' | 'passkeys' | 'cards' | 'identities'

/** Here rather than in the settings module, so the popup can list them without its storage code. */
export const URI_MATCHES = ['baseDomain', 'host', 'startsWith', 'exact', 'regex', 'never'] as const
export type UriMatch = typeof URI_MATCHES[number]
/** Milliseconds; `0` is Never. */
export const CLEAR_CLIPBOARD_OPTIONS = [0, 10_000, 20_000, 30_000, 60_000, 120_000, 300_000] as const

export const THEMES = ['system', 'light', 'dark'] as const
export type Theme = typeof THEMES[number]

/** Settings that apply before an account is chosen, so they are not per account. */
export interface GlobalSettings {
	theme: Theme
	compactMode: boolean
	showAnimations: boolean
	/** Copy actions on the vault list's cards; the item detail keeps its own. */
	quickCopy: boolean
	/** The login count on the toolbar icon's badge. */
	showBadgeCounter: boolean
	/** Not now on the Vault tab's Safari website access card. */
	websiteAccessDismissed: boolean
}

export interface SyncStatus {
	syncing: boolean
	/** Last time the server confirmed the snapshot, `null` before the first sync. */
	syncedAt: string | null
	offline: boolean
	lastError: 'network' | 'server' | 'busy' | null
}

/** The plaintext projection of a secret row; ciphertext never reaches the popup. */
export interface ItemMeta {
	id: string
	name: string
	url: string | null
	typeId: string | null
	folderId: string | null
	hasLogin: boolean
	blocked: boolean
	/** Absent when the stored reason is not a known code. */
	blockedReason?: BlockReason
	migrationError?: string | null
	createdAt: string
	updatedAt: string
	expiresAt: string | null
	/** A shared copy that is only filled: of its values, only the login decrypts for the popup. */
	useOnly: boolean
	/** A copy from another organisation, which may only be moved. */
	readOnly: boolean
	/** When access to this shared copy ends. */
	accessExpiresAt: string | null
}

export interface FolderMeta {
	id: string
	name: string
	parentId: string | null
}

export interface TypeMeta {
	id: string
	name: string
	label: string
}

export type VaultSnapshotReply =
	| { state: 'logged_out' }
	| { state: 'locked' }
	| {
		state: 'unlocked'
		/** `null` until the account's first sync stores a snapshot. */
		items: ItemMeta[] | null
		folders: FolderMeta[]
		types: TypeMeta[]
		/** The tab's matching logins, then the cards and the identities its Vault view settings show, each last used first. */
		suggestionIds: string[]
		/** Every unblocked card and identity on an `http(s)` tab, whatever those settings: the detail view offers each Fill. */
		formIds: string[]
		sync: SyncStatus
		/** The account's Keepiq web app, for "open the web app" links. */
		webAppUrl: string
		/** The extension's Default item type when the cached types hold it; `null` means `login`. */
		defaultSecretType: string | null
		folderNoticeDismissed: boolean
	}

/** The value fields the edit form shows and saves. */
export type FormField = 'login' | 'key' | 'additionalFields'
/** `totp`: a login's seed (keepiq#1325), which the popup only turns into a code. */
export type DecryptField = FormField | 'totp'
export type DecryptedItem = Partial<Record<DecryptField, string>>

/** All a use-only copy's holder gets, seed included; the background refuses the rest (use-only-shares). */
export const USE_ONLY_FIELDS: readonly DecryptField[] = ['login', 'totp']

/** A batch leaves out rows that are blocked, use-only or do not decrypt; a single id gets the reason instead. */
export type DecryptReply =
	| { ok: true; items: Record<string, DecryptedItem> }
	| { ok: false; error: 'locked' | 'blocked' | 'use_only' | 'decrypt_failed' }

/** All the detail gets of a login's passkey: no key, credential id or user handle. `createdAt` is null when absent or not a date. */
export interface PasskeyDetail {
	rpId: string
	rpName: string
	/** `userName`, else `userDisplayName`. */
	userName: string
	createdAt: string | null
}

/** In stored order; `null` for an entry that does not parse. `unreadable`: the field does not decrypt or is not an array. */
export type PasskeysReply =
	| { ok: true; passkeys: Array<PasskeyDetail | null> }
	| { ok: false; error: 'locked' | 'not_found' | 'blocked' | 'unreadable' }

/**
 * A field the web app stores as `{ value, kind }` rather than as text: a hidden or a yes-or-no field (Keepiq
 * `vault-custom-fields`). `boolean` marks a value stored as a JSON boolean rather than as `'true'` or `'false'`.
 */
export interface FieldShape {
	kind: string
	boolean?: true
}

/** Plaintext draft. The background encrypts key, login and additionalFields; the popup never does. */
export interface ItemDraft {
	typeId: string
	name: string
	url: string | null
	folderId: string | null
	key: string
	login: string | null
	/** Name to value, `notes` included; `{}` when empty. */
	additionalFields: Record<string, string>
	/** The fields of `additionalFields` that are stored with a kind, by name; absent when none is. Saved back with that kind. */
	fieldKinds?: Record<string, FieldShape>
	/** A login's authenticator key; absent or `''` for none. */
	totp?: string
}

/** The fields the user changed. `null` clears; `additionalFields` is always whole; `totp` `''` removes the saved key. */
export type ItemPatch = Partial<ItemDraft>

/** A fresh fetch, decrypted for the edit form. */
export interface EditableItem extends ItemDraft {
	id: string
	updatedAt: string
	/**
	 * The type name the fetch vouched for the fields under; the edit form renders by it, not the popup's snapshot. Absent for a type the vault does not know.
	 * `passkey` when either the server or the cache says so, and `key` is then empty: a passkey's credential is never decrypted for editing.
	 */
	typeName?: string
	/** An edit fetch's record of what its form showed, for that form's save to name; absent for a clone. */
	recordId?: string
	/** The row holds an authenticator key; the key itself never reaches the popup. Always `false` for a clone. */
	totpSaved: boolean
	/** A clone of a row that holds a key, which is not cloned. */
	totpLeftOut?: true
}

export interface FolderChildren {
	directSecretCount: number
	subfolders: Array<{ id: string; name: string; secretCount: number; subfolderCount: number }>
}

export type SubfolderAction = 'delete' | 'move' | 'keep'

export type FolderDeletePlan =
	| { mode: 'plain' }
	| { mode: 'cascade'; cascade: 'delete' | 'move' }
	| { mode: 'resolution'; directSecrets: 'delete' | 'move'; subfolders: Record<string, SubfolderAction> }

/** `conflict` is a duplicate folder name, a folder that is not empty, an item changed since `baseUpdatedAt`, or a fetched item whose ciphertext the cache does not hold. */
export type WriteErrorCode =
	| 'locked' | 'session_revoked' | 'write_locked' | 'forbidden' | 'policy' | 'invalid' | 'conflict'
	| 'not_found' | 'blocked' | 'unreadable' | 'offline' | 'server_error' | 'unknown'
	/** A use-only, read-only or (for a clone) expiring shared copy. */
	| 'restricted'
	/** A shared copy whose grade lets its holder view it, not edit it. */
	| 'view_only'
	/** The suite's certificate does not match the unlocked key, so nothing is encrypted to it. */
	| 'key_mismatch'
	/** The write was meant for an account that is no longer the active one. */
	| 'account_changed'
	/** A shared item's source was written after its copy's edit form opened. */
	| 'stale_source'
	/** The share list kept changing over three share-save answers. */
	| 'incomplete'
	/** A recipient's certificate did not import. */
	| 'bad_certificate'
	/** Partner shares get the whole value, and the edit form did not show every field of it in full. */
	| 'unverified'
	/** `partners` names the partner shares whose instance did not answer. */
	| 'partner_unreachable'
	/** The partner lookup's limit of 60 per minute. */
	| 'partner_lookup_limit'
	/** The server runs a Nextcloud without the OCM endpoint event partner sharing needs (below 33). */
	| 'federation_unavailable'
	/** The route a save needs is missing, as on a Keepiq older than `MIN_KEEPIQ_VERSION`. */
	| 'keepiq_outdated'
	/** A partner certificate is not valid by this machine's clock, which may be wrong, so nothing is suspended on it. */
	| 'partner_clock'
	| 'passkey_clone'
	/** A send password needs Argon2id, whose WebAssembly this browser does not run. */
	| 'argon2_unavailable'
	/** `partners` names the partner shares whose Keepiq cannot store an authenticator key or passkeys the change adds. */
	| 'partner_upgrade_required'
	/** A share row of the item waits for the server's share quarantine scan, which only background jobs finish. */
	| 'share_scan_pending'

export interface VaultWriteError {
	code: WriteErrorCode
	/** With `blocked`, when the row's reason is a known code. */
	reason?: BlockReason
	/** With `partner_unreachable` and `partner_upgrade_required`. */
	partners?: Array<{ shareId: string; cloudId: string }>
	/** With `partner_upgrade_required`: the release the partner needs, as the server or the background named it. */
	requiredVersion?: string
}

export type WriteResult<T> = { ok: true; value: T } | { ok: false; error: VaultWriteError }

/** Shown on the item's detail after a save; absent when the save reached everyone. */
export interface ShareSaveNote {
	/** Copy-editor path: partner shares exist that only the owner's next save reaches. */
	ownerOnly?: true
	/** Partner shares this save suspended on a verdict about the recipient, by cloud id. */
	suspended?: string[]
	/** Partner shares the user suspended through Save without them, by cloud id. */
	paused?: string[]
}

/** Popup → background requests with their own reply shapes, listed in `PopupReplies`. */
export type PopupRequest =
	| { kind: 'vault.sync' }
	/** `opened` marks the popup's first read: the one that may start a stale sync and counts as interaction. */
	| { kind: 'vault.snapshot'; tabUrl?: string; opened?: boolean }
	| { kind: 'item.decrypt'; ids: string[]; fields: DecryptField[]; passive?: boolean }
	| { kind: 'item.passkeys'; id: string; passive?: boolean }
	/** `changeCount`: Safari's pasteboard count after a write through the containing app. */
	| { kind: 'clipboard.copied'; changeCount?: number }
	| { kind: 'popup.popout'; tabId?: number }
	| { kind: 'popup.lastTab.set'; tab: PopupTab }
	/** Once on mount: the context menu's Add, which the background then forgets. */
	| { kind: 'popup.intent' }
	| { kind: 'settings.get' }
	| { kind: 'settings.set'; patch: Partial<GlobalSettings> }
	| DefaultManagerRequest
	| FillRequest
	| AccountSettingsRequest
	| VaultWrite
	| GeneratorRequest
	| SendRequest

/**
 * Chrome's own password saving and address autofill (ext-default-password-manager), not per account. `enable` is
 * sent once the popup holds the `privacy` permission; `disable` clears both and gives the permission back.
 */
export type DefaultManagerRequest =
	| { kind: 'defaultManager.get' }
	| { kind: 'defaultManager.enable' }
	| { kind: 'defaultManager.disable' }

/**
 * `on`: both settings are off under this extension's control. `off`: not, or the permission is not held, as the
 * settings cannot be read without it. `managed`: policy or another extension controls either. `managedOn`: as
 * `managed`, while this extension still controls the other, which `disable` gives back. `unsupported`: the browser
 * has no address autofill setting.
 */
export type DefaultManagerState = 'on' | 'off' | 'managed' | 'managedOn' | 'unsupported'

/**
 * Each names the account the popup showed, so a write never lands in another account's vault
 * after a switch in another window.
 */
export type VaultWrite = { accountId: string } & (
	/** `purpose`: a clone is refused for more restricted copies than an edit. */
	| { kind: 'item.fetch'; id: string; purpose: 'edit' | 'clone' }
	| { kind: 'item.create'; draft: ItemDraft }
	/**
	 * `baseUpdatedAt`: the fetched version, so a save over a newer one is refused. `recordId`: the fetch's, without which a save that needs it for partners fails with `unverified`.
	 * `withoutUnreachable`: partner share ids the user chose to suspend after a `partner_unreachable` failure.
	 */
	| { kind: 'item.update'; id: string; patch: ItemPatch; baseUpdatedAt?: string; recordId?: string; withoutUnreachable?: string[] }
	| { kind: 'item.delete'; id: string }
	/** Whether the server's grade lets the user edit the item; a view-only copy is `false`. */
	| { kind: 'item.editable'; id: string }
	| { kind: 'folder.create'; name: string; parentId: string | null }
	| { kind: 'folder.rename'; id: string; name: string }
	| { kind: 'folder.children'; id: string }
	| { kind: 'folder.delete'; id: string; plan: FolderDeletePlan }
	| { kind: 'folder.dismissNotice' }
)

/** Named by the account the popup shows, like `VaultWrite`; `settings.get`/`set` stay global for the theme, read before any account exists. */
export type AccountSettingsRequest = { accountId: string } & (
	| { kind: 'account.settings.get' }
	| { kind: 'account.settings.set'; patch: Partial<AccountSettings> }
	| { kind: 'pin.set'; pin: string; masterPassword: string }
	| { kind: 'pin.remove' }
)

export interface AccountSettingsSnapshot {
	accountId: string
	/** As stored, validated. */
	values: AccountSettings
	/** After the organisation maximum. */
	effectiveTimeout: VaultTimeout
	/** `null` until the first read. */
	maxIdleMinutes: number | null
	exportDisabled: boolean
	/** Either copy exists, so the toggle shows on. */
	pin: boolean
	/** Empty until ext-autofill. */
	autofillFeatures: AutofillFeature[]
	/** Of the `autofill_login` command; `null` when it is missing or has none. */
	shortcut: string | null
	serverUrl: string
	webAppUrl: string
	/** Raw, for display only. */
	keepiqVersion: string | null
}

export type PinSetReply = { ok: true } | { ok: false; code: 'invalid_master_password' | 'pin_too_short' | 'locked' }

/** Per account, like `VaultWrite`, so a stale window never reads or writes another account's generator. */
export type GeneratorRequest = { accountId: string } & (
	| { kind: 'generator.context' }
	| { kind: 'generator.saveOptions'; options: GeneratorOptions }
	| { kind: 'generator.history.list' }
	| { kind: 'generator.history.add'; entry: Omit<GeneratorHistoryEntry, 'createdAt'> }
	| { kind: 'generator.history.clear' }
)

/** Options as stored, the policy as cached; the popup applies the policy when it generates. */
export interface GeneratorContext {
	options: GeneratorOptions
	policy: GeneratorPolicy | null
	accountEmail: string | null
}

/** Per account, like `VaultWrite`, so a send never goes out from another account's server. */
export type SendRequest = { accountId: string } & (
	| { kind: 'send.list' }
	| { kind: 'send.create'; input: SendCreateInput }
	| { kind: 'send.delete'; id: string }
)

export type SendPayloadType = 'text' | 'credential'

export interface SendCreateInput {
	payloadType: SendPayloadType
	/** For a credential, the body from `serializeCredential`, so only one plaintext string crosses to the background. */
	content: string
	maxViews: number
	/** 3600 up to `TTL_CAP_SECONDS`; the extension offers no send without expiry. */
	ttlSeconds: number
	password?: string
}

/** A send as the server lists it; it never holds the content or its key. */
export interface SendRow {
	id: string
	token: string
	payloadType: SendPayloadType
	hasPassword: boolean
	maxViews: number
	viewCount: number
	remainingViews: number
	/** `null` never expires. An expired send is listed until the server's hourly purge. */
	expiresAt: string | null
	createdAt: string
	/** Password sends only: their link carries no secret, so it is rebuilt from `token`. */
	link?: string
}

export interface PopupReplies {
	'vault.sync': SyncStatus
	'vault.snapshot': VaultSnapshotReply
	'item.decrypt': DecryptReply
	'item.passkeys': PasskeysReply
	'clipboard.copied': null
	'popup.popout': null
	'popup.lastTab.set': null
	/** `null` when none is pending, it is older than 10 seconds, or the active account is not unlocked. */
	'popup.intent': AddIntent | null
	'settings.get': GlobalSettings
	'settings.set': GlobalSettings
	'defaultManager.get': DefaultManagerState
	'defaultManager.enable': DefaultManagerState
	'defaultManager.disable': DefaultManagerState
	'fill.request': FillResult
	/** `null` for an unknown account. */
	'account.settings.get': AccountSettingsSnapshot | null
	/** `'locked'` while the account is not unlocked. */
	'account.settings.set': AccountSettingsSnapshot | 'locked' | null
	'pin.set': PinSetReply
	'pin.remove': null
	'item.fetch': WriteResult<EditableItem>
	'item.create': WriteResult<{ id: string }>
	'item.update': WriteResult<{ id: string; shareSave?: ShareSaveNote }>
	'item.delete': WriteResult<null>
	'item.editable': WriteResult<{ editable: boolean }>
	'folder.create': WriteResult<{ id: string }>
	'folder.rename': WriteResult<null>
	'folder.children': WriteResult<FolderChildren>
	'folder.delete': WriteResult<null>
	'folder.dismissNotice': null
	/** `null` when `accountId` is not the active account. */
	'generator.context': GeneratorContext | null
	/** The options as stored after sanitising; `null` when refused. */
	'generator.saveOptions': GeneratorOptions | null
	/** Newest first. Both answer `[]` while locked, though `add` still records the value, sealed. */
	'generator.history.list': GeneratorHistoryEntry[]
	'generator.history.add': GeneratorHistoryEntry[]
	'generator.history.clear': null
	/** Newest first. `passwordProtection`: whether this browser can make a password send. */
	'send.list': WriteResult<{ sends: SendRow[]; passwordProtection: boolean }>
	/** `link` is shown once: the background keeps neither it nor the key. */
	'send.create': WriteResult<{ row: SendRow; link: string }>
	/** Also `ok` on a 404: the send was already burned or expired. */
	'send.delete': WriteResult<null>
}

export const POPUP_REQUEST_KINDS: ReadonlySet<string> = new Set<keyof PopupReplies>([
	'vault.sync', 'vault.snapshot', 'item.decrypt', 'item.passkeys', 'clipboard.copied', 'popup.popout', 'popup.lastTab.set', 'popup.intent',
	'settings.get', 'settings.set', 'defaultManager.get', 'defaultManager.enable', 'defaultManager.disable', 'fill.request', 'account.settings.get', 'account.settings.set', 'pin.set', 'pin.remove', 'item.fetch', 'item.create', 'item.update', 'item.delete', 'item.editable',
	'folder.create', 'folder.rename', 'folder.children', 'folder.delete', 'folder.dismissNotice',
	'generator.context', 'generator.saveOptions', 'generator.history.list', 'generator.history.add', 'generator.history.clear',
	'send.list', 'send.create', 'send.delete',
])

/** Extension window → background: the server calls of the vault's own passkey unlock (extension-biometric-unlock). */
export type VaultPasskeyRequest = { accountId: string } & (
	| { kind: 'vaultPasskey.enrolOptions' }
	| { kind: 'vaultPasskey.enrol'; body: EnrolmentBody }
	| { kind: 'vaultPasskey.unlockOptions' }
	| { kind: 'vaultPasskey.release'; assertion: CredentialJson }
)

export interface VaultPasskeyReplies {
	'vaultPasskey.enrolOptions': EnrolOptions
	'vaultPasskey.enrol': null
	'vaultPasskey.unlockOptions': UnlockOptions
	'vaultPasskey.release': ReleasedKey
}

export type VaultPasskeyReply<K extends VaultPasskeyRequest['kind']> = { ok: true; value: VaultPasskeyReplies[K] } | { ok: false; code: ErrorCode }

export const VAULT_PASSKEY_KINDS = kindSet<VaultPasskeyRequest['kind']>({
	'vaultPasskey.enrolOptions': true, 'vaultPasskey.enrol': true, 'vaultPasskey.unlockOptions': true, 'vaultPasskey.release': true,
})

/** Background → popup broadcasts; nobody listens while the popup is closed. */
export type BackgroundToPopup =
	| { kind: 'vault.changed'; sync: SyncStatus }
	| { kind: 'vault.locked' }

/** Background → the Chrome offscreen document. Reply: whether it wrote. */
export type BackgroundToOffscreen =
	| { kind: 'offscreen.clearClipboard' }
	| { kind: 'offscreen.writeClipboard'; text: string }

/** Background or popup → Safari's containing app (`safari/SafariWebExtensionHandler.swift`). */
export type ToNativeApp =
	| { kind: 'clipboard.write'; text: string }
	/** Without `changeCount` the app clears unconditionally. */
	| { kind: 'clipboard.clear'; changeCount?: number }

/** Name of the port the popup holds open; its disconnect means the popup closed. */
export const POPUP_PORT = 'popup'
