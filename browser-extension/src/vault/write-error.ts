/** The one mapping from a failed write to the code the popup words; share-save reports through it too. */
import { ApiError, Offline, SessionRevoked, VaultWriteLocked } from '@/src/api/client'
import type { VaultWriteError } from '@/src/messages'

/** A refusal of our own, before or instead of a request. */
export class WriteFailure extends Error {
	constructor(readonly error: VaultWriteError) {
		super(error.code)
	}
}

export function writeError(error: unknown): VaultWriteError {
	if (error instanceof WriteFailure) return error.error
	if (error instanceof SessionRevoked) return { code: 'session_revoked' }
	if (error instanceof VaultWriteLocked) return { code: 'write_locked' }
	if (error instanceof Offline) return { code: 'offline' }
	if (error instanceof ApiError) {
		if (error.status === 400) return { code: 'invalid' }
		// A blocked suite, a folder someone else owns and a read-only copy are all a bare refusal; only a policy names itself.
		if (error.refused) return { code: error.code ? 'policy' : 'forbidden' }
		if (error.status === 404) return { code: 'not_found' }
		if (error.status === 409) return { code: 'conflict' }
		if (error.status >= 500) return { code: 'server_error' }
	}
	console.error('[keepiq] write failed', error)
	return { code: 'unknown' }
}
