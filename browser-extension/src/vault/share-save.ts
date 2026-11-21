/** A value change of a possibly shared item in one `PUT /api/v1/secrets/{id}/share-save`, ported from the web app's `saveShared` (`src/services/shareSave.js`); the server's rules are in `ShareSaveService`. */
import { ApiError, KeepiqNotInstalled, type Client } from '@/src/api/client'
import { TOTP_PASSKEYS_FIELDS_SINCE } from '@/src/api/keepiq-version'
import { FederatedCertificateError, importPublicKey, importSpki, rsaDecrypt, rsaEncrypt, verifyFederatedCertificate } from '@/src/crypto'
import type { DecryptField, ShareSaveNote } from '@/src/messages'
import { verifiedPartners, type VerifiedPartner } from './verified-partners'
import { WriteFailure } from './write-error'

export type ValueField = DecryptField | 'passkeys'

/** What a save may change, a partner share gets whole and an edit fetch must find in the cache; only a passkey ceremony writes `passkeys`. */
export const VALUE_FIELDS: readonly ValueField[] = ['key', 'login', 'additionalFields', 'totp', 'passkeys']
/** Fields a partner may not store (ADR-003 § Partner certificate). */
const PARTNER_OPTIONAL = ['totp', 'passkeys'] as const

/** `''` removes a key on the wire; encrypted, it would be a key that does not read. */
export const sealValue = (field: ValueField, value: string | null, key: CryptoKey): Promise<string | null> =>
	value === null || (value === '' && (PARTNER_OPTIONAL as readonly ValueField[]).includes(field)) ? Promise.resolve(value) : rsaEncrypt(value, key)

/** `ShareSaveRequest::MAX_ENTRIES` per list pair, and `ShareController::MAX_RECIPIENT_PROBE`. */
const BATCH = 100
const MAX_ROUNDS = 3
const COPY_EDITOR_GRADES = new Set(['owner', 'write', 'manage'])
/**
 * Lookup refusals that are a verdict on the recipient, with the status each comes with; the plain controller sends them only as `message`.
 * `bad_chain` is the server's own check that the partner served an intermediate and a root.
 */
const PARTNER_GONE = new Map([['unknown_recipient', 404], ['not_a_partner', 403], ['bad_chain', 502]])

const secretPath = (id: string) => `/api/v1/secrets/${encodeURIComponent(id)}`

/** Plaintext or ciphertext per value field, `null` for an empty one; `''` for a removed `totp`. */
export type Values = Partial<Record<ValueField, string | null>>

/** What an edit form opened: the ciphertext of each field it showed in full, `totp` and `passkeys` always (`recordOpened`), the row version, and the source version `write-context` named. */
export interface Opened {
	shown: Values
	updatedAt: string
	sourceUpdatedAt: string | null
}

interface SourceContext {
	ownRow: false
	sourceSecretId: string
	effectiveGrade: string
	ownerCertificate: string | null
	sourceUpdatedAt: string | null
}

/** `ShareWriteContext::ownRowContext`: the caller's row leads to no source they stand on, so it is saved alone and share-save judges it again. */
interface OwnRowContext {
	ownRow: true
	ownerCertificate: null
	sourceUpdatedAt: null
}

export type WriteContext = SourceContext | OwnRowContext

export async function readWriteContext(client: Client, id: string): Promise<WriteContext> {
	let context: Partial<Record<keyof SourceContext, unknown>> | null
	try {
		context = await client.keepiq('GET', `${secretPath(id)}/write-context`)
	} catch (error) {
		// Nextcloud's page, not Keepiq's verdict on the row, as in `goneOrRefused`.
		if (error instanceof KeepiqNotInstalled) throw new WriteFailure({ code: 'server_error' })
		throw error instanceof ApiError && error.status === 404 ? await goneOrRefused(client, id) : error
	}
	if (context?.sourceSecretId === null) return { ownRow: true, ownerCertificate: null, sourceUpdatedAt: null }
	if (!isString(context?.sourceSecretId)) throw new WriteFailure({ code: 'unknown' })
	const text = (value: unknown) => (typeof value === 'string' && value ? value : null)
	return {
		ownRow: false,
		sourceSecretId: context.sourceSecretId,
		effectiveGrade: text(context.effectiveGrade) ?? 'none',
		ownerCertificate: text(context.ownerCertificate),
		sourceUpdatedAt: text(context.sourceUpdatedAt),
	}
}

