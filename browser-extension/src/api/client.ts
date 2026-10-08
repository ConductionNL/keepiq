import { toBase64 } from '@/src/crypto/base64'
import type { NextcloudUser, SuiteRow } from './types'

export const SESSION_REVOKED_MESSAGE = 'Session revoked, please log in again'

export class ApiError extends Error {
	constructor(readonly status: number, message: string) {
		super(message)
	}
}
export class SessionRevoked extends Error {
	constructor() {
		super(SESSION_REVOKED_MESSAGE)
	}
}
export class VaultWriteLocked extends ApiError {}
export class KeepiqNotInstalled extends ApiError {}
export class NotNextcloud extends Error {}
export class Offline extends Error {}

/** What the client needs of an account. No `id` means it is not stored yet (add verification). */
export interface ClientAccount {
	id?: string
	origin: string
	uid: string
	/** What the user typed; Nextcloud ties an app password to it, and it may differ from the uid. */
	loginName: string
	appPassword: string | null
}

let onUnauthorized: (accountId: string) => Promise<void> = async () => {}

/** The account store registers its purge here, so a 401 anywhere logs the account out. */
export function setUnauthorizedHandler(handler: (accountId: string) => Promise<void>): void {
	onUnauthorized = handler
}

export type Client = ReturnType<typeof createClient>

export function createClient(account: ClientAccount) {
	const keepiqBase = `${account.origin}/index.php/apps/keepiq`

	async function send(url: string, init: { method?: string; body?: unknown; accept?: string } = {}): Promise<Response> {
		if (account.appPassword === null) throw new SessionRevoked()
		const headers: Record<string, string> = {
			// btoa only takes Latin-1, and uids and app passwords may not be.
			Authorization: `Basic ${toBase64(new TextEncoder().encode(`${account.loginName}:${account.appPassword}`))}`,
			'OCS-APIRequest': 'true',
			Accept: init.accept ?? 'application/json',
		}
		if (init.body !== undefined) headers['Content-Type'] = 'application/json'
		let response: Response
		try {
			response = await fetch(url, {
				method: init.method ?? 'GET',
				headers,
				body: init.body === undefined ? undefined : JSON.stringify(init.body),
				credentials: 'omit',
			})
		} catch {
			throw new Offline()
		}
		if (response.status === 401) {
			if (account.id) await onUnauthorized(account.id)
			throw new SessionRevoked()
		}
		return response
	}

	async function keepiq<T>(method: string, path: string, body?: unknown): Promise<T> {
		const response = await send(`${keepiqBase}${path}`, { method, body })
		const json: unknown = await response.json().catch(() => undefined)
		if (response.ok) return json as T
		const message = typeof (json as { message?: unknown })?.message === 'string'
			? (json as { message: string }).message
			: response.statusText
		if (response.status === 423) throw new VaultWriteLocked(423, message)
		// Nextcloud answers unknown app routes with an HTML page.
		if (response.status === 404 && json === undefined) throw new KeepiqNotInstalled(404, message)
		throw new ApiError(response.status, message)
	}

	return {
		keepiq,

		async listSuites(): Promise<SuiteRow[]> {
			const rows = await keepiq<unknown>('GET', '/api/v1/suites')
			if (!Array.isArray(rows)) throw new ApiError(200, 'Unexpected suites response')
			return rows as SuiteRow[]
		},

		async fetchIdentity(): Promise<NextcloudUser> {
			const response = await send(`${account.origin}/ocs/v2.php/cloud/user?format=json`)
			const json = await response.json().catch(() => undefined) as { ocs?: { data?: NextcloudUser } } | undefined
			const data = json?.ocs?.data
			if (!response.ok || typeof data?.id !== 'string') throw new NotNextcloud()
			return data
		},

		/** `null` on any failure; callers fall back to initials. */
		async fetchAvatarDataUrl(size = 64): Promise<string | null> {
			try {
				const response = await send(`${account.origin}/index.php/avatar/${encodeURIComponent(account.uid)}/${size}`, { accept: 'image/*' })
				if (!response.ok) return null
				const type = response.headers.get('Content-Type')?.split(';')[0] || 'image/png'
				return `data:${type};base64,${toBase64(new Uint8Array(await response.arrayBuffer()))}`
			} catch (error) {
				if (error instanceof SessionRevoked) throw error
				return null
			}
		},
	}
}
