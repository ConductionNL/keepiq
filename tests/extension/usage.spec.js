/**
 * @spec openspec/changes/vault-favourites-tags-and-last-used/specs/vault-list-organisation/spec.md#requirement-sort-by-date-last-used
 *
 * After a fill the worker reports the used secret once; a fill that did not
 * happen reports nothing, and a failed report never breaks the fill.
 */
import { describe, expect, it, vi } from 'vitest'
import { reportFill } from '../../browser-extension/src/lib/usage.js'

describe('reportFill', () => {
	it('posts the id once after a successful fill', async () => {
		const post = vi.fn().mockResolvedValue(undefined)
		await reportFill({ filled: true }, 's-1', post)
		expect(post).toHaveBeenCalledTimes(1)
		expect(post).toHaveBeenCalledWith('s-1')
	})

	it('posts nothing when the fill did not happen', async () => {
		const post = vi.fn()
		await reportFill({ filled: false }, 's-1', post)
		await reportFill(undefined, 's-1', post)
		await reportFill({ filled: true }, '', post)
		expect(post).not.toHaveBeenCalled()
	})

	it('swallows a failed report so the fill still answers', async () => {
		const post = vi.fn().mockRejectedValue(new Error('offline'))
		await expect(reportFill({ filled: true }, 's-1', post)).resolves.toBe(false)
	})
})
