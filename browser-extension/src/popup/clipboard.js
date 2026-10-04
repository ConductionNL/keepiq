/**
 * Copying from the popup (clients-extension-gaps): write the text, then tell
 * the worker, which clears the clipboard after the user's delay even when
 * the popup has closed by then.
 *
 * @spec openspec/specs/extension-clipboard/spec.md#requirement-every-copy-is-cleared-after-a-delay-the-user-sets
 */

/**
 * Copy text and have the worker clear it later. Quietly does nothing where
 * the clipboard is unavailable: the value stays visible instead.
 *
 * @param {string} text The text.
 * @return {Promise<boolean>} Whether the text was copied.
 */
export async function copyText(text) {
	try {
		await navigator.clipboard.writeText(text)
	} catch {
		// No clipboard (no focus, or not allowed).
		return false
	}
	try {
		chrome.runtime.sendMessage(
			{ type: 'clipboard-copied', payload: {} },
			() => {},
		)
	} catch {
		// The worker clears nothing; the copy itself stands.
	}
	return true
}
