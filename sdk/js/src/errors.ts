/** Typed errors of the Keepiq client. Check with `instanceof`. */

export class KeepiqError extends Error {
	constructor(message: string) {
		super(message)
		this.name = new.target.name
	}
}

/** No such secret in the application's vault. */
export class NotFoundError extends KeepiqError {}

/** The token exchange or a call with a fresh token was refused. */
export class UnauthorizedError extends KeepiqError {}

/** The secret is unchanged since the last read of the same address (HTTP 304). */
export class NotModifiedError extends KeepiqError {}

/** The envelope is encrypted to a certificate other than the configured one. */
export class KeyMismatchError extends KeepiqError {}

export interface Candidate {
	id: string
	name: string
	folderPath: string
	updatedAt?: string
}

/** Several secrets share the name (HTTP 409). Narrow with a folder or rename one. */
export class AmbiguousNameError extends KeepiqError {
	constructor(public readonly secretName: string, public readonly candidates: Candidate[]) {
		super(`${candidates.length} secrets are named "${secretName}": ${candidates.map((c) => `${c.id} (${c.folderPath || '/'})`).join(', ')}`)
	}
}

/** Any other non-success answer. */
export class ApiError extends KeepiqError {
	constructor(public readonly status: number, message: string) {
		super(`server answered ${status}: ${message}`)
	}
}
