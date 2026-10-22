import { generatorHistoryKey, sessionEpoch, sessionValues } from '@/src/vault/key-store'
import { GENERATOR_KINDS, HISTORY_LIMIT, type GeneratorHistoryEntry } from './options'
import { createQueue } from './queue'

const isEntry = (value: unknown): value is GeneratorHistoryEntry => {
	const entry = value as Partial<GeneratorHistoryEntry> | null
	return typeof entry?.value === 'string' && GENERATOR_KINDS.includes(entry.kind!) && typeof entry.createdAt === 'string'
}

/** Newest first. */
export async function listHistory(accountId: string): Promise<GeneratorHistoryEntry[]> {
	const stored = await sessionValues.get(generatorHistoryKey(accountId))
	if (stored === undefined) return []
	try {
		const entries: unknown = JSON.parse(stored)
		return Array.isArray(entries) ? entries.filter(isEntry) : []
	} catch {
		return []
	}
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

export function clearHistory(accountId: string): Promise<void> {
	return queued(() => sessionValues.remove([generatorHistoryKey(accountId)]))
}