/** A copy without a write grade, which the server would let its holder change alone, out of step with everyone else's (share-sync). */
export const isViewOnly = (id: string, context: WriteContext) => !context.ownRow && context.sourceSecretId !== id && !COPY_EDITOR_GRADES.has(context.effectiveGrade)

/**
 * Write-context and an own row's share-save also answer 404 for a row two share rows name, a share row that is someone else's, or a caller with no grade,
 * so only Keepiq's 404 on the row itself proves it gone; a row still there was refused.
 */
async function goneOrRefused(client: Client, id: string): Promise<unknown> {
	let row: { updatedAt?: unknown } | null
	try {
		row = await client.keepiq('GET', secretPath(id))
	} catch (error) {
		// Nextcloud's page, not Keepiq's verdict on the row.
		if (error instanceof KeepiqNotInstalled) return new WriteFailure({ code: 'server_error' })
		return error
	}
	return new WriteFailure({ code: typeof row?.updatedAt === 'string' ? 'forbidden' : 'server_error' })
}

/** An own row's share-save 404 is checked against the row, as a write-context 404 is. */
async function saveRefusal(client: Client, id: string, context: WriteContext, refusal: unknown): Promise<unknown> {
	return context.ownRow && refusal instanceof ApiError && refusal.status === 404 ? goneOrRefused(client, id) : refusal
}

/** The source version a save through a copy names, from the form's record when it has one. */
function sourceBaseOf(context: WriteContext, opened: Opened | undefined, copyPath: boolean): string | null {
	if (!opened) return context.sourceUpdatedAt
	// A form opened while its row was saved alone made its edit from no source version, so the source's current one would hide a change since.
	if (copyPath && opened.sourceUpdatedAt === null) throw new WriteFailure({ code: 'stale_source' })
	return opened.sourceUpdatedAt
}

export interface ShareSaveInput {
	accountId: string
	id: string
	/** The editor's own sparse patch, value fields already encrypted to the editor. */
	patch: Record<string, unknown>
	/** The patch's value fields as typed, `null` where cleared. */
	plain: Values
	baseUpdatedAt: string
	/** The caller's `write-context` read, which picks the path. */
	context: WriteContext
	/** The saving form's record; absent when it is gone or the save had no form. */
	opened: Opened | undefined
	withoutUnreachable: ReadonlySet<string>
}

interface RequiredCopy { secretId: string; holder: string; targetUserId: string | null }
interface RequiredShare { shareId: string; recipientCloudId: string }

const isString = (value: unknown): value is string => typeof value === 'string' && value !== ''
const listOf = (value: unknown): unknown[] => (Array.isArray(value) ? value : [])

function incompleteOf(body: unknown) {
	const answer = body as { copies?: { required?: unknown; skipRefused?: unknown }; federated?: { required?: unknown } } | null
	const copies = listOf(answer?.copies?.required).flatMap((row): RequiredCopy[] => {
		const r = row as Partial<Record<keyof RequiredCopy, unknown>> | null
		return isString(r?.secretId) ? [{ secretId: r.secretId, holder: String(r.holder), targetUserId: isString(r.targetUserId) ? r.targetUserId : null }] : []
	})
	const shares = listOf(answer?.federated?.required).flatMap((row): RequiredShare[] => {
		const r = row as Partial<Record<keyof RequiredShare, unknown>> | null
		return isString(r?.shareId) && isString(r.recipientCloudId) ? [{ shareId: r.shareId, recipientCloudId: r.recipientCloudId }] : []
	})
	return { copies, skipRefused: new Set(listOf(answer?.copies?.skipRefused).filter(isString)), shares }
}

/** The share-save's own refusals that read otherwise through `writeError`; `cloudIds` by share id, for the partner the server names. */
function refusalOf(error: unknown, cloudIds: ReadonlyMap<string, string>): unknown {
	if (!(error instanceof ApiError)) return error
	// Nextcloud's page for a route this Keepiq doesn't have, as an `-unstable` build from before share-save.
	if (error instanceof KeepiqNotInstalled) return new WriteFailure({ code: 'keepiq_outdated' })
	if (error.status === 409) return conflictRefusal(error, cloudIds)
	if (error.refused && error.code === 'not_allowed') return new WriteFailure({ code: 'forbidden' })
	return error
}

