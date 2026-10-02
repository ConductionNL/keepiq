/**
 * Keepiq client library for TypeScript and JavaScript (Node 20+, browsers).
 *
 *     import { Client } from '@conduction/keepiq-sdk'
 *     const client = new Client('https://cloud.example.org', 'billing', process.env.KEEPIQ_APP_KEY!)
 *     const secret = await client.getByName('stripe-key')
 */
export { Client } from './client.js'
export type { ClientOptions, Lease, Secret, WriteFields } from './client.js'
export {
	AmbiguousNameError,
	ApiError,
	KeepiqError,
	KeyMismatchError,
	NotFoundError,
	NotModifiedError,
	UnauthorizedError,
} from './errors.js'
export type { Candidate } from './errors.js'
export { SCHEME } from './crypto.js'
