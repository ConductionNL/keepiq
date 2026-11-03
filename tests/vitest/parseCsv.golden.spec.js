/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for parseCsv in src/export/csv.js: hand-picked RFC 4180 edge
 * cases plus seeded random documents over the characters the tokenizer
 * branches on, compared with fixtures/parseCsv.golden.json. Rows are
 * recorded as [column, value] pairs, so empty column names stay valid JSON
 * keys for the linter and the column order is pinned too.
 *
 * Each group is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/specs/secret-export/spec.md
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { parseCsv } from '../../src/export/csv.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/parseCsv.golden.json')

const EDGE = [
	'',
	'a',
	'a,b',
	'a,b\n',
	'a,b\n1,2',
	'a,b\r\n1,2\r\n',
	'a,b\r1,2\r',
	'a,b\n\n1,2\n\n',
	'a,b\n,\n',
	'a,b\n1\n',
	'a,b\n1,2,3\n',
	'a\n""\n',
	'a,b\n"x, y","say ""hi"""\n',
	'a,b\n"multi\nline","cr\r\nlf"\n',
	'a,b\n"unterminated,1\n2',
	'a,b\nx"y,z\n',
	'a,b\n"q"tail,z\n',
	'"",""\n1,2',
	'a,a\n1,2\n',
	'a,b\n\r\n1,2',
	'\n\n\n',
	'"a\nb"',
	'a,b\n" ",x\n',
	'a,b\n1,2\r',
	'a,b\n""""\n',
]

/**
 * A seeded generator, so the random documents are the same on every run.
 *
 * @param {number} seed The seed.
 * @return {Function} Returns the next number in [0, 1).
 */
function mulberry32(seed) {
	let a = seed
	return () => {
		a = (a + 0x6d2b79f5) | 0
		let t = Math.imul(a ^ (a >>> 15), 1 | a)
		t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t
		return ((t ^ (t >>> 14)) >>> 0) / 4294967296
	}
}

const ALPHABET = ['a', 'b', '"', ',', '\r', '\n', ' ', 'é']

/**
 * One group of random documents.
 *
 * @param {number} seed The group's seed.
 * @return {string[]} 100 documents of 0 to 24 characters.
 */
function randomDocs(seed) {
	const next = mulberry32(seed)
	const docs = []
	for (let n = 0; n < 100; n++) {
		const length = Math.floor(next() * 25)
		let doc = ''
		for (let k = 0; k < length; k++) {
			doc += ALPHABET[Math.floor(next() * ALPHABET.length)]
		}
		docs.push(doc)
	}
	return docs
}

const GROUPS = { edge: EDGE }
for (let seed = 1; seed <= 5; seed++) {
	GROUPS[`random ${seed}`] = randomDocs(seed)
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('parseCsv (golden)', () => {
	it.each(Object.keys(GROUPS))('%s', (name) => {
		const actual = GROUPS[name].map((doc) => ({
			doc,
			rows: parseCsv(doc).map((row) => Object.entries(row)),
		}))
		if (RECORD) {
			recorded[name] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
