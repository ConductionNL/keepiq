import { describe, expect, it, vi } from 'vitest'
import { ApiError, Offline, SessionRevoked, VaultWriteLocked } from '@/src/api/client'
import { WriteFailure, writeError } from './write-error'

describe('writeError', () => {
	it.each([
		['our own refusal', new WriteFailure({ code: 'restricted' }), 'restricted'],
		['a revoked session', new SessionRevoked(), 'session_revoked'],
		['a key migration', new VaultWriteLocked(423, 'Locked'), 'write_locked'],
		['no answer', new Offline(), 'offline'],
		['a 400', new ApiError(400, 'Bad'), 'invalid'],
		['a bare 428', new ApiError(428, 'Forbidden'), 'forbidden'],
		['a 403 naming a policy', new ApiError(403, 'Policy', 'org_ownership_required'), 'policy'],
		['a 404', new ApiError(404, 'Gone'), 'not_found'],
		['a 409', new ApiError(409, 'Stale'), 'conflict'],
		['a 502', new ApiError(502, 'Bad gateway'), 'server_error'],
	])('maps %s', (_, error, code) => {
		expect(writeError(error)).toEqual({ code })
	})

	it('logs what it cannot name', () => {
		const log = vi.spyOn(console, 'error').mockImplementation(() => {})
		expect(writeError(new ApiError(429, 'Too many'))).toEqual({ code: 'unknown' })
		expect(log).toHaveBeenCalled()
	})
})
