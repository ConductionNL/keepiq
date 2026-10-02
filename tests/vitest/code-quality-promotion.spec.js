/**
 * SPDX-FileCopyrightText: 2026 Conduction / Keepiq Contributors
 * SPDX-License-Identifier: EUPL-1.2
 *
 * keepiq#882: the development to beta promotion PR must run the Code Quality
 * pipeline, because the shared workflow runs Playwright only on PRs into
 * `beta` and `main` (`e2e-promotion-only`). This reads the `quality` job's
 * job-level `if:` straight out of .github/workflows/code-quality.yml and
 * evaluates it for each event shape, so the test cannot drift from the file.
 *
 * @spec exclude CI wiring of the shared quality workflow; no product spec covers it
 */

import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const WORKFLOW = resolve(__dirname, '../../.github/workflows/code-quality.yml')

/**
 * The `if:` of the `quality` job, as written.
 *
 * @return {string} the GitHub expression
 */
function qualityIf() {
	const text = readFileSync(WORKFLOW, 'utf8')
	const job = text.split(/^jobs:\s*$/m)[1]
	const block = job.split(/^ {2}quality:\s*$/m)[1]
	const match = block.match(/^ {4}if:\s*(.+)$/m)
	if (match === null) {
		throw new Error('the quality job declares no if:, so this test has nothing to evaluate')
	}
	return match[1].trim()
}

/**
 * Evaluate the expression for one event. Only the operators this condition
 * may use are translated; anything else throws instead of guessing.
 *
 * @param {string} expr - the GitHub expression
 * @param {object} github - event_name, head_ref, base_ref
 * @return {boolean} whether the job runs
 */
function evaluate(expr, github) {
	const js = expr.replace(/github\.(event_name|head_ref|base_ref)/g, (_, key) => JSON.stringify(github[key] ?? ''))
	if (/[^\s()'"|=!&\w.-]/.test(js.replace(/"[^"]*"|'[^']*'/g, ''))) {
		throw new Error(`unexpected token in ${expr}`)
	}
	return Function(`return (${js.replace(/'/g, '"')})`)()
}

describe('code-quality.yml job condition (keepiq#882)', () => {
	const expr = qualityIf()

	it('runs the development to beta promotion PR (red on the old condition)', () => {
		expect(evaluate(expr, { event_name: 'pull_request', head_ref: 'development', base_ref: 'beta' })).toBe(true)
	})

	it('still skips a development-headed PR into any other branch', () => {
		expect(evaluate(expr, { event_name: 'pull_request', head_ref: 'development', base_ref: 'main' })).toBe(false)
	})

	it('runs feature PRs, pushes and dispatches as before', () => {
		expect(evaluate(expr, { event_name: 'pull_request', head_ref: 'fix/x', base_ref: 'development' })).toBe(true)
		expect(evaluate(expr, { event_name: 'pull_request', head_ref: 'beta', base_ref: 'main' })).toBe(true)
		expect(evaluate(expr, { event_name: 'push', head_ref: '', base_ref: '' })).toBe(true)
		expect(evaluate(expr, { event_name: 'workflow_dispatch', head_ref: '', base_ref: '' })).toBe(true)
	})

	it('control: the pre-#882 condition skipped the promotion PR', () => {
		const old = "(github.event_name != 'pull_request' || github.head_ref != 'development')"
		expect(evaluate(old, { event_name: 'pull_request', head_ref: 'development', base_ref: 'beta' })).toBe(false)
	})
})