function conflictRefusal(error: ApiError, cloudIds: ReadonlyMap<string, string>): unknown {
	if (error.code === 'stale_source') return new WriteFailure({ code: 'stale_source' })
	if (error.code === 'partner_upgrade_required') return partnerUpgradeRefusal(error, cloudIds)
	return error.refused && error.code === 'not_allowed' ? new WriteFailure({ code: 'forbidden' }) : error
}

function partnerUpgradeRefusal(error: ApiError, cloudIds: ReadonlyMap<string, string>): WriteFailure {
	const body = error.body as { shareId?: unknown; requiredVersion?: unknown } | null
	const shareId = isString(body?.shareId) ? body.shareId : null
	const cloudId = shareId && cloudIds.get(shareId)
	return new WriteFailure({
		code: 'partner_upgrade_required',
		partners: shareId && cloudId ? [{ shareId, cloudId }] : [],
		...(isString(body?.requiredVersion) && { requiredVersion: body.requiredVersion }),
	})
}

/** Exactly the fields the patch carries, with the same null-ness, or the server refuses it (`copy_fields_mismatch`). */
async function sealSparse(plain: Values, key: CryptoKey): Promise<Values> {
	const sealed: Values = {}
	for (const field of VALUE_FIELDS) {
		const value = plain[field]
		if (value !== undefined) sealed[field] = await sealValue(field, value, key)
	}
	return sealed
}

/** Null where the partner does not store a field: a partner left out of it keeps its value only under the same certificate. */
async function sealWhole(value: Values, key: CryptoKey, valueFields: readonly string[]) {
	const optional = async (field: (typeof PARTNER_OPTIONAL)[number]) => {
		const plain = value[field]
		return plain && valueFields.includes(field) ? rsaEncrypt(plain, key) : null
	}
	return {
		key: await rsaEncrypt(value.key ?? '', key),
		login: value.login ? await rsaEncrypt(value.login, key) : null,
		additionalFields: value.additionalFields ? await rsaEncrypt(value.additionalFields, key) : null,
		totp: await optional('totp'),
		passkeys: await optional('passkeys'),
	}
}

type PartnerOutcome = { verified: VerifiedPartner } | { suspend: string } | 'unreachable'

/** A lookup answer in the shape `FederatedCertificateService::parseAnswer` guarantees. */
interface CertificateAnswer {
	certificate: string
	chain: string[]
	pin: string
	valueFields: unknown
}

/** Looks the partner recipient up and verifies the answer against the share's own cloud id, not the lookup's echo of it. */
async function lookUp(client: Client, share: RequiredShare): Promise<PartnerOutcome> {
	let answer: Record<string, unknown> | null
	try {
		answer = await client.keepiq('POST', '/api/v1/federation/recipient-certificate', { cloudId: share.recipientCloudId })
	} catch (error) {
		return lookupFailure(error)
	}
	return verifiedOutcome(share, checkedAnswer(answer))
}

function lookupFailure(error: unknown): PartnerOutcome {
	if (!(error instanceof ApiError)) throw error
	if (error.status === 429) throw new WriteFailure({ code: 'partner_lookup_limit' })
	const outcome = partnerGone(error)
	if (outcome) return outcome
	throw serverFailure(error)
}

/** The partner did not answer, or answered that the recipient or the partnership is gone. */
function partnerGone(error: ApiError): PartnerOutcome | null {
	if (error.status === 502 && error.message === 'partner_unreachable') return 'unreachable'
	return PARTNER_GONE.get(error.message) === error.status ? { suspend: error.message } : null
}

/** This server failed, not the partner, so nothing is suspended; its 409 is its own refusal, as a Nextcloud too old for partner sharing, not a newer version. */
function serverFailure(error: ApiError): unknown {
	if (error.status !== 409) return error
	return new WriteFailure({ code: error.message === 'federation_unavailable' ? 'federation_unavailable' : 'server_error' })
}

const isChain = (chain: unknown): chain is string[] => Array.isArray(chain) && chain.length === 2 && chain.every((pem) => typeof pem === 'string')

const isPin = (pin: unknown): pin is string => typeof pin === 'string' && /^[0-9a-f]{64}$/i.test(pin)

/** `FederatedCertificateService::parseAnswer` guarantees this shape, and `FederationPartnerService::add` the pin's, so another is this server's fault, and a suspension can't be undone. */
function checkedAnswer(answer: Record<string, unknown> | null): CertificateAnswer {
	const certificate = answer?.certificate
	const chain = answer?.chain
	const pin = answer?.partnerRootFingerprint
	if (typeof certificate !== 'string' || !isChain(chain) || !isPin(pin)) throw new WriteFailure({ code: 'server_error' })
	return { certificate, chain, pin, valueFields: answer?.valueFields }
}

