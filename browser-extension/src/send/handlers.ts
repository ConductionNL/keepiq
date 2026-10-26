/** The `send.*` requests. Nothing about a send outlives its reply: its content, key and link are never kept or stored. */
import { getActiveAccountId } from '@/src/accounts/store'
import type { SendCreateInput, SendRow, WriteResult } from '@/src/messages'
import { hasKey, sessionEpoch } from '@/src/vault/key-store'
import { run } from '@/src/vault/write'
import { WriteFailure } from '@/src/vault/write-error'
import { createSend, deleteSend, listSends, type SendBody } from './api'
import { isArgon2Available } from './argon2'
import { sealPayload, wrapContentKey } from './crypto'
import { isValidMaxViews, MIN_TTL_SECONDS, TTL_CAP_SECONDS } from './expiry'
import { buildShareLink } from './link'

/** A password send's link holds no secret, so it can be rebuilt any time. */
const withLink = (row: SendRow, serverUrl: string): SendRow => row.hasPassword ? { ...row, link: buildShareLink(serverUrl, row.token, null) } : row

/** A credential body is `serializeCredential`'s; one without a password is not worth sending. */
const PASSWORD_LINE = '\nPassword: '
const hasPassword = (body: string) => body.includes(PASSWORD_LINE) && body.slice(body.lastIndexOf(PASSWORD_LINE) + PASSWORD_LINE.length).trim() !== ''

function isCreateInput(input: unknown): input is SendCreateInput {
	const i = input as Partial<SendCreateInput> | null
	return (i?.payloadType === 'text' || i?.payloadType === 'credential')
		&& typeof i.content === 'string' && i.content.trim() !== ''
		&& (i.payloadType === 'text' || hasPassword(i.content))
		&& isValidMaxViews(i.maxViews as number)
		&& Number.isInteger(i.ttlSeconds) && i.ttlSeconds! >= MIN_TTL_SECONDS && i.ttlSeconds! <= TTL_CAP_SECONDS
		&& (i.password === undefined || typeof i.password === 'string')
}

/** Argon2id takes about a second; a lock, logout or account switch meanwhile must not still send. */
async function assertStillActive(accountId: string, epoch: number): Promise<void> {
	if (sessionEpoch(accountId) !== epoch || !(await hasKey(accountId))) throw new WriteFailure({ code: 'locked' })
	if (await getActiveAccountId() !== accountId) throw new WriteFailure({ code: 'account_changed' })
}

/** A derivation that fails after the probe passed, as when 64 MiB can't be had, says nothing about the server. */
async function wrapped(rawKey: Uint8Array<ArrayBuffer>, password: string) {
	try {
		return await wrapContentKey(rawKey, password)
	} catch (error) {
		console.error('[keepiq] Argon2id failed', error)
		throw new WriteFailure({ code: 'argon2_unavailable' })
	}
}

export function listSendsFor(accountId: string): Promise<WriteResult<{ sends: SendRow[]; passwordProtection: boolean }>> {
	return run(async ({ account, client }) => ({
		sends: (await listSends(client)).map((row) => withLink(row, account.serverUrl)),
		passwordProtection: await isArgon2Available(),
	}), 'reply', accountId)
}

export function createSendFor(input: SendCreateInput, accountId: string): Promise<WriteResult<{ row: SendRow; link: string }>> {
	// Read before any await, so a lock while this runs is seen.
	const epoch = sessionEpoch(accountId)
	return run(async ({ account, client }) => {
		if (!isCreateInput(input)) throw new WriteFailure({ code: 'invalid' })
		const password = input.password || null
		if (password && !(await isArgon2Available())) throw new WriteFailure({ code: 'argon2_unavailable' })
		const { encryptedPayload, rawKey } = await sealPayload(input.content)
		try {
			const body: SendBody = { encryptedPayload, payloadType: input.payloadType, maxViews: input.maxViews, ttlSeconds: input.ttlSeconds, hasPassword: password !== null }
			if (password) Object.assign(body, await wrapped(rawKey, password))
			await assertStillActive(account.id, epoch)
			const row = await createSend(client, body)
			return { row: withLink(row, account.serverUrl), link: buildShareLink(account.serverUrl, row.token, password ? null : rawKey) }
		} finally {
			rawKey.fill(0)
		}
	}, 'reply', accountId)
}

export function deleteSendFor(id: string, accountId: string): Promise<WriteResult<null>> {
	return run(async ({ client }) => {
		if (typeof id !== 'string' || !id) throw new WriteFailure({ code: 'invalid' })
		await deleteSend(client, id)
		return null
	}, 'reply', accountId)
}
