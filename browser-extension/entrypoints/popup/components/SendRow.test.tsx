// @vitest-environment happy-dom
import { cleanup, render, screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, describe, expect, it, vi } from 'vitest'
import { setLocale } from '@/src/testing/i18n'
import { sendRow } from '../testing'
import { isExpired, sendLabel, SendRow } from './SendRow'

afterEach(() => {
	cleanup()
	setLocale('en')
})

const renderRow = (row = sendRow(), link?: string) => {
	const onRemove = vi.fn()
	render(<ul><SendRow row={row} name="Text send (2)" link={link} onRemove={onRemove} /></ul>)
	return onRemove
}

const thisYear = new Date().getFullYear()

describe('SendRow', () => {
	it('labels by type and a short 24-hour time, as sends have no name', () => {
		const createdAt = new Date(thisYear, 9, 22, 21, 5).toISOString()
		expect(sendLabel(sendRow({ createdAt }))).toBe('Text send, Oct 22, 21:05')
		expect(sendLabel(sendRow({ createdAt, payloadType: 'credential' }))).toBe('Credential send, Oct 22, 21:05')
		setLocale('nl')
		expect(sendLabel(sendRow({ createdAt, payloadType: 'credential' }))).toBe('Inloggegevens, 22 okt, 21:05')
	})

	it('names the year only when it is not this one', () => {
		expect(sendLabel(sendRow({ createdAt: new Date(thisYear - 1, 0, 2, 8, 0).toISOString() }))).toBe(`Text send, Jan 2, ${thisYear - 1}, 08:00`)
	})

	it('shows views and expiry on lines of their own', () => {
		renderRow(sendRow({ maxViews: 1, remainingViews: 1, expiresAt: new Date(thisYear, 10, 1, 12, 0).toISOString() }))
		expect(screen.getByText('1 of 1 view left')).toBeTruthy()
		expect(screen.getByText('Expires Nov 1, 12:00')).toBeTruthy()
	})

	it('names its actions by the name it was given, and removes on request', async () => {
		const onRemove = renderRow(sendRow(), 'https://example.org/l#k=x')
		expect(screen.getByRole('button', { name: 'Copy link, Text send (2)' })).toBeTruthy()
		await userEvent.setup().click(screen.getByRole('button', { name: 'Remove, Text send (2)' }))
		expect(onRemove).toHaveBeenCalledWith(sendRow())
	})

	it('treats a send as expired from its expiry on', () => {
		const row = sendRow({ expiresAt: '2026-10-22T10:00:00Z' })
		expect(isExpired(row, Date.parse('2026-10-22T09:59:59Z'))).toBe(false)
		expect(isExpired(row, Date.parse('2026-10-22T10:00:00Z'))).toBe(true)
		expect(isExpired(sendRow({ expiresAt: null }))).toBe(false)
	})
})