async function verifiedOutcome(share: RequiredShare, answer: CertificateAnswer): Promise<PartnerOutcome> {
	// An older partner sends none, and stores none of those fields.
	const valueFields = listOf(answer.valueFields).filter(isString)
	try {
		return { verified: { ...await verifyFederatedCertificate({ certificate: answer.certificate, chain: answer.chain, partnerRootFingerprint: answer.pin, cloudId: share.recipientCloudId }), valueFields } }
	} catch (error) {
		return certificateFailure(error)
	}
}

/** Validity is judged by this machine's clock, which may be wrong, so it stops the save instead of suspending for good. */
function certificateFailure(error: unknown): PartnerOutcome {
	if (error instanceof FederatedCertificateError && error.reason === 'not_valid_now') throw new WriteFailure({ code: 'partner_clock' })
	if (error instanceof FederatedCertificateError) return { suspend: error.reason }
	throw error
}

/** Resolves with the note the item's detail shows, or `undefined` when everyone got the change; a rejection wrote nothing, unless the reply to the final call was lost. */
export async function shareSave(client: Client, privateKey: CryptoKey, input: ShareSaveInput): Promise<ShareSaveNote | undefined> {
	const partners = verifiedPartners(input.accountId)
	try {
		const note = await save({ client, privateKey, partners }, input)
		partners.succeeded()
		return note
	} catch (error) {
		partners.failed()
		throw error
	}
}

/** Who saves: the client, the editor's private key, and the partners verified for the account. */
interface ShareSaveSession {
	client: Client
	privateKey: CryptoKey
	partners: ReturnType<typeof verifiedPartners>
}

async function save(session: ShareSaveSession, input: ShareSaveInput): Promise<ShareSaveNote | undefined> {
	if (isViewOnly(input.id, input.context)) throw new WriteFailure({ code: 'view_only' })
	return new ShareSaveRun(session, input).run()
}

/** One entry of a list the save sends, keyed by its list's prefix and its own key. */
interface Pending {
	key: string
	entry: object
}

/** One list of the save, by key; `prefix` names the list in what was already staged. */
class Staged<T extends object> {
	readonly entries = new Map<string, T>()

	constructor(private readonly prefix: string) {}

	unsent(sent: ReadonlySet<string>): Pending[] {
		return [...this.entries].filter(([key]) => !sent.has(this.prefix + key)).map(([key, entry]) => ({ key: this.prefix + key, entry }))
	}

	/** Drops every entry `keep` refuses; `true` when one of them was already staged. */
	drop(keep: (key: string) => boolean, sent: ReadonlySet<string>): boolean {
		let restage = false
		for (const key of [...this.entries.keys()]) {
			if (keep(key)) continue
			this.entries.delete(key)
			restage ||= sent.has(this.prefix + key)
		}
		return restage
	}
}

const pick = (part: Pending[], prefix: string) => part.filter(({ key }) => key.startsWith(prefix)).map(({ entry }) => entry)

/** What one round of partner shares found: the whole value they get, the fields they must be able to store, and who could not take it. */
interface PartnerRound {
	value: Values
	adds: ReadonlyArray<typeof PARTNER_OPTIONAL[number]>
	unreachable: RequiredShare[]
	tooOld: RequiredShare[]
}

/** A partner recipient with a verified certificate, and whether it was already verified for the account. */
interface FoundPartner {
	share: RequiredShare
	partner: VerifiedPartner
	kept: boolean
}

const isStageUnknown = (error: unknown) => error instanceof ApiError && error.status === 404 && error.code === 'stage_unknown'

const isIncomplete = (error: unknown) => error instanceof ApiError && error.status === 409 && error.code === 'incomplete'

const copyNote = (answer: unknown): ShareSaveNote | undefined => ((answer as { federated?: { ownerOnly?: unknown } } | null)?.federated?.ownerOnly === true ? { ownerOnly: true } : undefined)

/** A certificate row of the recipient lookup, for a user who can be shared with. */
function certificateRow(row: unknown): { userId: string; certificate: string } | null {
	const r = row as { userId?: unknown; shareable?: unknown; certificate?: unknown } | null
	return isString(r?.userId) && r.shareable === true && isString(r.certificate) ? { userId: r.userId, certificate: r.certificate } : null
}

