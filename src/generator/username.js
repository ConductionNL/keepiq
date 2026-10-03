/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Username generator: a random word, a plus-addressed email or a catch-all
 * email, in the browser. Pure module; the random source can be swapped for
 * tests.
 *
 * @spec openspec/changes/clients-extension-complete/specs/extension-generator/spec.md#requirement-username-generator
 */

import { EFF_LARGE_WORDLIST } from './eff-large-wordlist.js'
import { GeneratorError, randomInt } from './generator.js'

const TAG_CHARS = 'abcdefghijklmnopqrstuvwxyz0123456789'
const TAG_LENGTH = 8

/**
 * Eight random lowercase letters and digits.
 *
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function randomTag(rand) {
	let tag = ''
	for (let i = 0; i < TAG_LENGTH; i++) {
		tag += TAG_CHARS[rand(0, TAG_CHARS.length - 1)]
	}
	return tag
}

/**
 * The label for an email: random, or the website's host name.
 *
 * @param {{mode?: string, website?: string}} options The label options.
 * @param {(min: number, max: number) => number} rand The random-integer source.
 * @return {string}
 */
function labelFor({ mode = 'random', website = '' }, rand) {
	if (mode === 'website') {
		const host = String(website).trim().toLowerCase()
		if (host === '') {
			throw new GeneratorError('No website detected')
		}
		return host
	}
	return randomTag(rand)
}

/**
 * One word from the word list, capitalised and followed by four digits by
 * default.
 *
 * @param {object} [options] The options.
 * @param {boolean} [options.capitalize] Capitalise the word (default true).
 * @param {boolean} [options.includeNumber] Append four digits (default true).
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 */
export function randomWordUsername(
	{ capitalize = true, includeNumber = true } = {},
	rand = randomInt,
) {
	let word = EFF_LARGE_WORDLIST[rand(0, EFF_LARGE_WORDLIST.length - 1)]
	if (capitalize) {
		word = word[0].toUpperCase() + word.slice(1)
	}
	return includeNumber ? word + String(rand(0, 9999)).padStart(4, '0') : word
}

/**
 * The email with `+label` before the `@`: `user+k3x9…@example.com`.
 *
 * @param {string} email The base address.
 * @param {{mode?: string, website?: string}} [options] Random or website label.
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 */
export function plusAddressedEmail(email, options = {}, rand = randomInt) {
	const at = String(email).lastIndexOf('@')
	if (at <= 0 || at === email.length - 1) {
		throw new GeneratorError('Enter an email address')
	}
	return `${email.slice(0, at)}+${labelFor(options, rand)}${email.slice(at)}`
}

/**
 * An address at a catch-all domain: `k3x9…@example.com`.
 *
 * @param {string} domain The catch-all domain.
 * @param {{mode?: string, website?: string}} [options] Random or website label.
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 */
export function catchAllEmail(domain, options = {}, rand = randomInt) {
	const clean = String(domain).trim().replace(/^@/, '')
	if (clean === '' || !clean.includes('.')) {
		throw new GeneratorError('Enter a domain')
	}
	return `${labelFor(options, rand)}@${clean}`
}

/**
 * Generate a username of the chosen type.
 *
 * @param {object} options The options.
 * @param {'word'|'plus'|'catchall'} options.type The kind of username.
 * @param {(min: number, max: number) => number} [rand] Random-integer source (tests only).
 * @return {string}
 * @throws {GeneratorError} When the input for the type is missing.
 */
export function generateUsername(options, rand = randomInt) {
	if (options.type === 'plus') {
		return plusAddressedEmail(options.email || '', options, rand)
	}
	if (options.type === 'catchall') {
		return catchAllEmail(options.domain || '', options, rand)
	}
	return randomWordUsername(options, rand)
}
