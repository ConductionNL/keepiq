/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden test for parseOtpauth in src/totp/totp.js: bare base32 seeds and
 * otpauth URIs, valid and invalid, each recorded as the parsed parameters or
 * the error it throws, compared with fixtures/parseOtpauth.golden.json.
 *
 * Each case is its own test. Set RECORD_GOLDEN=1 to rewrite the fixture.
 *
 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
 */

import fs from 'node:fs'
import path from 'node:path'
import { describe, expect, it } from 'vitest'
import { parseOtpauth } from '../../src/totp/totp.js'

const GOLDEN = path.resolve(__dirname, 'fixtures/parseOtpauth.golden.json')

const KEY = 'JBSWY3DPEHPK3PXP'
const URI = 'otpauth://totp/'

const INPUTS = {
	null: null,
	undefined,
	number: 12345,
	empty: '',
	blank: '   \n ',
	'bare key': KEY,
	'bare key, lower case with spaces': ' jbsw y3dp ehpk 3pxp ',
	'bare key, padded': 'JBSWY3DPEHPK3PXP====',
	'bare key, invalid character': 'JBSWY3DPEHPK3PX1',
	'bare key, one character': 'A',
	'scheme only': 'otpauth://',
	'upper-case scheme': `OTPAUTH://TOTP/Acme:alice?secret=${KEY}`,
	'hotp uri': `otpauth://hotp/Acme:alice?secret=${KEY}&counter=1`,
	'no secret': `${URI}Acme:alice?issuer=Acme`,
	'empty secret': `${URI}Acme:alice?secret=`,
	'invalid secret': `${URI}Acme:alice?secret=NOT-BASE32`,
	'secret with spaces, lower case': `${URI}Acme:alice?secret=jbsw%20y3dp%20ehpk%203pxp`,
	'label issuer and account': `${URI}Acme:alice@example.org?secret=${KEY}`,
	'issuer parameter wins': `${URI}Label:alice?secret=${KEY}&issuer=Param`,
	'blank issuer parameter': `${URI}Label:alice?secret=${KEY}&issuer=`,
	'issuer parameter, no colon': `${URI}alice?secret=${KEY}&issuer=Acme`,
	'account only': `${URI}alice?secret=${KEY}`,
	'no label': `${URI}?secret=${KEY}`,
	'no label, no slash': `otpauth://totp?secret=${KEY}`,
	'colon only': `${URI}:?secret=${KEY}`,
	'blank issuer in label': `${URI}%20:alice?secret=${KEY}`,
	'blank account in label': `${URI}Acme:%20?secret=${KEY}`,
	'several colons': `${URI}Acme:team:alice?secret=${KEY}`,
	'encoded label': `${URI}Acme%20Corp%3Aalice%20doe?secret=${KEY}`,
	'bad percent encoding': `${URI}Acme%E0%A4%A:alice?secret=${KEY}`,
	'sha256 eight digits sixty seconds': `${URI}A:b?secret=${KEY}&algorithm=SHA256&digits=8&period=60`,
	'sha512 lower case': `${URI}A:b?secret=${KEY}&algorithm=sha512`,
	'sha1 explicit': `${URI}A:b?secret=${KEY}&algorithm=SHA1`,
	'unknown algorithm': `${URI}A:b?secret=${KEY}&algorithm=MD5`,
	'seven digits': `${URI}A:b?secret=${KEY}&digits=7`,
	'digits not a number': `${URI}A:b?secret=${KEY}&digits=x`,
	'ten digits': `${URI}A:b?secret=${KEY}&digits=10`,
	'zero period': `${URI}A:b?secret=${KEY}&period=0`,
	'negative period': `${URI}A:b?secret=${KEY}&period=-30`,
	'period not a number': `${URI}A:b?secret=${KEY}&period=soon`,
	'fractional period': `${URI}A:b?secret=${KEY}&period=30.5`,
	'empty parameters': `${URI}A:b?secret=${KEY}&algorithm=&digits=&period=`,
	'surrounding whitespace': `  ${URI}A:b?secret=${KEY}  `,
	'not a url': 'otpauth://totp/%%%?secret=',
	'host with port': `otpauth://totp:99/A:b?secret=${KEY}`,
	'blank issuer parameter, no colon': `${URI}alice?secret=${KEY}&issuer=`,
	'broken host': 'otpauth://[::1/A:b?secret=JBSWY3DPEHPK3PXP',
}

/**
 * The parsed parameters, or the error parseOtpauth throws.
 *
 * @param {*} input The seed value.
 * @return {object} The outcome.
 */
function outcome(input) {
	try {
		return { parsed: parseOtpauth(input) }
	} catch (error) {
		return { error: `${error.name}: ${error.message}` }
	}
}

const RECORD = process.env.RECORD_GOLDEN === '1'
const recorded = RECORD ? {} : JSON.parse(fs.readFileSync(GOLDEN, 'utf8'))

describe('parseOtpauth (golden)', () => {
	it.each(Object.keys(INPUTS))('%s', (name) => {
		const actual = outcome(INPUTS[name])
		if (RECORD) {
			recorded[name] = actual
			fs.writeFileSync(GOLDEN, JSON.stringify(recorded, null, '\t') + '\n')
		}
		expect(actual).toEqual(recorded[name])
	})
})
