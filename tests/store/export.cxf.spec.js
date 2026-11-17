/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The CXF file export, the sealed CXP transfer and the GDPR package of
 * `useExportStore`: a dry run reports without reporting or downloading, the
 * export event goes out before anything leaves the browser and a failing
 * event stops it, the sealed CXP envelope opens only for the requester, and
 * the GDPR package carries the vault only when it was unlocked.
 *
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-re-auth-gated-client-side-cxf-export
 * @spec openspec/specs/cxf-import-export/spec.md#requirement-unmapped-item-report
 * @spec openspec/specs/gdpr-compliance/spec.md
 */

import axios from '@nextcloud/axios'
import { createPinia, setActivePinia } from 'pinia'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createImportRequest, openEnvelope } from '../../src/crypto/cxp.js'
import {
	GDPR_PACKAGE_FORMAT,
	VAULT_UNAVAILABLE_REASON,
} from '../../src/export/gdprPackage.js'
import { useExportStore } from '../../src/store/modules/export.js'

const secrets = [
	{
		name: 'AWS',
		key: 'super-secret-key',
		login: 'admin',
		folderId: null,
		typeId: 'login',
	},
]

let downloads
let blobs
let order

/**
 * Read a Blob handed to URL.createObjectURL as text.
 *
 * @param {Blob} blob The blob.
 * @return {Promise<string>}
 */
function blobText(blob) {
	return new Promise((resolve) => {
		const reader = new FileReader()
		reader.onload = () => resolve(reader.result)
		reader.readAsText(blob)
	})
}

beforeEach(() => {
	setActivePinia(createPinia())
	downloads = []
	blobs = []
	order = []
	vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(function () {
		downloads.push(this.download)
		order.push('download')
	})
	global.URL.createObjectURL = (blob) => {
		blobs.push(blob)
		return 'blob:mock'
	}
	global.URL.revokeObjectURL = () => {}
})

afterEach(() => {
	vi.restoreAllMocks()
})

describe('exportCxf', () => {
	it('reports the item count before downloading the CXF document', async () => {
		const post = vi.spyOn(axios, 'post').mockImplementation(async () => {
			order.push('event')
		})
		const store = useExportStore()

		const result = await store.exportCxf(secrets, [], { mode: 'vault' })

		expect(result.itemCount).toBe(1)
		expect(Array.isArray(result.unmapped)).toBe(true)
		expect(order).toEqual(['event', 'download'])
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/export/events', {
			mode: 'cxf',
			scope: 'vault',
			secretCount: 1,
		})
		expect(downloads).toEqual(['vault.cxf'])
		expect(blobs[0].type).toBe('application/json')
		const document = JSON.parse(await blobText(blobs[0]))
		expect(JSON.stringify(document)).toContain('AWS')
		expect(store.loading).toBe(false)
		expect(store.error).toBeNull()
	})

	it('builds the report on a dry run without reporting or downloading', async () => {
		const post = vi.spyOn(axios, 'post')
		const store = useExportStore()

		const result = await store.exportCxf(secrets, [], undefined, {
			dryRun: true,
		})

		expect(result.itemCount).toBe(1)
		expect(post).not.toHaveBeenCalled()
		expect(downloads).toEqual([])
		expect(store.loading).toBe(false)
	})

	it('offers no download when the export event fails, and keeps the error', async () => {
		vi.spyOn(axios, 'post').mockRejectedValue(new Error('event refused'))
		const store = useExportStore()

		await expect(store.exportCxf(secrets, [])).rejects.toThrow('event refused')
		expect(downloads).toEqual([])
		expect(store.error).toBe('event refused')
		expect(store.loading).toBe(false)
	})
})

describe('exportCxpSealed', () => {
	it('seals the CXF payload so only the requester opens it, after reporting', async () => {
		const post = vi.spyOn(axios, 'post').mockResolvedValue({ data: {} })
		const { request, session } = await createImportRequest()
		const store = useExportStore()

		const { envelope, itemCount } = await store.exportCxpSealed(
			request,
			secrets,
			[],
		)

		expect(itemCount).toBe(1)
		expect(post).toHaveBeenCalledWith('/apps/keepiq/api/v1/export/events', {
			mode: 'cxp',
			scope: 'vault',
			secretCount: 1,
		})
		expect(JSON.stringify(envelope)).not.toContain('super-secret-key')
		const opened = new TextDecoder().decode(
			await openEnvelope(session, envelope),
		)
		expect(opened).toContain('super-secret-key')
		expect(downloads).toEqual([])

		const other = await createImportRequest()
		await expect(openEnvelope(other.session, envelope)).rejects.toThrow()
	})

	it('returns no envelope on a dry run and reports nothing', async () => {
		const post = vi.spyOn(axios, 'post')
		const { request } = await createImportRequest()
		const store = useExportStore()

		const result = await store.exportCxpSealed(
			request,
			secrets,
			[],
			{ mode: 'vault' },
			{ dryRun: true },
		)

		expect(result.envelope).toBeNull()
		expect(result.itemCount).toBe(1)
		expect(post).not.toHaveBeenCalled()
	})

	it('refuses a request of another version and keeps the error', async () => {
		const post = vi.spyOn(axios, 'post')
		const { request } = await createImportRequest()
		const store = useExportStore()

		await expect(
			store.exportCxpSealed({ ...request, v: 'other' }, secrets, []),
		).rejects.toThrow(/unsupported request version/)
		expect(post).not.toHaveBeenCalled()
		expect(store.error).toMatch(/unsupported request version/)
		expect(store.loading).toBe(false)
	})
})

describe('exportGdprPackage', () => {
	it('merges the unlocked vault with the server metadata', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { user: 'alice' } })
		const store = useExportStore()

		await store.exportGdprPackage(secrets, [])

		expect(get).toHaveBeenCalledWith('/apps/keepiq/api/v1/gdpr/metadata', {
			params: { includesVault: 'true' },
		})
		expect(downloads).toEqual(['keepiq-gdpr-export.json'])
		const pkg = JSON.parse(await blobText(blobs[0]))
		expect(pkg.format).toBe(GDPR_PACKAGE_FORMAT)
		expect(pkg.includesVault).toBe(true)
		expect(pkg.metadata).toEqual({ user: 'alice' })
		expect(pkg.vault.available).toBe(true)
		expect(pkg.vault.secrets).toHaveLength(1)
		expect(pkg.vault.secrets[0].name).toBe('AWS')
	})

	it('ships only the metadata while the vault is locked', async () => {
		const get = vi
			.spyOn(axios, 'get')
			.mockResolvedValue({ data: { user: 'alice' } })
		const store = useExportStore()

		await store.exportGdprPackage(null, [])

		expect(get.mock.calls[0][1]).toEqual({
			params: { includesVault: 'false' },
		})
		const pkg = JSON.parse(await blobText(blobs[0]))
		expect(pkg.includesVault).toBe(false)
		expect(pkg.vault).toEqual({
			available: false,
			unavailable: VAULT_UNAVAILABLE_REASON,
		})
	})

	it('downloads nothing when the metadata request fails', async () => {
		vi.spyOn(axios, 'get').mockRejectedValue(new Error('server down'))
		const store = useExportStore()

		await expect(store.exportGdprPackage(secrets, [])).rejects.toThrow(
			'server down',
		)
		expect(downloads).toEqual([])
		expect(store.error).toBe('server down')
		expect(store.loading).toBe(false)
	})
})
