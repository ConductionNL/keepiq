/** A value change of a possibly shared item in one `PUT /api/v1/secrets/{id}/share-save`, ported from the web app's `saveShared` (`src/services/shareSave.js`); the server's rules are in `ShareSaveService`. */
import { ApiError, type Client } from '@/src/api/client'
import { FederatedCertificateError, importPublicKey, importSpki, rsaDecrypt, rsaEncrypt, verifyFederatedCertificate, type VerifiedFederatedCertificate } from '@/src/crypto'
import type { DecryptField, ShareSaveNote } from '@/src/messages'
import { verifiedPartners } from './verified-partners'
import { WriteFailure } from './write-error'

export const VALUE_FIELDS: readonly DecryptField[] = ['key', 'login', 'additionalFields']

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

/** Plaintext or ciphertext per value field, `null` for an empty one. */
export type Values = Partial<Record<DecryptField, string | null>>

/** What an edit form opened: the ciphertext of each field it showed in full, the row version, and the source version `write-context` named. */
export interface Opened {
	shown: Values
	updatedAt: string
	sourceUpdatedAt: string | null
}

export interface WriteContext {
	sourceSecretId: string
	effectiveGrade: string
	ownerCertificate: string | null
	sourceUpdatedAt: string | null
}

export async function readWriteContext(client: Client, id: string): Promise<WriteContext> {
	const context = await client.keepiq<Partial<Record<keyof WriteContext, unknown>> | null>('GET', `${secretPath(id)}/write-context`)
	if (typeof context?.sourceSecretId !== 'string') throw new WriteFailure({ code: 'unknown' })
	const text = (value: unknown) => (typeof value === 'string' && value ? value : null)
	return {
		sourceSecretId: context.sourceSecretId,
		effectiveGrade: text(context.effectiveGrade) ?? 'none',
		ownerCertificate: text(context.ownerCertificate),
		sourceUpdatedAt: text(context.sourceUpdatedAt),
	}
}