function refuseTooOld(tooOld: RequiredShare[]): void {
	if (tooOld.length === 0) return
	const partners = tooOld.map((share) => ({ shareId: share.shareId, cloudId: share.recipientCloudId }))
	throw new WriteFailure({ code: 'partner_upgrade_required', partners, requiredVersion: TOTP_PASSKEYS_FIELDS_SINCE })
}

/** Only shares the user agreed to leave out are suspended; naming every unreachable one lets one Save without them cover them all. */
function refuseUnreachable(unreachable: RequiredShare[], withoutUnreachable: ReadonlySet<string>): void {
	if (unreachable.some((share) => !withoutUnreachable.has(share.shareId))) {
		throw new WriteFailure({ code: 'partner_unreachable', partners: unreachable.map((share) => ({ shareId: share.shareId, cloudId: share.recipientCloudId })) })
	}
}

/** One share-save: the lists sent so far, the certificates read for them, and the stage the server holds. */
class ShareSaveRun {
	private readonly copyPath: boolean
	private readonly sourceBaseUpdatedAt: string | null
	private ownerCertificate: string | null
	private readonly copies = new Staged<Values & { secretId: string }>('c:')
	private readonly skipped = new Staged<{ secretId: string; reason: 'no_vault_key' }>('s:')
	private readonly federated = new Staged<Awaited<ReturnType<typeof sealWhole>> & { shareId: string; certFingerprint: string }>('f:')
	private readonly suspend = new Staged<{ shareId: string; reason: string }>('p:')
	private readonly cloudIds = new Map<string, string>()
	private readonly certificates = new Map<string, string | null>()
	private readonly keys = new Map<string, Promise<CryptoKey>>()
	/** Holders whose skip the server refused and whose certificate was read again; once each. */
	private readonly reread = new Set<string>()
	private whole: Values | undefined
	private stage: { id: string | null; sent: Set<string> } = { id: null, sent: new Set() }
	private rounds = 0
	/** Set when a fill dropped an entry already staged, which cannot be taken back, so a new stage starts. */
	private restage = false

	constructor(private readonly session: ShareSaveSession, private readonly input: ShareSaveInput) {
		this.copyPath = !input.context.ownRow && input.context.sourceSecretId !== input.id
		this.sourceBaseUpdatedAt = sourceBaseOf(input.context, input.opened, this.copyPath)
		this.ownerCertificate = input.context.ownerCertificate
	}

	async run(): Promise<ShareSaveNote | undefined> {
		const answer = await this.sendUntilComplete()
		return this.copyPath ? copyNote(answer) : this.partnerNote()
	}

	private async sendUntilComplete(): Promise<unknown> {
		for (;;) {
			try {
				return await this.send()
			} catch (error) {
				await this.recover(error)
			}
		}
	}

	/** The server forgot a stage this save started, so the save starts over. */
	private restartable(error: unknown): boolean {
		return isStageUnknown(error) && this.stage.id !== null
	}

	private async recover(error: unknown): Promise<void> {
		const restart = this.restartable(error)
		if (!restart && !isIncomplete(error)) throw await saveRefusal(this.session.client, this.input.id, this.input.context, refusalOf(error, this.cloudIds))
		if (++this.rounds >= MAX_ROUNDS) throw new WriteFailure({ code: 'incomplete' })
		if (restart) this.stage = { id: null, sent: new Set() }
		else await this.fill((error as ApiError).body)
	}

	/** Those the user left out through Save without them get their own line, worded for a pause, not a verdict on the recipient. */
	private partnerNote(): ShareSaveNote | undefined {
		const named = (paused: boolean) => [...this.suspend.entries.values()].filter((entry) => (entry.reason === 'partner_unreachable') === paused).map((entry) => this.cloudIds.get(entry.shareId)!)
		const suspended = named(false)
		const paused = named(true)
		if (suspended.length === 0 && paused.length === 0) return undefined
		return { ...(suspended.length > 0 && { suspended }), ...(paused.length > 0 && { paused }) }
	}

	private keyOf(certificate: string): Promise<CryptoKey> {
		let key = this.keys.get(certificate)
		if (!key) this.keys.set(certificate, key = importPublicKey(certificate).catch(() => {
			throw new WriteFailure({ code: 'bad_certificate' })
		}))
		return key
	}

