/** Keepiq's send routes (`EphemeralSendController`), since 0.1.4, below the minimum, so ungated. */
import { ApiError, KeepiqNotInstalled, type Client } from '@/src/api/client'
import type { SendPayloadType, SendRow } from '@/src/messages'
import { WriteFailure } from '@/src/vault/write-error'

export interface SendBody {
	encryptedPayload: string
	payloadType: SendPayloadType
	maxViews: number
	ttlSeconds: number
	hasPassword: boolean
	wrappedKey?: string
	argon2idSalt?: string
}

const isString = (value: unknown): value is string => typeof value === 'string'
const isCount = (value: unknown): value is number => Number.isInteger(value) && (value as number) >= 0

function toRow(value: unknown): SendRow | null {
	const row = value as Partial<Record<keyof SendRow, unknown>> | null
	if (typeof row !== 'object' || row === null) return null
	const { id, token, payloadType, hasPassword, maxViews, viewCount, remainingViews, expiresAt, createdAt } = row
	if (!isString(id) || !isString(token) || !token || (payloadType !== 'text' && payloadType !== 'credential') || typeof hasPassword !== 'boolean'
		|| !isCount(maxViews) || !isCount(viewCount) || !isCount(remainingViews) || (expiresAt !== null && !isString(expiresAt)) || !isString(createdAt)) return null
	return { id, token, payloadType, hasPassword, maxViews, viewCount, remainingViews, expiresAt, createdAt }
}

/** Newest first, as the server orders them. A malformed row is left out rather than blanking the list. */
export async function listSends(client: Client): Promise<SendRow[]> {
	const rows = await client.keepiq<unknown>('GET', '/api/v1/sends')
	if (!Array.isArray(rows)) throw new WriteFailure({ code: 'server_error' })
	return rows.flatMap((value) => {
		const row = toRow(value)
		if (!row) console.error('[keepiq] skipping a malformed send row')
		return row ? [row] : []
	})
}

export async function createSend(client: Client, body: SendBody): Promise<SendRow> {
	const row = toRow(await client.keepiq<unknown>('POST', '/api/v1/sends', body))
	if (!row) throw new WriteFailure({ code: 'server_error' })
	return row
}

/** A 404 means it is already gone, burned or expired, which is what the user wanted. */
export async function deleteSend(client: Client, id: string): Promise<void> {
	try {
		await client.keepiq('DELETE', `/api/v1/sends/${encodeURIComponent(id)}`)
	} catch (error) {
		if (error instanceof ApiError && !(error instanceof KeepiqNotInstalled) && error.status === 404) return
		throw error
	}
}
