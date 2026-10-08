import type { ErrorCode } from './messages'

/** A domain error the background turns into `{ ok: false, code }` for the popup. */
export class Failure extends Error {
	constructor(readonly code: ErrorCode, message: string = code) {
		super(message)
	}
}
