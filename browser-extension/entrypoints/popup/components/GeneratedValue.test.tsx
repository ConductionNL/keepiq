// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, fakeClipboard } from '../testing'
import { ColouredValue, GeneratedValue } from './GeneratedValue'

afterEach(cleanup)

function renderValue(props: Partial<Parameters<typeof GeneratedValue>[0]> = {}) {
	fakeBackground()
	const toast = vi.fn()
	const user = userEvent.setup()
	const handlers = { onRegenerate: vi.fn(), onCopied: vi.fn() }
	render(
		<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast }}>
			<GeneratedValue value="Ab1!" label="Password" {...handlers} {...props} />
		</ShellContext.Provider>,
	)
	return { ...handlers, toast, user }
}

const box = () => document.querySelector<HTMLElement>('.generated__value')!

describe('ColouredValue', () => {
	it('marks digits and symbols, and leaves letters, including accented ones, alone', () => {
		render(<ColouredValue value="é9-x" />)
		const chars = [...document.querySelector('.gen-value')!.children]
		expect(chars.map((char) => [char.textContent, char.className])).toEqual([['é', ''], ['9', 'gen-digit'], ['-', 'gen-symbol'], ['x', '']])
	})
})

describe('GeneratedValue', () => {
	it('shows the value outside any live region', () => {
		renderValue()
		expect(box().textContent).toBe('Ab1!')
		expect(box().closest('[aria-live]:not([aria-live="off"]), [role="status"]')).toBeNull()
	})

	it('regenerates, and copies with a toast before reporting the copy', async () => {
		const { onRegenerate, onCopied, toast, user } = renderValue()
		const writeText = fakeClipboard()
		await user.click(screen.getByRole('button', { name: 'Regenerate' }))
		expect(onRegenerate).toHaveBeenCalledOnce()
		await user.click(screen.getByRole('button', { name: 'Copy password' }))
		expect(writeText).toHaveBeenCalledWith('Ab1!')
		expect(toast).toHaveBeenCalledWith('Password copied')
		expect(onCopied).toHaveBeenCalledWith('Ab1!')
	})

	it('shows the failure instead of a value, with Copy disabled', () => {
		renderValue({ value: null, failure: 'Enter an email address' })
		expect(screen.getByText('Enter an email address')).toBeTruthy()
		expect(screen.getByRole<HTMLButtonElement>('button', { name: 'Copy password' }).disabled).toBe(true)
	})

	it('takes focus on mount only when asked to', () => {
		renderValue()
		expect(document.activeElement).toBe(document.body)
		expect(box().hasAttribute('tabindex')).toBe(false)
		cleanup()
		renderValue({ focusOnMount: true })
		expect(document.activeElement).toBe(box())
		expect(box().tabIndex).toBe(-1)
	})
})
