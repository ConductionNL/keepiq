/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * The steps of the encryption suite store's re-encryption loop: unwrapping
 * the keys, fetching and migrating one work page after another, posting one
 * page of pipeline results and settling each record, and ending the run.
 * Each run object lives only for the call of the store action that built it.
 *
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	decryptPrivateKey,
	importPrivateKey,
	importPublicKey,
} from '../../crypto/index.js'

/**
 * Unwrap the old private key with the old master password and import the
 * keys the runner needs. Both stay in this scope; neither is ever sent
 * anywhere (ADR-003).
 *
 * @param {object} params The migration parameters.
 * @return {Promise<{oldPrivateKey: CryptoKey, newPublicKey: CryptoKey, newPrivateKey: CryptoKey}>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-migration-covers-every-suite-bound-store
 */
export async function unwrapMigrationKeys(params) {
	const oldPrivateKeyPem = await decryptPrivateKey(
		params.oldEncryptedPrivateKey,
		params.oldPassword,
	)
	return {
		oldPrivateKey: await importPrivateKey(oldPrivateKeyPem),
		newPublicKey: await importPublicKey(params.newPublicKeyPem),
		newPrivateKey: params.newPrivateKey,
	}
}

/**
 * Migrate work pages until none is left or a page stops the run. Each pass
 * re-asks for outstanding work from offset 0: committed records leave the
 * result set, so the next page is always at the front.
 *
 * @param {object} run The migration run.
 * @return {Promise<void>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */
export async function migratePages(run) {
	let more = true
	while (more) {
		const page = await fetchWorkPage(run)
		more = page.jobs.length > 0 && (await migratePage(run, page))
	}
}

/**
 * @param {object} run The migration run.
 * @return {Promise<{data: object, jobs: Array<object>}>} The next work page
 *   and its jobs; the versions it leaves out are noted on the store.
 */
async function fetchWorkPage(run) {
	const { data } = await axios.get(
		generateUrl(`/apps/keepiq/api/v1/migrations/${run.migrationId}/work`),
	)
	run.suiteStore.migrationDroppedVersions = data.versions?.dropCandidates || 0
	return { data, jobs: run.suiteStore.buildJobs(data) }
}

/**
 * Re-encrypt one page, post its results and add them to the run.
 *
 * @param {object} run The migration run.
 * @param {{data: object, jobs: Array<object>}} page The work page.
 * @return {Promise<boolean>} Whether the run goes on to the next page.
 */
async function migratePage(run, page) {
	const total = progressTotal(run.migrated, page.data)
	showMigrating(run, total)
	const results = await run.runner.run(page.jobs)
	const outcome = await run.suiteStore.postResults(
		run.migrationId,
		results,
		page.jobs,
	)
	run.migrated += outcome.committed
	run.failures.push(...outcome.failures)
	run.suiteStore.migrationFailures = [...run.failures]
	showMigrating(run, total)
	return goesOnAfter(run, outcome)
}

/**
 * The progress bar's total. `totalRemaining` counts EVERY row still on the
 * old suite, including the version rows outside the retention window. Those
 * are reported as dropCandidates and never dispatched as jobs — the server
 * deletes them at completion — so using the raw number as the denominator
 * left the bar short of 100% and then jumping to the terminal panel, which
 * reads as a hang during an already-anxious operation.
 *
 * @param {number} migrated The records committed so far.
 * @param {object} work The work page.
 * @return {number}
 */
function progressTotal(migrated, work) {
	return (
		migrated
		+ Math.max(0, work.totalRemaining - (work.versions?.dropCandidates || 0))
	)
}

/**
 * @param {object} run The migration run.
 * @param {number} total The progress bar's total.
 * @return {void}
 */
function showMigrating(run, total) {
	run.suiteStore.migrationProgress = {
		done: run.migrated,
		total,
		phase: 'migrating',
	}
}