	/** The typed fields plus every other one as the form opened it in full; the server's rows are never decrypted for it (ADR-002). */
	private async wholeValue(): Promise<Values> {
		const { opened, baseUpdatedAt } = this.input
		// The form's ciphertext is the item's only at the version the save is checked against.
		const shown = opened && Date.parse(opened.updatedAt) === Date.parse(baseUpdatedAt) ? opened.shown : null
		const value: Values = {}
		for (const field of VALUE_FIELDS) value[field] = await this.wholeField(field, shown)
		return value
	}

	private async wholeField(field: ValueField, shown: Opened['shown'] | null): Promise<string | null | undefined> {
		if (this.input.plain[field] !== undefined) return this.input.plain[field]
		if (!shown || !(field in shown)) throw new WriteFailure({ code: 'unverified' })
		return this.decryptShown(shown[field])
	}

	private async decryptShown(ciphertext: string | null | undefined): Promise<string | null> {
		try {
			return ciphertext ? await rsaDecrypt(ciphertext, this.session.privateKey) : null
		} catch (error) {
			console.error('[keepiq] could not open', this.input.id, 'for its partner shares', error)
			throw new WriteFailure({ code: 'unreadable' })
		}
	}

	private get url(): string {
		return `${secretPath(this.input.id)}/share-save`
	}

	private envelope(): Record<string, unknown> {
		return { baseUpdatedAt: this.input.baseUpdatedAt, ...(this.copyPath ? { sourceBaseUpdatedAt: this.sourceBaseUpdatedAt } : {}) }
	}

	private lists(localPart: Pending[], remotePart: Pending[]): Record<string, object[]> {
		return {
			copies: pick(localPart, 'c:'),
			skipped: pick(localPart, 's:'),
			...(this.copyPath ? {} : { federated: pick(remotePart, 'f:'), suspend: pick(remotePart, 'p:') }),
		}
	}

	private async send(): Promise<unknown> {
		const sent = this.stage.sent
		let parts = { local: [...this.copies.unsent(sent), ...this.skipped.unsent(sent)], remote: [...this.federated.unsent(sent), ...this.suspend.unsent(sent)] }
		// A chunk goes only while a list holds more than the bound, so each is full on that list and never empty.
		while (parts.local.length > BATCH || parts.remote.length > BATCH) parts = await this.sendChunk(parts)
		const stage = this.stage.id ? { stage: { id: this.stage.id, final: true } } : {}
		return this.session.client.keepiq('PUT', this.url, { ...this.envelope(), patch: this.input.patch, ...this.lists(parts.local, parts.remote), ...stage })
	}

	private async sendChunk(parts: { local: Pending[]; remote: Pending[] }): Promise<{ local: Pending[]; remote: Pending[] }> {
		const localPart = parts.local.slice(0, BATCH)
		const remotePart = parts.remote.slice(0, BATCH)
		const stage = this.stage.id ? { id: this.stage.id, final: false } : { final: false }
		const answer = await this.session.client.keepiq<{ stageId?: unknown } | null>('PUT', this.url, { ...this.envelope(), ...this.lists(localPart, remotePart), stage })
		if (!isString(answer?.stageId)) throw new WriteFailure({ code: 'unknown' })
		this.stage.id = answer.stageId
		for (const { key } of [...localPart, ...remotePart]) this.stage.sent.add(key)
		return { local: parts.local.slice(BATCH), remote: parts.remote.slice(BATCH) }
	}

	private dropFrom<T extends object>(staged: Staged<T>, keep: (key: string) => boolean): void {
		if (staged.drop(keep, this.stage.sent)) this.restage = true
	}

	private async fill(body: unknown): Promise<void> {
		const required = incompleteOf(body)
		this.restage = false
		const copyIds = new Set(required.copies.map((copy) => copy.secretId))
		this.dropFrom(this.copies, (key) => copyIds.has(key))
		this.dropFrom(this.skipped, (key) => copyIds.has(key) && !required.skipRefused.has(key))
		await this.rereadRefused(required)
		const todo = required.copies.filter((copy) => !this.copies.entries.has(copy.secretId) && !this.skipped.entries.has(copy.secretId))
		await this.lookUpCertificates(todo)
		await this.sealCopies(todo)
		if (!this.copyPath) await this.fillPartners(required.shares)
		if (this.restage) this.stage = { id: null, sent: new Set() }
	}

