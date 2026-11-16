/**
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * Stylelint rule `keepiq/no-bare-hex-color`.
 *
 * Keepiq takes its look from the Nextcloud theme (Nextcloud theming or
 * thematiq), so a colour value may appear in a stylesheet only as the
 * fallback of a `var()`: `var(--color-primary-element, #00679e)` is
 * allowed, `color: #00679e` and `var(#00679e)` are not. Built-in
 * `color-no-hex` cannot express that exception, it rejects fallbacks too.
 *
 * @spec openspec/changes/platform-properties/specs/platform-properties/spec.md#requirement-the-look-comes-from-the-nextcloud-theme
 */
import stylelint from 'stylelint'

const {
	createPlugin,
	utils: { report, ruleMessages, validateOptions },
} = stylelint

export const ruleName = 'keepiq/no-bare-hex-color'

export const messages = ruleMessages(ruleName, {
	rejected: (hex) => `Unexpected hex colour "${hex}"; use a Nextcloud theme variable, with the colour only as its var() fallback`,
})

const HEX = /^#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/

/**
 * Find the hex colours in a declaration value that are not inside the
 * fallback part of a var() call.
 *
 * Walks the value once, keeping a stack of the open functions and, per
 * function, whether its first top-level comma has been passed. Quoted
 * strings are skipped, so `content: "#1"` is not a colour.
 *
 * @spec openspec/changes/platform-properties/tasks.md#1.3
 *
 * @param {string} value The declaration value
 * @return {Array<{hex: string, index: number}>} The rejected colours
 */
export function bareHexColors(value) {
	const found = []
	const stack = []
	let i = 0
	while (i < value.length) {
		const ch = value[i]
		if (ch === '"' || ch === '\'') {
			const end = value.indexOf(ch, i + 1)
			i = end === -1 ? value.length : end + 1
			continue
		}
		if (ch === '(') {
			const name = /([-\w]*)$/.exec(value.slice(0, i))[1].toLowerCase()
			stack.push({ name, pastComma: false })
		} else if (ch === ')') {
			stack.pop()
		} else if (ch === ',' && stack.length > 0) {
			stack[stack.length - 1].pastComma = true
		} else if (ch === '#') {
			const word = /^#[\w-]*/.exec(value.slice(i))[0]
			if (HEX.test(word)) {
				const inFallback = stack.some((f) => f.name === 'var' && f.pastComma)
				if (!inFallback) {
					found.push({ hex: word, index: i })
				}
			}
			i += word.length
			continue
		}
		i++
	}
	return found
}

/**
 * The rule: report every hex colour in a declaration value that is not a
 * var() fallback.
 *
 * @param {boolean} primary The rule option; only `true` is valid
 * @return {(root: object, result: object) => void} The PostCSS root walker
 */
function rule(primary) {
	return (root, result) => {
		if (!validateOptions(result, ruleName, { actual: primary, possible: [true] })) {
			return
		}
		root.walkDecls((decl) => {
			for (const { hex, index } of bareHexColors(decl.value)) {
				const start = decl.prop.length + (decl.raws.between || ':').length + index
				report({
					result,
					ruleName,
					node: decl,
					message: messages.rejected(hex),
					index: start,
					endIndex: start + hex.length,
				})
			}
		})
	}
}

rule.ruleName = ruleName
rule.messages = messages

export default createPlugin(ruleName, rule)
