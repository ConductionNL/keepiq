import { toBase64 } from '@/src/crypto/base64'
import { Failure } from '@/src/failure'

/** Nextcloud's own limit on a Login Flow v2 token. */
export const LOGIN_FLOW_TIMEOUT_MS = 20 * 60_000
export const LOGIN_FLOW_POLL_MS = 2_000
/** `storage.session`: the error code and host of a flow that ended after the popup closed, for the add form to show. */
export const LOGIN_FLOW_FAILURE = 'loginFlowFailure'

export interface LoginFlowStart {
	loginUrl: string
	token: string
	endpoint: string
}

export interface LoginFlowCredentials {
	loginName: string
	appPassword: string
}

/** What the flow needs from the browser, so tests can stand in for tabs and time. */
export interface LoginFlowDeps {
	tabOpen: () => Promise<boolean>
	wait: (ms: number) => Promise<void>
	now: () => number
	signal: AbortSignal
}

const sameOrigin = (url: string, serverUrl: string): boolean => {
	try {
		return new URL(url).origin === new URL(serverUrl).origin
	} catch {
		return false
	}
}

async function post(url: string, body?: string): Promise<Response> {
	try {
		return await fetch(url, {
			method: 'POST',
			credentials: 'omit',
			headers: { Accept: 'application/json', ...(body === undefined ? {} : { 'Content-Type': 'application/x-www-form-urlencoded' }) },
			body,
		})
	} catch {
		throw new Failure('unreachable')
	}
}

/** `POST /index.php/login/v2`. The token is only ever sent back to the server the user named, so both URLs must stay on its origin. */
export async function startLoginFlow(serverUrl: string): Promise<LoginFlowStart> {
	const response = await post(`${serverUrl}/index.php/login/v2`)
	if (response.status >= 500) throw new Failure('server_error')
	const json = await response.json().catch(() => undefined) as { login?: unknown; poll?: { token?: unknown; endpoint?: unknown } } | undefined
	const { login, poll } = json ?? {}
	if (!response.ok || typeof login !== 'string' || typeof poll?.token !== 'string' || typeof poll.endpoint !== 'string') throw new Failure('not_nextcloud')
	if (!sameOrigin(login, serverUrl) || !sameOrigin(poll.endpoint, serverUrl)) throw new Failure('not_nextcloud')
	return { loginUrl: login, token: poll.token, endpoint: poll.endpoint }
}

/** One poll: `null` while the user has not finished signing in (Nextcloud answers 404). */
export async function pollLoginFlow({ token, endpoint }: Pick<LoginFlowStart, 'token' | 'endpoint'>): Promise<LoginFlowCredentials | null> {
	const response = await post(endpoint, new URLSearchParams({ token }).toString())
	if (response.status === 404) return null
	if (!response.ok) throw new Failure(response.status >= 500 ? 'server_error' : 'unknown')
	const json = await response.json().catch(() => undefined) as { loginName?: unknown; appPassword?: unknown } | undefined
	if (typeof json?.loginName !== 'string' || typeof json.appPassword !== 'string' || !json.loginName || !json.appPassword) throw new Failure('unknown')
	return { loginName: json.loginName, appPassword: json.appPassword }
}

/**
 * Polls until the user signed in, closed the login tab, or the token expired; `null` for the last two.
 * A network error while polling is retried, as a laptop may sleep through the sign-in.
 */
export async function awaitLoginFlow(flow: LoginFlowStart, deps: LoginFlowDeps): Promise<LoginFlowCredentials | null> {
	const deadline = deps.now() + LOGIN_FLOW_TIMEOUT_MS
	while (!deps.signal.aborted && deps.now() < deadline) {
		await deps.wait(LOGIN_FLOW_POLL_MS)
		if (deps.signal.aborted || !(await deps.tabOpen())) return null
		try {
			const credentials = await pollLoginFlow(flow)
			if (credentials) return credentials
		} catch (error) {
			if (!(error instanceof Failure && error.code === 'unreachable')) throw error
		}
	}
	return null
}

/** Best effort: an app password the extension could not use should not stay in the user's Devices & sessions. */
export async function deleteAppPassword(serverUrl: string, { loginName, appPassword }: LoginFlowCredentials): Promise<void> {
	await fetch(`${serverUrl}/ocs/v2.php/core/apppassword`, {
		method: 'DELETE',
		credentials: 'omit',
		headers: { Authorization: `Basic ${toBase64(new TextEncoder().encode(`${loginName}:${appPassword}`))}`, 'OCS-APIRequest': 'true' },
	}).catch(() => undefined)
}
