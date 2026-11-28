import { afterEach, describe, expect, it, vi } from 'vitest'
import { vectors } from '@/src/testing/vectors'
import { argon2idHash, deriveSendKey, isArgon2Available } from './argon2'

const { argon2idKnownAnswer: known } = vectors('send')
const hex = (bytes: Uint8Array) => Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('')
const fromHex = (text: string) => Uint8Array.from(text.match(/../g)!, (pair) => parseInt(pair, 16))

afterEach(() => {
	vi.unstubAllGlobals()
	vi.resetModules()
})

describe('argon2', () => {
	it('gives the web app\'s Argon2id output for its parameters', async () => {
		expect({ memoryKiB: known.memoryKiB, iterations: known.iterations, parallelism: known.parallelism }).toEqual({ memoryKiB: 65536, iterations: 3, parallelism: 1 })
		expect(hex(await argon2idHash(known.password, fromHex(known.saltHex)))).toBe(known.keyHex)
	}, 30_000)

	it('derives a non-extractable AES-GCM key that only wraps', async () => {
		const key = await deriveSendKey('password', fromHex(known.saltHex))
		expect(key.algorithm).toMatchObject({ name: 'AES-GCM', length: 256 })
		expect(key.extractable).toBe(false)
		expect(key.usages).toEqual(['encrypt'])
	}, 30_000)

	it('is available where WebAssembly compiles', async () => {
		expect(await isArgon2Available()).toBe(true)
	})

	it('is unavailable where compiling is refused, as under a CSP without wasm-unsafe-eval', async () => {
		vi.stubGlobal('WebAssembly', { ...WebAssembly, compile: () => Promise.reject(new WebAssembly.CompileError('blocked by CSP')) })
		vi.resetModules()
		const fresh = await import('./argon2')
		expect(await fresh.isArgon2Available()).toBe(false)
	})

	it('is unavailable without WebAssembly at all', async () => {
		vi.stubGlobal('WebAssembly', undefined)
		vi.resetModules()
		const fresh = await import('./argon2')
		expect(await fresh.isArgon2Available()).toBe(false)
	})
})