/**
 * Whether the run goes on after a page, noting why it halted when it does
 * not.
 *
 * A round-trip that does not verify is not this record's problem — it is
 * the new key's. Stop immediately rather than grinding through the vault
 * producing ciphertext nobody can read. Everything committed so far was
 * individually verified, so it stays valid, and the migration stays
 * resumable.
 *
 * A pass that commits nothing cannot make progress, and the work list is
 * re-derived from the rows — so asking again returns exactly the same page.
 * Stop either way; what differs is whether it counts as a halt.
 *
 * @param {object} run The migration run.
 * @param {object} outcome The page's outcome.
 * @return {boolean}
 */
function goesOnAfter(run, outcome) {
	if (outcome.halt !== null) {
		run.halted = outcome.halt
		return false
	}
	if (outcome.committed === 0) {
		if (outcome.failures.length === 0) {
			// Nothing committed and nothing permanently failed: the whole
			// page was transient (offline, server down). The rows stay
			// unaccounted server-side, so the completion gate will refuse and
			// the resume banner picks it up.
			halted(run)
		}
		// Otherwise every record in this page failed permanently and is now
		// recorded against its owning secret. Letting the loop continue here
		// was an infinite spin: the rows stay bound to the old suite, so each
		// pass re-fetched and re-failed the same page and the dialog sat at
		// "0 of N" forever. Fall through to completion, which asks the user
		// what to do about them.
		return false
	}
	return true
}

/**
 * @param {object} run The migration run.
 * @return {void}
 */
function halted(run) {
	run.halted =
		'Migration could not reach the server; no records were changed.'
}

/**
 * End the run: throw its halt, or mark it done and return its outcome.
 *
 * @param {object} run The migration run.
 * @return {{migrated: number, failed: number, droppedVersions: number,
 *   failures: Array<object>, usedWorker: boolean}}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-a-migration-always-has-a-way-to-terminate
 */
export function finishRun(run) {
	if (run.halted !== null) {
		showEnded(run, 'halted')
		throw haltError(run)
	}
	showEnded(run, 'done')
	return runOutcome(run)
}

/**
 * @param {object} run The migration run.
 * @param {string} phase How it ended: halted or done.
 * @return {void}
 */
function showEnded(run, phase) {
	run.suiteStore.migrationProgress = {
		done: run.migrated,
		total: run.migrated,
		phase,
	}
}

/**
 * @param {object} run The finished migration run.
 * @return {{migrated: number, failed: number, droppedVersions: number,
 *   failures: Array<object>, usedWorker: boolean}}
 */
function runOutcome(run) {
	return {
		migrated: run.migrated,
		failed: run.failures.length,
		droppedVersions: run.suiteStore.migrationDroppedVersions,
		failures: run.failures,
		usedWorker: run.runner.usesWorker,
	}
}

/**
 * @param {object} run The halted migration run.
 * @return {Error} A MigrationHaltedError carrying what was migrated and failed.
 */
function haltError(run) {
	const error = new Error(run.halted)
	error.name = 'MigrationHaltedError'
	error.migrated = run.migrated
	error.failures = run.failures
	return error
}

/**
 * The jobs of a work section whose records carry no name.
 *
 * @param {object|undefined} section The work section.
 * @param {string} store Its MIGRATION_STORES value.
 * @return {Array<object>}
 */
export function unnamedJobs(section, store) {
	return (section?.records || []).map((record) => ({
		store,
		id: record.id,
		name: null,
		record,
	}))
}

/**
 * Post one window of pipeline results at once and tally what they settled as.
 *
 * @param {object} run The page post run.
 * @param {Array<object>} window The results of this window.
 * @return {Promise<void>}
 * @spec openspec/changes/restore-suite-migration-loop/specs/encryption-suites/spec.md#requirement-re-encrypted-ciphertext-is-verified-before-the-original-is-discarded
 */
export async function postWindow(run, window) {
	const settled = await Promise.all(
		window.map((result) => settleResult(run, result)),
	)
	for (const entry of settled) {
		tallySettled(run, entry)
	}
}

