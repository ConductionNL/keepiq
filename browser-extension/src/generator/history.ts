import { importPublicKey, rsaDecrypt, rsaEncrypt } from '@/src/crypto'
import { readProvenCertificate } from '@/src/vault/certificate'
import { generatorHistoryKey, generatorSealedKey, getKey, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { GENERATOR_KINDS, HISTORY_LIMIT, type GeneratorHistoryEntry } from './options'
import { createQueue } from './queue'

const isEntry = (value: unknown): value is GeneratorHistoryEntry => {
	const entry = value as Partial<GeneratorHistoryEntry> | null
	return typeof entry?.value === 'string' && GENERATOR_KINDS.includes(entry.kind!) && typeof entry.createdAt === 'string'
}

async function readArray(key: string): Promise<unknown[]> {
	const stored = await sessionValues.get(key)
	if (stored === undefined) return []
	try {
		const values: unknown = JSON.parse(stored)
		return Array.isArray(values) ? values : []
	} catch {
		return []
	}
}

/** Newest first. */
export async function listHistory(accountId: string): Promise<GeneratorHistoryEntry[]> {
	return (await readArray(generatorHistoryKey(accountId))).filter(isEntry)
}

const queued = createQueue()

/** `epoch` is read when the request arrives, so a lock or logout before the queue reaches this add also counts. */
export function addHistory(accountId: string, entry: Omit<GeneratorHistoryEntry, 'createdAt'>, epoch = sessionEpoch(accountId), now = new Date()): Promise<GeneratorHistoryEntry[]> {
	return queued(async () => {
		const entries = await listHistory(accountId)
		// A lock or logout since the request arrived cleared the history; writing now would bring it back.
		if (sessionEpoch(accountId) !== epoch) return []
		// Copying a value then using it records it once.
		if (entries[0]?.value === entry.value) return entries
		const next = [{ value: entry.value, kind: entry.kind, createdAt: now.toISOString() }, ...entries].slice(0, HISTORY_LIMIT)
		await sessionValues.set({ [generatorHistoryKey(accountId)]: JSON.stringify(next) })
		return next
	})
}

/** Encrypted to the certificate the last unlock proved, so nothing at the browser reads it before an unlock (ADR-002). Without one, not recorded. */
export function sealHistory(accountId: string, entry: Omit<GeneratorHistoryEntry, 'createdAt'>, epoch = sessionEpoch(accountId), now = new Date()): Promise<void> {
	return queued(async () => {
		const certificate = await readProvenCertificate(accountId)
		if (!certificate) return
		let sealed: string
		try {
			sealed = await rsaEncrypt(JSON.stringify({ value: entry.value, kind: entry.kind, createdAt: now.toISOString() }), await importPublicKey(certificate))
		} catch {
			return
		}
		const stored = await readArray(generatorSealedKey(accountId))
		if (sessionEpoch(accountId) !== epoch) return
		await sessionValues.set({ [generatorSealedKey(accountId)]: JSON.stringify([...stored, sealed].slice(-HISTORY_LIMIT)) })
	})
}

async function unseal(sealed: unknown, key: CryptoKey): Promise<unknown> {
	try {
		return typeof sealed === 'string' ? JSON.parse(await rsaDecrypt(sealed, key)) : null
	} catch {
		return null
	}
}

/**
 * The history, with what the lock screen sealed opened into it and then removed; what does not open is dropped.
 * `[]` once a lock or logout since `epoch` cleared it.
 */
export function openHistory(accountId: string, epoch = sessionEpoch(accountId)): Promise<GeneratorHistoryEntry[]> {
	return queued(async () => {
		const entries = await listHistory(accountId)
		const sealed = await readArray(generatorSealedKey(accountId))
		if (sealed.length === 0) return sessionEpoch(accountId) === epoch ? entries : []
		const key = await getKey(accountId)
		// Only as many as the history keeps, so a planted array can't queue thousands of decrypts.
		const opened = key ? (await Promise.all(sealed.slice(-HISTORY_LIMIT).map((value) => unseal(value, key)))).filter(isEntry)
			.map(({ value, kind, createdAt }) => ({ value, kind, createdAt })) : []
		if (!key || sessionEpoch(accountId) !== epoch) return []
		// Sealed oldest first; reversed so values sealed in the same millisecond keep their order.
		const merged = [...opened.reverse(), ...entries].sort((a, b) => (a.createdAt < b.createdAt ? 1 : a.createdAt > b.createdAt ? -1 : 0))
		// As in `addHistory`, a value repeating the one before it is not recorded again.
		const next = merged.filter((entry, i) => merged[i + 1]?.value !== entry.value).slice(0, HISTORY_LIMIT)
		await sessionValues.set({ [generatorHistoryKey(accountId)]: JSON.stringify(next) })
		await sessionValues.remove([generatorSealedKey(accountId)])
		return next
	})
}

export function clearHistory(accountId: string): Promise<void> {
	return queued(() => sessionValues.remove([generatorHistoryKey(accountId), generatorSealedKey(accountId)]))
}