export interface ShareSaveInput {
	accountId: string
	id: string
	/** The editor's own sparse patch, value fields already encrypted to the editor. */
	patch: Record<string, unknown>
	/** The patch's value fields as typed, `null` where cleared. */
	plain: Values
	baseUpdatedAt: string
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

/** The share-save's own refusals that read otherwise through `writeError`. */
function refusalOf(error: unknown): unknown {
	if (!(error instanceof ApiError)) return error
	if (error.status === 409 && error.code === 'stale_source') return new WriteFailure({ code: 'stale_source' })
	if (error.refused && error.code === 'not_allowed') return new WriteFailure({ code: 'forbidden' })
	return error
}

/** Exactly the fields the patch carries, with the same null-ness, or the server refuses it (`copy_fields_mismatch`). */
async function sealSparse(plain: Values, key: CryptoKey): Promise<Values> {
	const sealed: Values = {}
	for (const field of VALUE_FIELDS) {
		const value = plain[field]
		if (value !== undefined) sealed[field] = value === null ? null : await rsaEncrypt(value, key)
	}
	return sealed
}

async function sealWhole(value: Values, key: CryptoKey) {
	return {
		key: await rsaEncrypt(value.key ?? '', key),
		login: value.login ? await rsaEncrypt(value.login, key) : null,
		additionalFields: value.additionalFields ? await rsaEncrypt(value.additionalFields, key) : null,
	}
}

type PartnerOutcome = { verified: VerifiedFederatedCertificate } | { suspend: string } | 'unreachable'

/** Looks the partner recipient up and verifies the answer against the share's own cloud id, not the lookup's echo of it. */
async function lookUp(client: Client, share: RequiredShare): Promise<PartnerOutcome> {
	let answer: Record<string, unknown> | null
	try {
		answer = await client.keepiq('POST', '/api/v1/federation/recipient-certificate', { cloudId: share.recipientCloudId })
	} catch (error) {
		if (!(error instanceof ApiError)) throw error
		if (error.status === 429) throw new WriteFailure({ code: 'partner_lookup_limit' })
		if (error.status === 502 && error.message === 'partner_unreachable') return 'unreachable'
		if (PARTNER_GONE.get(error.message) === error.status) return { suspend: error.message }
		// This server failed, not the partner, so nothing is suspended; its 409 is its own refusal, as a Nextcloud too old for partner sharing, not a newer version.
		if (error.status === 409) throw new WriteFailure({ code: error.message === 'federation_unavailable' ? 'federation_unavailable' : 'server_error' })
		throw error
	}
	// `FederatedCertificateService::parseAnswer` guarantees this shape, and `FederationPartnerService::add` the pin's, so another is this server's fault, and a suspension can't be undone.
	const chain = answer?.chain
	const pin = answer?.partnerRootFingerprint
	if (typeof answer?.certificate !== 'string' || !Array.isArray(chain) || chain.length !== 2 || !chain.every((pem) => typeof pem === 'string') || typeof pin !== 'string' || !/^[0-9a-f]{64}$/i.test(pin)) {
		throw new WriteFailure({ code: 'server_error' })
	}
	try {
		return { verified: await verifyFederatedCertificate({ certificate: answer.certificate, chain, partnerRootFingerprint: pin, cloudId: share.recipientCloudId }) }
	} catch (error) {
		if (error instanceof FederatedCertificateError) return { suspend: error.reason }
		throw error
	}
}

/** Resolves with the note the item's detail shows, or `undefined` when everyone got the change; a rejection wrote nothing, unless the reply to the final call was lost. */
export async function shareSave(client: Client, privateKey: CryptoKey, input: ShareSaveInput): Promise<ShareSaveNote | undefined> {
	const partners = verifiedPartners(input.accountId)
	try {
		const note = await save(client, privateKey, input, partners)
		partners.succeeded()
		return note
	} catch (error) {
		partners.failed()
		throw error
	}
}

async function save(client: Client, privateKey: CryptoKey, { id, patch, plain, baseUpdatedAt, opened, withoutUnreachable }: ShareSaveInput, partners: ReturnType<typeof verifiedPartners>): Promise<ShareSaveNote | undefined> {
	const context = await readWriteContext(client, id)
	const copyPath = context.sourceSecretId !== id
	if (copyPath && !COPY_EDITOR_GRADES.has(context.effectiveGrade)) {
		await client.keepiq('PUT', secretPath(id), { ...patch, baseUpdatedAt })
		return { scope: 'own_copy' }
	}
	const sourceBaseUpdatedAt = opened?.sourceUpdatedAt ?? context.sourceUpdatedAt
	let ownerCertificate = context.ownerCertificate

	const copies = new Map<string, Values & { secretId: string }>()
	const skipped = new Map<string, { secretId: string; reason: 'no_vault_key' }>()
	const federated = new Map<string, Awaited<ReturnType<typeof sealWhole>> & { shareId: string; certFingerprint: string }>()
	const suspend = new Map<string, { shareId: string; reason: string }>()
	const cloudIds = new Map<string, string>()
	const certificates = new Map<string, string | null>()
	const keys = new Map<string, Promise<CryptoKey>>()
	/** Holders whose skip the server refused and whose certificate was read again; once each. */
	const reread = new Set<string>()
	let whole: Values | undefined
	let stage: { id: string | null; sent: Set<string> } = { id: null, sent: new Set() }

	const keyOf = (certificate: string) => {
		let key = keys.get(certificate)
		if (!key) keys.set(certificate, key = importPublicKey(certificate).catch(() => {
			throw new WriteFailure({ code: 'bad_certificate' })
		}))
		return key
	}

	/** The typed fields plus every other one as the form opened it in full; the server's rows are never decrypted for it (ADR-002). */
	const wholeValue = async (): Promise<Values> => {
		const value: Values = {}
		// The form's ciphertext is the item's only at the version the save is checked against.
		const current = opened && Date.parse(opened.updatedAt) === Date.parse(baseUpdatedAt)
		for (const field of VALUE_FIELDS) {
			if (plain[field] !== undefined) {
				value[field] = plain[field]
				continue
			}
			if (!current || !(field in opened.shown)) throw new WriteFailure({ code: 'unverified' })
			const ciphertext = opened.shown[field]
			try {
				value[field] = ciphertext ? await rsaDecrypt(ciphertext, privateKey) : null
			} catch (error) {
				console.error('[keepiq] could not open', id, 'for its partner shares', error)
				throw new WriteFailure({ code: 'unreadable' })
			}
		}
		return value
	}

	const send = async (): Promise<unknown> => {
		const unsent = <T>(map: Map<string, T>, prefix: string) => [...map].filter(([key]) => !stage.sent.has(prefix + key)).map(([key, entry]) => ({ key: prefix + key, entry }))
		let local = [...unsent(copies, 'c:'), ...unsent(skipped, 's:')]
		let remote = [...unsent(federated, 'f:'), ...unsent(suspend, 'p:')]
		const pick = (part: Array<{ key: string; entry: object }>, prefix: string) => part.filter(({ key }) => key.startsWith(prefix)).map(({ entry }) => entry)
		const lists = (localPart: typeof local, remotePart: typeof remote) => ({
			copies: pick(localPart, 'c:'),
			skipped: pick(localPart, 's:'),
			...(copyPath ? {} : { federated: pick(remotePart, 'f:'), suspend: pick(remotePart, 'p:') }),
		})
		const envelope = { baseUpdatedAt, ...(copyPath ? { sourceBaseUpdatedAt } : {}) }
		const url = `${secretPath(id)}/share-save`
		// A chunk goes only while a list holds more than the bound, so each is full on that list and never empty.
		while (local.length > BATCH || remote.length > BATCH) {
			const localPart = local.slice(0, BATCH)
			const remotePart = remote.slice(0, BATCH)
			const answer = await client.keepiq<{ stageId?: unknown } | null>('PUT', url, { ...envelope, ...lists(localPart, remotePart), stage: stage.id ? { id: stage.id, final: false } : { final: false } })
			if (!isString(answer?.stageId)) throw new WriteFailure({ code: 'unknown' })
			stage.id = answer.stageId
			for (const { key } of [...localPart, ...remotePart]) stage.sent.add(key)
			local = local.slice(BATCH)
			remote = remote.slice(BATCH)
		}
		return client.keepiq('PUT', url, { ...envelope, patch, ...lists(local, remote), ...(stage.id ? { stage: { id: stage.id, final: true } } : {}) })
	}

	type Drop = <T>(map: Map<string, T>, prefix: string, keep: (key: string) => boolean) => void

	const fillPartners = async (required: RequiredShare[], drop: Drop) => {
		const shareIds = new Set(required.map((share) => share.shareId))
		drop(federated, 'f:', (key) => shareIds.has(key))
		drop(suspend, 'p:', (key) => shareIds.has(key))
		const todo = required.filter((share) => !federated.has(share.shareId) && !suspend.has(share.shareId))
		if (todo.length === 0) return
		const value = whole ??= await wholeValue()
		const unreachable: RequiredShare[] = []
		for (const share of todo) {
			const kept = partners.get(share)
			const outcome = kept ? { verified: kept } : await lookUp(client, share)
			cloudIds.set(share.shareId, share.recipientCloudId)
			if (outcome === 'unreachable') unreachable.push(share)
			else if ('suspend' in outcome) suspend.set(share.shareId, { shareId: share.shareId, reason: outcome.suspend })
			else {
				const key = await importSpki(outcome.verified.spki).catch(() => {
					throw new WriteFailure({ code: 'bad_certificate' })
				})
				if (!kept) partners.add(share, outcome.verified)
				federated.set(share.shareId, { shareId: share.shareId, certFingerprint: outcome.verified.fingerprint, ...await sealWhole(value, key) })
			}
		}
		// Only shares the user agreed to leave out are suspended; naming every unreachable one lets one Save without them cover them all.
		if (unreachable.some((share) => !withoutUnreachable.has(share.shareId))) {
			throw new WriteFailure({ code: 'partner_unreachable', partners: unreachable.map((share) => ({ shareId: share.shareId, cloudId: share.recipientCloudId })) })
		}
		for (const share of unreachable) suspend.set(share.shareId, { shareId: share.shareId, reason: 'partner_unreachable' })
	}

	const fill = async (body: unknown) => {
		const required = incompleteOf(body)
		let restage = false
		// An entry already staged cannot be taken back, so dropping one starts a new stage.
		const drop: Drop = (map, prefix, keep) => {
			for (const key of [...map.keys()]) {
				if (keep(key)) continue
				map.delete(key)
				restage ||= stage.sent.has(prefix + key)
			}
		}
		const copyIds = new Set(required.copies.map((copy) => copy.secretId))
		drop(copies, 'c:', (key) => copyIds.has(key))
		drop(skipped, 's:', (key) => copyIds.has(key) && !required.skipRefused.has(key))
		for (const holder of required.copies.filter((copy) => required.skipRefused.has(copy.secretId) && !reread.has(copy.secretId))) {
			reread.add(holder.secretId)
			if (holder.holder === 'owner') ownerCertificate = (await readWriteContext(client, id)).ownerCertificate
			else if (holder.targetUserId) certificates.delete(holder.targetUserId)
		}
		const todo = required.copies.filter((copy) => !copies.has(copy.secretId) && !skipped.has(copy.secretId))
		const lookups = [...new Set(todo.flatMap((copy) => (copy.holder !== 'owner' && copy.targetUserId && !certificates.has(copy.targetUserId) ? [copy.targetUserId] : [])))]
		for (let i = 0; i < lookups.length; i += BATCH) {
			const batch = lookups.slice(i, i + BATCH)
			const answer = await client.keepiq<{ recipients?: unknown } | null>('POST', '/api/v1/shares/recipient-certificates', { userIds: batch })
			for (const userId of batch) certificates.set(userId, null)
			for (const row of listOf(answer?.recipients)) {
				const r = row as { userId?: unknown; shareable?: unknown; certificate?: unknown } | null
				if (isString(r?.userId) && r.shareable === true && isString(r.certificate)) certificates.set(r.userId, r.certificate)
			}
		}
		for (const holder of todo) {
			const certificate = holder.holder === 'owner' ? ownerCertificate : holder.targetUserId ? certificates.get(holder.targetUserId) : null
			if (certificate) copies.set(holder.secretId, { secretId: holder.secretId, ...await sealSparse(plain, await keyOf(certificate)) })
			else skipped.set(holder.secretId, { secretId: holder.secretId, reason: 'no_vault_key' })
		}
		if (!copyPath) await fillPartners(required.shares, drop)
		if (restage) stage = { id: null, sent: new Set() }
	}

	let rounds = 0
	let answer: unknown
	for (;;) {
		try {
			answer = await send()
			break
		} catch (error) {
			const restart = error instanceof ApiError && error.status === 404 && error.code === 'stage_unknown' && stage.id !== null
			if (!restart && !(error instanceof ApiError && error.status === 409 && error.code === 'incomplete')) throw refusalOf(error)
			if (++rounds >= MAX_ROUNDS) throw new WriteFailure({ code: 'incomplete' })
			if (restart) stage = { id: null, sent: new Set() }
			else await fill(error.body)
		}
	}
	if (copyPath) return (answer as { federated?: { ownerOnly?: unknown } } | null)?.federated?.ownerOnly === true ? { scope: 'copy', ownerOnly: true } : undefined
	// Those the user left out through Save without them get their own line, worded for a pause, not a verdict on the recipient.
	const named = (paused: boolean) => [...suspend.values()].filter((entry) => (entry.reason === 'partner_unreachable') === paused).map((entry) => cloudIds.get(entry.shareId)!)
	const suspended = named(false)
	const paused = named(true)
	if (suspended.length === 0 && paused.length === 0) return undefined
	return { scope: 'source', ...(suspended.length > 0 && { suspended }), ...(paused.length > 0 && { paused }) }
}
