/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Pure client-side health-analysis engine.
 *
 * Given decrypted secret rows (already in browser memory after RSA decrypt),
 * computes per-secret findings (weak / reused / stale / possibly-compromised)
 * and a weighted vault health score. zxcvbn strength scoring runs only on
 * password-bearing values; reuse detection runs over SHA-256 digests of ALL
 * values (so duplicated API keys are caught too). Nothing computed here is
 * persisted or transmitted — the caller (the worker / store) drops the plaintext
 * rows after the pass and discards all state on lock (password-health design
 * D2/D3/D6).
 *
 * @spec openspec/changes/password-health/specs/password-health/spec.md#requirement-strength-scoring-and-badges
 */

import zxcvbn from 'zxcvbn'
import { isPasswordBearing } from './classify.js'

/**
 * zxcvbn score at or below which a password is flagged weak.
 *
 * @type {number}
 */
export const WEAK_SCORE_THRESHOLD = 2

/**
 * Finding-category weights for the vault health score (password-health design
 * D7). A higher weight means the finding hurts the score more.
 *
 * @type {Object<string, number>}
 */
export const FINDING_WEIGHTS = {
	breached: 1.0,
	reused: 0.8,
	compromised: 0.8,
	weak: 0.6,
	stale: 0.3,
}

/**
 * Resolve the staleness cutoff in days from a user threshold setting.
 *
 * @param {string|number} threshold One of '90' | '180' | '365' | 'never'.
 * @return {number|null} The cutoff in days, or null when staleness is disabled.
 */
export function staleCutoffDays(threshold) {
	if (threshold === 'never' || threshold === null || threshold === undefined) {
		return null
	}
	const n = parseInt(threshold, 10)
	return Number.isFinite(n) && n > 0 ? n : 365
}

/**
 * Compute the age in days of a key from its `keyUpdatedAt` ISO timestamp.
 *
 * @param {string|null} keyUpdatedAt The ISO-8601 timestamp, or null.
 * @param {number} [now] The reference epoch ms (defaults to Date.now()).
 * @return {number|null} The age in whole days, or null when unknown.
 */
export function ageInDays(keyUpdatedAt, now = Date.now()) {
	if (!keyUpdatedAt) {
		return null
	}
	const then = Date.parse(keyUpdatedAt)
	if (!Number.isFinite(then)) {
		return null
	}
	return Math.floor((now - then) / 86400000)
}

/**
 * Analyse a set of decrypted secret rows into per-secret findings + a summary.
 *
 * @param {Array<object>} rows Rows of `{id, name, url, folderPath, value,
 *   keyUpdatedAt, possiblyCompromisedAt}`.
 * @param {object} [options] Analysis options.
 * @param {string|number} [options.stalenessThreshold] User threshold.
 * @param {Function} [options.digest] Async value->hex-digest fn (reuse map).
 * @param {Map<string,{status:string,count:number}>} [options.breachResults]
 *   Optional value-digest -> breach verdict map (computed elsewhere via hibp).
 * @param {number} [options.now] Reference time for staleness.
 * @return {Promise<{findings: Array<object>, summary: object, score: number}>}
 */
export async function analyse(rows, options = {}) {
	const {
		stalenessThreshold = '365',
		digest = sha256Hex,
		breachResults = null,
		now = Date.now(),
	} = options

	const cutoff = staleCutoffDays(stalenessThreshold)
	const ctx = {
		index: await digestIndex(rows, digest),
		cutoff,
		now,
		breachResults,
	}
	const findings = rows
		.map((row) => analyseRow(row, ctx))
		.filter((finding) => finding !== null)
	const summary = summarise(rows.length, findings)

	return { findings, summary, score: vaultScore(summary) }
}

/**
 * Digest every non-empty string value (key material included), and group
 * the secret ids per digest for the reuse check.
 *
 * @param {Array<object>} rows The rows passed to {@link analyse}.
 * @param {(value: string) => Promise<string>} digest Async value->hex-digest fn.
 * @return {Promise<{digestById: Map<string,string>, byDigest: Map<string,Array<string>>}>}
 */
async function digestIndex(rows, digest) {
	const digestById = new Map()
	const byDigest = new Map()
	for (const row of rows) {
		if (typeof row.value !== 'string' || row.value.length === 0) {
			continue
		}

		const d = await digest(row.value)
		digestById.set(row.id, d)
		if (!byDigest.has(d)) {
			byDigest.set(d, [])
		}
		byDigest.get(d).push(row.id)
	}
	return { digestById, byDigest }
}

/**
 * The finding for one row, or null when it has no flag and no strength score.
 * Flags come in a fixed order: weak, reused, stale, compromised, breached.
 *
 * @param {object} row One row passed to {@link analyse}.
 * @param {object} ctx The digest index, stale cutoff, reference time and breach verdicts.
 * @return {object|null}
 */
