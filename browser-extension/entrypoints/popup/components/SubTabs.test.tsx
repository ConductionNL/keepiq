// @vitest-environment happy-dom
import { cleanup, render, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { useState } from 'react'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { SubTabs, subTabId, type SubTab } from './SubTabs'

afterEach(cleanup)

type Id = 'a' | 'b' | 'c'
const TABS: SubTab<Id>[] = [{ id: 'a', label: 'Alpha' }, { id: 'b', label: 'Beta' }, { id: 'c', label: 'Gamma' }]

function Harness({ tabs = TABS, onChange = vi.fn(), hint }: { tabs?: SubTab<Id>[]; onChange?: (id: Id) => void; hint?: string }) {
	const [value, setValue] = useState<Id>('a')
	return (
		<>
			<SubTabs label="Kinds" panelId="panel" tabs={tabs} value={value} hint={hint} onChange={(id) => {
				onChange(id)
				setValue(id)
			}} />
			<div role="tabpanel" id="panel" aria-labelledby={subTabId('panel', value)} />
		</>
	)
}

const selected = () => screen.getAllByRole('tab').filter((tab) => tab.getAttribute('aria-selected') === 'true').map((tab) => tab.textContent)

describe('SubTabs', () => {
	it('is a labelled tablist with one tab in the tab order, controlling the panel', () => {
		render(<Harness />)
		expect(screen.getByRole('tablist', { name: 'Kinds' })).toBeTruthy()
		expect(screen.getAllByRole('tab').map((tab) => tab.tabIndex)).toEqual([0, -1, -1])
		expect(screen.getAllByRole('tab').every((tab) => tab.getAttribute('aria-controls') === 'panel')).toBe(true)
		expect(screen.getByRole('tabpanel', { name: 'Alpha' })).toBeTruthy()
	})

	it('positions one hidden pill under the selected tab, moving it on click and with the arrow keys', async () => {
		const user = userEvent.setup()
		render(<Harness />)
		const list = screen.getByRole('tablist')
		const position = () => [list.style.getPropertyValue('--index'), list.style.getPropertyValue('--count')]
		const pills = list.querySelectorAll('.segmented__pill')
		expect(pills).toHaveLength(1)
		expect(pills[0]!.getAttribute('aria-hidden')).toBe('true')
		expect(position()).toEqual(['0', '3'])
		await user.click(screen.getByRole('tab', { name: 'Gamma' }))
		expect(position()).toEqual(['2', '3'])
		await user.keyboard('{ArrowLeft}')
		expect(position()).toEqual(['1', '3'])
	})

	it('slides only after mounting, so opening on a stored tab does not animate', async () => {
		render(<Harness />)
		const list = screen.getByRole('tablist')
		expect(list.classList.contains('segmented--sliding')).toBe(false)
		await waitFor(() => expect(list.classList.contains('segmented--sliding')).toBe(true))
	})

	it('selects on click', async () => {
		const user = userEvent.setup()
		render(<Harness />)
		await user.click(screen.getByRole('tab', { name: 'Gamma' }))
		expect(selected()).toEqual(['Gamma'])
	})

	it('moves focus with the arrow keys, Home and End over every tab, wrapping around, and selects only enabled ones', async () => {
		const user = userEvent.setup()
		const focused = () => document.activeElement?.textContent
		render(<Harness tabs={[TABS[0]!, { ...TABS[1]!, disabled: true }, TABS[2]!]} />)
		screen.getByRole('tab', { name: 'Alpha' }).focus()
		await user.keyboard('{ArrowRight}')
		expect([focused(), selected()]).toEqual(['Beta', ['Alpha']])
		await user.keyboard('{ArrowRight}')
		expect([focused(), selected()]).toEqual(['Gamma', ['Gamma']])
		await user.keyboard('{ArrowRight}')
		expect([focused(), selected()]).toEqual(['Alpha', ['Alpha']])
		await user.keyboard('{ArrowLeft}')
		expect([focused(), selected()]).toEqual(['Gamma', ['Gamma']])
		await user.keyboard('{Home}')
		expect([focused(), selected()]).toEqual(['Alpha', ['Alpha']])
		await user.keyboard('{End}')
		expect([focused(), selected()]).toEqual(['Gamma', ['Gamma']])
	})

	it('ignores a click on a disabled tab and describes it by the hint', async () => {
		const user = userEvent.setup()
		const onChange = vi.fn()
		render(<Harness tabs={[TABS[0]!, { ...TABS[1]!, disabled: true }]} hint="Turned off" onChange={onChange} />)
		const beta = screen.getByRole('tab', { name: 'Beta' })
		await user.click(beta)
		expect(onChange).not.toHaveBeenCalled()
		expect(beta.getAttribute('aria-disabled')).toBe('true')
		expect(document.getElementById(beta.getAttribute('aria-describedby')!)!.textContent).toBe('Turned off')
		expect(screen.getByRole('tab', { name: 'Alpha' }).getAttribute('aria-describedby')).toBeNull()
	})
})
