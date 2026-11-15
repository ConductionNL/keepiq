import { useEffect, useState } from 'react'
import { i18n } from '#i18n'
import { InvalidMasterPassword } from '@/src/crypto'
import type { ErrorCode, VaultPasskeyReply, VaultPasskeyRequest } from '@/src/messages'
import { passkeySupport, type PasskeySupport } from '@/src/vault/passkey-support'
import { PrfUnavailable, type CeremonyDeps } from '@/src/vault/passkey-unlock'
import { errorText } from './errors'

export type VaultPasskeyMode = 'enrol' | 'unlock'

/** The ceremony runs in its own window: Firefox closes the popup while the system prompt is up. */
export async function openVaultPasskeyWindow(accountId: string, mode: VaultPasskeyMode): Promise<void> {
	const query = new URLSearchParams({ vaultPasskey: mode, account: accountId })
	await browser.windows.create({ type: 'popup', width: 380, height: 630, url: browser.runtime.getURL(`/popup.html?${query}`) })
}

/** The background refused, with the code it named. */
export class Refused extends Error {
	constructor(readonly code: ErrorCode) {
		super(code)
	}
}

async function ask<K extends VaultPasskeyRequest['kind']>(message: Extract<VaultPasskeyRequest, { kind: K }>) {
	let reply: VaultPasskeyReply<K> | undefined
	try {
		reply = await browser.runtime.sendMessage(message) as VaultPasskeyReply<K> | undefined
	} catch {
		reply = undefined
	}
	if (!reply) throw new Refused('not_responding')
	if (!reply.ok) throw new Refused(reply.code)
	return reply.value
}

export function ceremonyDeps(accountId: string): CeremonyDeps {
	return {
		credentials: navigator.credentials,
		enrolOptions: () => ask({ kind: 'vaultPasskey.enrolOptions', accountId }),
		enrol: async (body) => {
			await ask({ kind: 'vaultPasskey.enrol', accountId, body })
		},
		unlockOptions: () => ask({ kind: 'vaultPasskey.unlockOptions', accountId }),
		release: (assertion) => ask({ kind: 'vaultPasskey.release', accountId, assertion }),
	}
}

/** What the window says after a ceremony failed; a cancelled or refused prompt reads as the passkey failing. */
export function ceremonyErrorText(error: unknown, host: string): string {
	if (error instanceof Refused) return errorText(error.code, host)
	if (error instanceof InvalidMasterPassword) return errorText('invalid_master_password', host)
	if (error instanceof PrfUnavailable) return i18n.t('vaultPasskey.unsupported')
	return errorText('passkey_failed', host)
}

export function supportText(support: PasskeySupport): string | null {
	if (support === 'browser_too_old') return i18n.t('vaultPasskey.tooOld')
	if (support === 'unsupported') return i18n.t('vaultPasskey.unsupported')
	return null
}

/** `null` while the browser is still answering. */
export function usePasskeySupport(): PasskeySupport | null {
	const [support, setSupport] = useState<PasskeySupport | null>(null)
	useEffect(() => {
		let current = true
		void passkeySupport().catch((): PasskeySupport => 'unsupported').then((value) => {
			if (current) setSupport(value)
		})
		return () => {
			current = false
		}
	}, [])
	return support
}
