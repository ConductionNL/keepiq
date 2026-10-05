/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Writes tests/vectors/autofill/cases.json: what the browser extension's
 * site matching (browser-extension/src/lib/match.js), use-only rules
 * (useOnly.js) and save classifier (capture.js) answer for fixed inputs.
 * The native apps' system autofill must answer the same
 * (mobile-system-autofill), so the Kotlin core runs this file in commonTest
 * (AutofillVectorsTest) and tests/vitest/autofill-vectors.spec.js re-checks
 * the extension against it.
 *
 * Usage, from the repository root:
 *
 *   node tests/vectors/generate-autofill-vectors.mjs
 *
 * @spec openspec/changes/clients-mobile-apps/specs/mobile-system-autofill/spec.md#requirement-keepiq-as-the-autofill-service-on-android
 */

import { writeFileSync } from 'node:fs'
import { dirname, join } from 'node:path'
import { fileURLToPath } from 'node:url'
import { classifyCapture } from '../../browser-extension/src/lib/capture.js'
import {
	hostOf,
	isPublicSuffix,
	matchScore,
	matchSecrets,
	registrableDomain,
} from '../../browser-extension/src/lib/match.js'
import {
	blocksSavePrompt,
	filterForHost,
} from '../../browser-extension/src/lib/useOnly.js'

const HERE = dirname(fileURLToPath(import.meta.url))

const HOSTS = [
	'',
	'example.com',
	'EXAMPLE.com',
	'  example.com  ',
	'https://example.com',
	'https://Login.Example.com:8443/path?q=1#top',
	'http://user:pass@example.com/',
	'https://example.com.',
	'www.bank.co.uk',
	'https://a.b.gov.nl/x',
	'gov.nl',
	'co.uk',
	'localhost',
	'https://10.0.2.2:8443',
	'10.0.2.2',
	'https://127.1',
	'https://0x7f.0.0.1/',
	'https://example.123',
	'https://[::1]:8080/',
	'https://bücher.de/',
	'https://ÉCOLE.fr',
	'androidapp://com.example.bank',
	'androidapp://com.example.bank#sha256_cert_fingerprints=AA:BB',
	'android-app://com.example.bank',
	'ftp://files.example.org',
	'mailto:alice@example.com',
	'https://exa mple.com',
	'https://example.com:99999',
	'https://example.com:80a',
	'https://%65xample.com',
	'https://',
	'not a url at all',
	'https://example.com\\path',
	'HTTPS://EXAMPLE.COM',
	'sub.domain.example.co.nz',
	'https://xn--bcher-kva.de',
]

const ITEMS = [
	{ id: 'a', name: 'Example', url: 'https://example.com' },
	{
		id: 'b',
		name: 'Example login',
		url: 'https://login.example.com',
		lastUsedAt: '2026-10-01T10:00:00Z',
	},
	{
		id: 'c',
		name: 'Mail',
		url: 'https://mail.example.com',
		lastUsedAt: '2026-10-03T10:00:00Z',
	},
	{ id: 'd', name: 'example.com backup', url: '' },
	{ id: 'e', name: 'My examples', url: null },
	{ id: 'f', name: 'Bank', url: 'https://www.bank.co.uk' },
	{ id: 'g', name: 'Other', url: 'https://other.org' },
	{ id: 'h', name: 'Shared', url: 'https://example.com', useOnly: true },
	{ id: 'i', name: 'Shared elsewhere', url: 'https://other.org', useOnly: true },
	{ id: 'j', name: 'App', url: 'androidapp://com.example.bank' },
	{ id: 'k', name: 'Test server', url: 'https://10.0.2.2:8443' },
]

const TARGETS = [
	'example.com',
	'login.example.com',
	'https://www.example.com/sign-in',
	'bank.co.uk',
	'online.bank.co.uk',
	'other.org',
	'10.0.2.2',
	'ex.com',
	'',
	'com.example.bank',
]

const hosts = HOSTS.map((input) => ({
	input,
	host: hostOf(input),
	registrable: registrableDomain(input),
	publicSuffix: isPublicSuffix(input),
}))

const scores = []
for (const item of ITEMS) {
	for (const target of TARGETS) {
		scores.push({
			name: item.name,
			url: item.url,
			target,
			score: matchScore(item, target),
		})
	}
}

const matches = TARGETS.map((target) => {
	const ranked = matchSecrets(ITEMS, target)
	return {
		target,
		ids: ranked.map((r) => r.id),
		filtered: filterForHost(ranked, target).map((r) => r.id),
		blocksSave: blocksSavePrompt(ITEMS, target),
	}
})

// Stored logins with their decrypted values, and submitted logins.
const STORED = [
	{
		id: 's1',
		name: 'Example',
		url: 'https://example.com',
		plain: { login: 'alice', secret: 'one' },
	},
	{
		id: 's2',
		name: 'Example two',
		url: 'https://www.example.com',
		plain: { login: 'bob', secret: 'two' },
	},
	{
		id: 's3',
		name: 'example.com by name',
		url: '',
		plain: { login: 'carol', secret: 'three' },
	},
	{ id: 's4', name: 'Broken', url: 'https://example.com', plain: null },
	{
		id: 's5',
		name: 'Dup one',
		url: 'https://dup.example.org',
		plain: { login: 'dave', secret: 'x' },
	},
	{
		id: 's6',
		name: 'Dup two',
		url: 'https://dup.example.org',
		plain: { login: 'dave', secret: 'y' },
	},
	{
		id: 's7',
		name: 'Empty login',
		url: 'https://empty.example.net',
		plain: { secret: 'z' },
	},
]
const SUBMITS = [
	{ host: 'example.com', login: 'alice', secret: 'one' },
	{ host: 'example.com', login: 'alice', secret: 'changed' },
	{ host: 'login.example.com', login: 'bob', secret: 'new' },
	{ host: 'example.com', login: 'carol', secret: 'three' },
	{ host: 'example.com', login: 'erin', secret: 'e' },
	{ host: 'dup.example.org', login: 'dave', secret: 'new' },
	{ host: 'dup.example.org', login: 'dave', secret: 'x' },
	{ host: 'empty.example.net', login: '', secret: 'other' },
	{ host: 'unknown.test', login: 'alice', secret: 'one' },
]
async function decrypt(row) {
	const found = STORED.find((s) => s.id === row.id)
	if (!found.plain) throw new Error('cannot open')
	return found.plain
}
const classify = []
for (const submit of SUBMITS) {
	const offer = await classifyCapture(submit, STORED, decrypt)
	classify.push({
		...submit,
		action: offer.action,
		id: offer.action === 'update' ? offer.id : null,
	})
}

const out = {
	comment:
		'Written by tests/vectors/generate-autofill-vectors.mjs from the browser extension. Do not edit.',
	hosts,
	items: ITEMS,
	scores,
	matches,
	stored: STORED,
	classify,
}
writeFileSync(
	join(HERE, 'autofill', 'cases.json'),
	JSON.stringify(out, null, '\t') + '\n',
)
console.log(
	`autofill cases: ${hosts.length} hosts, ${scores.length} scores, ${matches.length} matches, ${classify.length} saves`,
)
