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

type RawSendRow = Partial<Record<keyof SendRow, unknown>>
type SendRowFields = Pick<SendRow, 'id' | 'token' | 'payloadType' | 'hasPassword' | 'maxViews' | 'viewCount' | 'remainingViews' | 'expiresAt' | 'createdAt'>

const isPayloadType = (value: unknown): value is SendPayloadType => value === 'text' || value === 'credential'

/** The id, the link's token, the type and whether a password guards it. */
const isIdentified = (row: RawSendRow) => isString(row.id) && isString(row.token) && !!row.token && isPayloadType(row.payloadType) && typeof row.hasPassword === 'boolean'

const isCounted = (row: RawSendRow) => isCount(row.maxViews) && isCount(row.viewCount) && isCount(row.remainingViews)

const isDated = (row: RawSendRow) => (row.expiresAt === null || isString(row.expiresAt)) && isString(row.createdAt)

const isSendRow = (row: RawSendRow): row is SendRowFields => isIdentified(row) && isCounted(row) && isDated(row)

function toRow(value: unknown): SendRow | null {
	const row = value as RawSendRow | null
	if (typeof row !== 'object' || row === null || !isSendRow(row)) return null
	const { id, token, payloadType, hasPassword, maxViews, viewCount, remainingViews, expiresAt, createdAt } = row
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