	private async rereadRefused(required: ReturnType<typeof incompleteOf>): Promise<void> {
		for (const holder of required.copies.filter((copy) => required.skipRefused.has(copy.secretId) && !this.reread.has(copy.secretId))) {
			this.reread.add(holder.secretId)
			if (holder.holder === 'owner') this.ownerCertificate = (await readWriteContext(this.session.client, this.input.id)).ownerCertificate
			else if (holder.targetUserId) this.certificates.delete(holder.targetUserId)
		}
	}

	private async lookUpCertificates(todo: RequiredCopy[]): Promise<void> {
		const lookups = [...new Set(todo.flatMap((copy) => (copy.holder !== 'owner' && copy.targetUserId && !this.certificates.has(copy.targetUserId) ? [copy.targetUserId] : [])))]
		for (let i = 0; i < lookups.length; i += BATCH) await this.lookUpBatch(lookups.slice(i, i + BATCH))
	}

	private async lookUpBatch(batch: string[]): Promise<void> {
		const answer = await this.session.client.keepiq<{ recipients?: unknown } | null>('POST', '/api/v1/shares/recipient-certificates', { userIds: batch })
		for (const userId of batch) this.certificates.set(userId, null)
		for (const row of listOf(answer?.recipients)) {
			const found = certificateRow(row)
			if (found) this.certificates.set(found.userId, found.certificate)
		}
	}

	private certificateOf(holder: RequiredCopy): string | null | undefined {
		if (holder.holder === 'owner') return this.ownerCertificate
		return holder.targetUserId ? this.certificates.get(holder.targetUserId) : null
	}

	private async sealCopies(todo: RequiredCopy[]): Promise<void> {
		for (const holder of todo) {
			const certificate = this.certificateOf(holder)
			if (certificate) this.copies.entries.set(holder.secretId, { secretId: holder.secretId, ...await sealSparse(this.input.plain, await this.keyOf(certificate)) })
			else this.skipped.entries.set(holder.secretId, { secretId: holder.secretId, reason: 'no_vault_key' })
		}
	}

	private async fillPartners(required: RequiredShare[]): Promise<void> {
		const shareIds = new Set(required.map((share) => share.shareId))
		this.dropFrom(this.federated, (key) => shareIds.has(key))
		this.dropFrom(this.suspend, (key) => shareIds.has(key))
		const todo = required.filter((share) => !this.federated.entries.has(share.shareId) && !this.suspend.entries.has(share.shareId))
		if (todo.length === 0) return
		const round = await this.partnerRound()
		for (const share of todo) await this.fillPartner(share, round)
		refuseTooOld(round.tooOld)
		refuseUnreachable(round.unreachable, this.input.withoutUnreachable)
		for (const share of round.unreachable) this.suspend.entries.set(share.shareId, { shareId: share.shareId, reason: 'partner_unreachable' })
	}

	/** A replaced or removed key or passkey reaches a partner that cannot store it as null, since it never held one; only a field the item did not hold stops the save, as in the web app. */
	private async partnerRound(): Promise<PartnerRound> {
		const value = this.whole ??= await this.wholeValue()
		const { plain, opened } = this.input
		const adds = PARTNER_OPTIONAL.filter((field) => !!plain[field] && !opened?.shown[field])
		return { value, adds, unreachable: [], tooOld: [] }
	}

	private async fillPartner(share: RequiredShare, round: PartnerRound): Promise<void> {
		const kept = this.session.partners.get(share)
		const outcome = kept ? { verified: kept } : await lookUp(this.session.client, share)
		this.cloudIds.set(share.shareId, share.recipientCloudId)
		if (outcome === 'unreachable') round.unreachable.push(share)
		else if ('suspend' in outcome) this.suspend.entries.set(share.shareId, { shareId: share.shareId, reason: outcome.suspend })
		else await this.fillVerified({ share, partner: outcome.verified, kept: !!kept }, round)
	}

	private async fillVerified({ share, partner, kept }: FoundPartner, round: PartnerRound): Promise<void> {
		const key = await importSpki(partner.spki).catch(() => {
			throw new WriteFailure({ code: 'bad_certificate' })
		})
		if (!kept) this.session.partners.add(share, partner)
		if (round.adds.some((field) => !partner.valueFields.includes(field))) round.tooOld.push(share)
		else this.federated.entries.set(share.shareId, { shareId: share.shareId, certFingerprint: partner.fingerprint, ...await sealWhole(round.value, key, partner.valueFields) })
	}
}
