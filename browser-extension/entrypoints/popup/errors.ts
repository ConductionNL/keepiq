import type { ErrorCode } from '@/src/messages'

/** What the user reads for each error code; `host` names the server where it matters. */
export function errorText(code: ErrorCode, host: string, fallback: string): string {
	switch (code) {
		case 'insecure_url': return 'Use https for this server'
		case 'invalid_url': return 'Enter a valid server URL'
		case 'permission_denied': return `Keepiq needs permission to reach ${host}`
		case 'unreachable': return `Could not reach ${host}`
		case 'not_nextcloud': return `${host} does not look like a Nextcloud server`
		case 'server_error': return `${host} is unavailable right now, try again later`
		case 'unauthorized': return 'Wrong username or app password'
		case 'keepiq_missing': return `Keepiq is not installed on ${host}`
		case 'no_active_suite': return 'Open the Keepiq web app once and set a master password, then try again'
		case 'unlock_blocked': return 'Your organisation requires two-factor login. Set up a second factor in Nextcloud Settings, Security, then try again'
		case 'duplicate': return 'This account is already added'
		case 'limit_reached': return 'Maximum of 5 accounts reached'
		case 'invalid_master_password': return 'Invalid master password'
		case 'offline_no_cache': return 'You are offline and this vault has not been synced yet'
		case 'session_revoked': return 'Session revoked, please log in again'
		default: return fallback
	}
}
