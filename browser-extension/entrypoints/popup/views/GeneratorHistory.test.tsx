// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { GeneratorHistoryEntry } from '@/src/generator/options'
import { setLocale } from '@/src/testing/i18n'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { fakeBackground, fakeClipboard, sentOf } from '../testing'
import { GeneratorHistory } from './GeneratorHistory'

afterEach(cleanup)

const minutesAgo = (minutes: number) => new Date(Date.now() - minutes * 60_000).toISOString()
const history: GeneratorHistoryEntry[] = [
	{ value: 'aB3$', kind: 'password', createdAt: minutesAgo(0) },
	{ value: 'correct-horse', kind: 'passphrase', createdAt: minutesAgo(5) },
]

function renderHistory(background: Parameters<typeof fakeBackground>[0] = {}) {
	const sent = fakeBackground(background)
	const toast = vi.fn()
	const user = userEvent.setup()
	render(<ShellContext.Provider value={{ ...DEFAULT_SHELL, toast }}><GeneratorHistory /></ShellContext.Provider>)
	return { sent, toast, user }
}

const rows = () => screen.findAllByRole('listitem')

describe('GeneratorHistory', () => {
	it('lists the entries newest first, with their kind, time and coloured value', async () => {
		renderHistory({ generator: { history } })
		const [first, second] = await rows()
		expect(within(first!).getByText('Password', { exact: false }).textContent).toBe('Password, just now')
		expect(first!.querySelector('time')!.getAttribute('dateTime')).toBe(history[0]!.createdAt)
		expect(first!.querySelector('.gen-value')!.textContent).toBe('aB3$')
		expect(first!.querySelector('.gen-digit')!.textContent).toBe('3')
		expect(within(second!).getByText('Passphrase', { exact: false }).textContent).toBe('Passphrase, 5 minutes ago')
	})

	it('words the time in Dutch', async () => {
		setLocale('nl')
		renderHistory({ generator: { history } })
		const [, second] = await rows()
		expect(second!.querySelector('.field-row__label')!.textContent).toBe('Wachtwoordzin, 5 minuten geleden')
	})

	it('copies an entry', async () => {
		const { toast, user } = renderHistory({ generator: { history } })
		const writeText = fakeClipboard()
		const [, second] = await rows()
		await user.click(within(second!).getByRole('button', { name: 'Copy passphrase' }))
		expect(writeText).toHaveBeenCalledWith('correct-horse')
		expect(toast).toHaveBeenCalledWith('Passphrase copied')
	})

	it('shows the empty state', async () => {
		renderHistory()
		expect(await screen.findByRole('heading', { name: 'No generated values yet' })).toBeTruthy()
	})

	it('clears after confirming', async () => {
		const { sent, user } = renderHistory({ generator: { history } })
		await rows()
		await user.click(screen.getByRole('button', { name: 'Clear' }))
		const dialog = screen.getByRole('dialog', { name: 'Clear the generator history?' })
		await user.click(within(dialog).getByRole('button', { name: 'Clear' }))
		expect(await screen.findByRole('heading', { name: 'No generated values yet' })).toBeTruthy()
		expect(sentOf(sent, 'generator.history.clear')).toEqual([{ kind: 'generator.history.clear', accountId: 'a1' }])
	})

	it('keeps the entries when the user cancels', async () => {
		const { sent, user } = renderHistory({ generator: { history } })
		await rows()
		await user.click(screen.getByRole('button', { name: 'Clear' }))
		await user.click(screen.getByRole('button', { name: 'Cancel' }))
		expect(screen.queryByRole('dialog')).toBeNull()
		expect(await rows()).toHaveLength(2)
		expect(sentOf(sent, 'generator.history.clear')).toEqual([])
	})

	it('says so in the dialog when the background does not answer the clear', async () => {
		const { user } = renderHistory({ generator: { history }, unanswered: ['generator.history.clear'] })
		await rows()
		await user.click(screen.getByRole('button', { name: 'Clear' }))
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Clear' }))
		await waitFor(() => expect(within(screen.getByRole('dialog')).getByRole('alert').textContent).toContain('Keepiq is not responding'))
		expect(await rows()).toHaveLength(2)
	})

	it('says the background is not responding when the list does not come', async () => {
		renderHistory({ unanswered: ['generator.history.list'] })
		expect((await screen.findByRole('alert')).textContent).toContain('Keepiq is not responding')
	})
})
