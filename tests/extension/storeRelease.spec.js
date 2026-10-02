/**
 * @spec openspec/specs/extension-store-release/spec.md
 *
 * The store packages (keepiq#783): one build per browser with the manifest
 * each store needs and the version from the release tag, two builds of the
 * same source byte-identical, and no permission without a call site.
 */
import { execFileSync } from 'node:child_process'
import { createHash } from 'node:crypto'
import { mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join, relative, resolve } from 'node:path'
import { afterAll, describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../..')
const SRC = join(ROOT, 'browser-extension/src')
const temps = []

function build(target, version) {
	const out = mkdtempSync(join(tmpdir(), 'keepiq-ext-'))
	temps.push(out)
	execFileSync(
		process.execPath,
		['browser-extension/build.mjs', '--target', target, '--outdir', out],
		{
			cwd: ROOT,
			env: { ...process.env, EXTENSION_VERSION: version },
			stdio: 'pipe',
		},
	)
	return out
}

function files(dir) {
	return readdirSync(dir).flatMap((name) => {
		const path = join(dir, name)
		return statSync(path).isDirectory() ? files(path) : [path]
	})
}

function treeHash(dir) {
	const hash = createHash('sha256')
	for (const path of files(dir).sort()) {
		hash.update(relative(dir, path))
		hash.update(readFileSync(path))
	}
	return hash.digest('hex')
}

afterAll(() => {
	for (const dir of temps) rmSync(dir, { recursive: true, force: true })
})

describe('per-store builds', () => {
	it('writes the Chrome and Firefox manifests with the tag version', () => {
		const chrome = build('chrome', '1.2.3')
		const firefox = build('firefox', '1.2.3')
		const cm = JSON.parse(
			readFileSync(join(chrome, 'chromium/manifest.json'), 'utf8'),
		)
		const fm = JSON.parse(
			readFileSync(join(firefox, 'firefox/manifest.json'), 'utf8'),
		)

		expect(cm.background.service_worker).toBe('service-worker.js')
		expect(cm.version).toBe('1.2.3')
		expect(fm.browser_specific_settings.gecko.id).toBe('keepiq@conduction.nl')
		expect(fm.background.scripts).toEqual(['service-worker.js'])
		expect(fm.version).toBe('1.2.3')
		expect(readdirSync(chrome)).toEqual(['chromium'])
	})

	it('refuses a version a store would reject', () => {
		expect(() => build('chrome', '1.2.3-beta')).toThrow()
	})

	it('builds byte-identical packages from the same source', () => {
		const a = build('firefox', '1.0.0')
		const b = build('firefox', '1.0.0')
		expect(treeHash(join(a, 'firefox'))).toBe(treeHash(join(b, 'firefox')))
	})
})

describe('least permissions', () => {
	// Each permission and the code that needs it. A permission missing here,
	// or one whose call site is gone, fails the suite.
	const CALL_SITES = {
		storage: /chrome\.storage\./,
		activeTab: /chrome\.tabs\.(query|sendMessage)\(/,
		tabs: /chrome\.tabs\./,
		clipboardWrite: /navigator\.clipboard\.writeText/,
		idle: /chrome\.idle\./,
		windows: /chrome\.windows\./,
	}
	const source = files(SRC)
		.filter((p) => p.endsWith('.js'))
		.map((p) => readFileSync(p, 'utf8'))
		.join('\n')
	const manifest = JSON.parse(
		readFileSync(join(ROOT, 'browser-extension/manifest.json'), 'utf8'),
	)

	it.each(manifest.permissions)('%s is used by extension code', (permission) => {
		expect(
			CALL_SITES[permission],
			`no known call site for ${permission}`,
		).toBeDefined()
		expect(source).toMatch(CALL_SITES[permission])
	})

	it('no longer requests scripting, which nothing calls', () => {
		expect(manifest.permissions).not.toContain('scripting')
		expect(source).not.toMatch(/chrome\.scripting/)
	})

	it('describes itself without a dash or a capitalised phrase', () => {
		expect(manifest.description).not.toMatch(/[—–]/)
		expect(manifest.description.length).toBeLessThanOrEqual(132)
	})
})

describe('the Firefox Add-ons signed file is attached after review', () => {
	const workflow = readFileSync(
		join(ROOT, '.github/workflows/extension-release.yml'),
		'utf8',
	)
	const jobStart = workflow.indexOf('\n  attach-amo-signed:\n')
	const job = jobStart === -1 ? '' : workflow.slice(jobStart)
	const buildJob = workflow.slice(
		workflow.indexOf('\n  build:\n'),
		workflow.indexOf('\n  publish:\n'),
	)

	it('has a follow-up job that runs daily and on demand', () => {
		expect(job, 'no attach-amo-signed job in extension-release.yml').not.toBe('')
		expect(workflow).toMatch(/\n {2}schedule:\n/)
		expect(workflow).toMatch(/\n {6}amo_version:\n/)
		expect(job).toMatch(/github\.event_name == 'schedule'/)
		expect(buildJob).toMatch(/github\.event_name != 'schedule'/)
	})

	it('reads only public store data, so it needs no secret and no approval', () => {
		expect(job).not.toMatch(/secrets\./)
		expect(job).not.toMatch(/\n {4}environment:/)
		expect(job).toMatch(
			/https:\/\/addons\.mozilla\.org\/api\/v5\/addons\/addon\//,
		)
	})

	it('asks Firefox Add-ons about the add-on id the Firefox manifest carries', async () => {
		const { GECKO_ID } =
			await import('../../browser-extension/manifests/browsers.mjs')
		expect(job).toContain(`GECKO_ID: ${GECKO_ID}\n`)
	})

	it('attaches the file only after checking its hash and its contents', () => {
		expect(job).toMatch(/\.file\.hash/)
		expect(job).toMatch(/META-INF\/mozilla\.rsa/)
		expect(job).toMatch(/diff -u built\.sha signed\.sha/)
		expect(job.indexOf('diff -u built.sha signed.sha')).toBeLessThan(
			job.indexOf('gh release upload'),
		)
	})
})