function analyseRow(row, ctx) {
	const value = typeof row.value === 'string' ? row.value : ''
	// Strength (password-bearing values only).
	const score = isPasswordBearing(value) ? zxcvbn(value).score : null
	const d = ctx.index.digestById.get(row.id)
	const shareCount = reuseCount(d, ctx.index.byDigest)
	// Staleness from server-maintained ciphertext age.
	const age = ageInDays(row.keyUpdatedAt, ctx.now)
	const { breach, breached } = breachVerdict(d, ctx.breachResults)
	const flags = [
		[score !== null && score <= WEAK_SCORE_THRESHOLD, 'weak'],
		[shareCount >= 2, 'reused'],
		[isStale(age, ctx.cutoff), 'stale'],
		// Possibly-compromised (the one server-known finding, from suite recovery).
		[Boolean(row.possiblyCompromisedAt), 'compromised'],
		[breached, 'breached'],
	]
		.filter(([on]) => on)
		.map(([, flag]) => flag)

	if (flags.length === 0 && score === null) {
		return null
	}
	return {
		id: row.id,
		name: row.name,
		folderPath: row.folderPath ?? null,
		score,
		flags,
		shareCount,
		ageDays: age,
		breach,
	}
}

/**
 * How many secrets share this digest: the bucket size when it holds 2 or
 * more, otherwise 1.
 *
 * @param {string|undefined} d The row's value digest.
 * @param {Map<string,Array<string>>} byDigest Secret ids per digest.
 * @return {number}
 */
function reuseCount(d, byDigest) {
	if (!d) {
		return 1
	}
	const count = byDigest.get(d).length
	return count >= 2 ? count : 1
}

/**
 * Whether an age in days is past the stale cutoff; never when either is unknown.
 *
 * @param {number|null} age The age in days.
 * @param {number|null} cutoff The cutoff in days.
 * @return {boolean}
 */
function isStale(age, cutoff) {
	return cutoff !== null && age !== null && age > cutoff
}

/**
 * The breach verdict for a digest, only when one was supplied for it, and
 * whether it says breached.
 *
 * @param {string|undefined} d The row's value digest.
 * @param {Map<string,object>|null} breachResults Digest -> breach verdict.
 * @return {{breach: object|null, breached: boolean}}
 */
function breachVerdict(d, breachResults) {
	if (breachResults && d && breachResults.has(d)) {
		const breach = breachResults.get(d)
		return { breach, breached: breach.status === 'breached' }
	}
	return { breach: null, breached: false }
}

/**
 * The finding counts per flag.
 *
 * @param {number} analysedCount The number of rows analysed.
 * @param {Array<object>} findings The findings.
 * @return {object}
 */
function summarise(analysedCount, findings) {
	const count = (flag) =>
		findings.filter((finding) => finding.flags.includes(flag)).length
	return {
		analysedCount,
		weakCount: count('weak'),
		reusedCount: count('reused'),
		staleCount: count('stale'),
		breachedCount: count('breached'),
		compromisedCount: count('compromised'),
	}
}

/**
 * Compute the weighted vault health score (0–100) from finding counts.
 *
 * score = 100 × (1 − Σ(weightᵢ × countᵢ) / analysedCount), clamped to [0, 100].
 *
 * @param {object} summary The finding-count summary from {@link analyse}.
 * @return {number} The score, rounded to an integer.
 */
export function vaultScore(summary) {
	const analysed = Number(summary.analysedCount) || 0
	if (analysed === 0) {
		return 100
	}
	const weighted =
		FINDING_WEIGHTS.weak * (summary.weakCount || 0)
		+ FINDING_WEIGHTS.reused * (summary.reusedCount || 0)
		+ FINDING_WEIGHTS.stale * (summary.staleCount || 0)
		+ FINDING_WEIGHTS.breached * (summary.breachedCount || 0)
		+ FINDING_WEIGHTS.compromised * (summary.compromisedCount || 0)
	const raw = 100 * (1 - weighted / analysed)
	return Math.max(0, Math.min(100, Math.round(raw)))
}

/**
 * Default SHA-256 hex digest via WebCrypto, used to build the reuse map. The
 * digest is a one-way fingerprint kept only for the duration of a pass.
 *
 * @param {string} value The decrypted value.
 * @return {Promise<string>} The hex digest.
 */
export async function sha256Hex(value) {
	const bytes = new TextEncoder().encode(value)
	const digestBuf = await crypto.subtle.digest('SHA-256', bytes)
	return Array.from(new Uint8Array(digestBuf))
		.map((b) => b.toString(16).padStart(2, '0'))
		.join('')
}
