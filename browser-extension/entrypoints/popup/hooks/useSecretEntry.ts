import { useRef, useState } from 'react'
import { i18n } from '#i18n'
import type { AccountSummary, ErrorCode, UnlockMethod } from '@/src/messages'
import { errorText } from '../errors'

/** A reply to an unlock with the entered secret; `undefined` when the background did not answer. */
type SecretReply = { ok: true } | { ok: false; code: string; attemptsLeft?: number } | undefined

/** The PIN or master password a form unlocks with, as the Unlock view and "Confirm it's you" ask for it. */
export function useSecretEntry(account: AccountSummary) {
	const [secret, setSecret] = useState('')
	const [switched, setSwitched] = useState(false)
	// The PIN comes first while one is set; a forgotten one leaves only the master password.
	const withPin = account.pin && !switched
	// Only the sealed PIN is left: this master password rebuilds the session copy (ADR-002).
	const afterRestart = account.pinAfterRestart && !account.pin
	const [error, setError] = useState<string | null>(null)
	const [busy, setBusy] = useState(false)
	const field = useRef<HTMLInputElement>(null)

	function switchToMasterPassword() {
		setError(null)
		setSecret('')
		setSwitched(true)
	}

	async function submit(unlock: (method: UnlockMethod) => Promise<SecretReply>): Promise<void> {
		setBusy(true)
		setError(null)
		const reply = await unlock(withPin ? { type: 'pin', pin: secret } : { type: 'masterPassword', masterPassword: secret })
		setBusy(false)
		// Accepted or not, the secret leaves React state.
		setSecret('')
		if (reply?.ok) return
		// A code that is no `ErrorCode` reads as "Something went wrong".
		setError(reply ? errorText(reply.code as ErrorCode, account.host, reply.attemptsLeft) : i18n.t('errors.notResponding'))
		if (reply?.code === 'pin_forgotten' || reply?.code === 'pin_key_changed') setSwitched(true)
		else field.current?.focus()
	}

	return { secret, setSecret, withPin, afterRestart, error, busy, field, switched, switchToMasterPassword, submit }
}