/**
 * Settle one pipeline result.
 *
 * A round-trip mismatch means the STORED value read back fine and the new
 * key could not carry it. Reporting that as a failure would let the server
 * finalise a readable secret as unrecoverable, so it is never sent — the run
 * stops instead. Only an old-key decrypt failure is reported. Anything else
 * unproven stays unreported, which leaves the row unaccounted server-side so
 * the completion gate keeps blocking and a resume retries it.
 *
 * @param {object} run The page post run.
 * @param {object} result The pipeline result.
 * @return {Promise<{outcome: string, result: object, error: ?string}>}
 */
async function settleResult(run, result) {
	if (result.halt === true) {
		return { outcome: 'halt', result, error: result.error }
	}
	if (result.ok !== true && result.permanent !== true) {
		return { outcome: 'retryable', result, error: result.error }
	}
	const url = run.suiteStore.recordUrl(run.migrationId, result.store, result.id)
	if (url === null) {
		return {
			outcome: 'retryable',
			result,
			error: `Unknown migration store: ${result.store}`,
		}
	}
	return commitResult(result, url)
}

/**
 * Post a verified payload, or report an old-key decrypt failure.
 *
 * @param {object} result The pipeline result.
 * @param {string} url The record's endpoint.
 * @return {Promise<{outcome: string, result: object, error: ?string}>}
 */
async function commitResult(result, url) {
	const body = result.ok === true ? result.payload : { error: result.error }
	try {
		await axios.post(url, body)
		return {
			outcome: result.ok === true ? 'committed' : 'permanent',
			result,
			error: result.error,
		}
	} catch (e) {
		return commitFailure(result, e)
	}
}

/**
 * A commit that leaves a stored `totp` or `passkeys` behind is refused, and
 * resending the same payload is refused again: stop with the server's reason
 * instead of retrying it. Nothing was written, so it stays resumable. Any
 * other failed commit never landed (network, guard rejection), so the row is
 * simply still outstanding: retryable by definition.
 *
 * @param {object} result The pipeline result.
 * @param {Error|object|string} e What the post threw.
 * @return {{outcome: string, result: object, error: string}}
 */
function commitFailure(result, e) {
	if (e?.response?.data?.error === 'fields_not_reencrypted') {
		return { outcome: 'halt', result, error: e.response.data.message }
	}
	return {
		outcome: 'retryable',
		result,
		error: e?.response?.data?.message || String(e?.message || e),
	}
}

/**
 * @param {object} run The page post run.
 * @return {void}
 */
function tallyCommitted(run) {
	run.committed += 1
}

/**
 * @param {object} run The page post run.
 * @param {object} entry A settled record that stops the run.
 * @return {void}
 */
function tallyHalt(run, entry) {
	run.halt = run.halt ?? (entry.error || 'Re-encryption could not be verified')
}

/**
 * @param {object} run The page post run.
 * @param {object} entry A settled record that stays outstanding.
 * @return {void}
 */
function tallyRetryable(run, entry) {
	run.retryable.push({
		store: entry.result.store,
		id: entry.result.id,
		name: run.namesById.get(entry.result.id) ?? null,
		error: entry.error || 'Temporarily failed',
	})
}

/**
 * @param {object} run The page post run.
 * @param {object} entry A settled record that failed for good.
 * @return {void}
 */
function tallyFailure(run, entry) {
	run.failures.push({
		store: entry.result.store,
		id: entry.result.id,
		name: run.namesById.get(entry.result.id) ?? null,
		error: entry.error || 'Migration failed',
		unrecoverable: true,
	})
}

/** How each settled outcome is tallied; a permanent failure otherwise. */
const TALLIES = new Map([
	['committed', tallyCommitted],
	['halt', tallyHalt],
	['retryable', tallyRetryable],
])

/**
 * @param {object} run The page post run.
 * @param {object} entry A settled record.
 * @return {void}
 */
function tallySettled(run, entry) {
	const tally = TALLIES.get(entry.outcome) ?? tallyFailure
	tally(run, entry)
}
