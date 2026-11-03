// @vitest-environment happy-dom
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { EditableItem, FolderMeta } from '@/src/messages'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { editableItem, fakeBackground, sentOf, snapshotReply } from '../testing'
import { ItemForm, type FormMode } from './ItemForm'

// happy-dom gives import.meta.url a non-file scheme, so the record is found from the package root.
const GOLDEN = resolve(process.cwd(), 'entrypoints/popup/views/ItemForm.markup.golden.json')

let footer: HTMLElement
beforeEach(() => {
	footer = document.body.appendChild(document.createElement('div'))
})
afterEach(() => {
	cleanup()
	footer.remove()
})

const folders: FolderMeta[] = [{ id: 'w', name: 'Work', parentId: null }]

interface World {
	mode?: FormMode
	item?: EditableItem
	offline?: boolean
	replies?: Record<string, unknown>
	unanswered?: string[]
}

/** The rendered form and footer, with React's generated ids numbered in order of appearance. */
function markup(): string {
	const html = document.body.innerHTML
	const ids = new Map<string, string>()
	return html.replace(/«[^»]*»|:r[0-9a-z]+:/g, (id) => {
		if (!ids.has(id)) ids.set(id, `id${ids.size}`)
		return ids.get(id)!
	})
}

function renderForm({ mode = 'add', item, offline = false, replies, unanswered }: World = {}) {
	const snapshot = snapshotReply({ folders })
	const sent = fakeBackground({ snapshot, items: item && { i1: item }, replies, unanswered })
	const shell = { ...DEFAULT_SHELL, footer, offline, toast: vi.fn(), refreshState: vi.fn(), syncNow: vi.fn(), setLeaveGuard: vi.fn(), generate: vi.fn() }
	render(
		<ShellContext.Provider value={shell}>
			<ItemForm mode={mode} id={item && 'i1'} snapshot={snapshot} onSaved={vi.fn()} onCancel={vi.fn()} onGone={vi.fn()} />
		</ShellContext.Provider>,
	)
	return { sent, shell, user: userEvent.setup() }
}

const save = () => within(footer).getByRole('button', { name: 'Save' })
const writes = (sent: ReturnType<typeof fakeBackground>) => [...sentOf(sent, 'item.create'), ...sentOf(sent, 'item.update')]
const wide = (count: number) => '\u{1F511}'.repeat(count)

type Trace = Record<string, unknown>

async function failedSave(world: World, code: string): Promise<Trace> {
	const { sent, user } = renderForm({ ...world, replies: { 'item.create': { ok: false, error: { code } }, 'item.update': { ok: false, error: { code } } } })
	const name = await screen.findByLabelText('Name') as HTMLInputElement
	await user.clear(name)
	await user.type(name, 'Saved name')
	await user.click(save())
	await screen.findByRole('alert')
	return { html: markup(), sent: writes(sent) }
}

const WORLDS: Record<string, () => Promise<Trace>> = {
	'add, online': async () => {
		renderForm()
		await screen.findByLabelText('Name')
		return { html: markup() }
	},
	'add, offline': async () => {
		renderForm({ offline: true })
		await screen.findByLabelText('Name')
		return { html: markup() }
	},
	'add fails unverified': () => failedSave({}, 'unverified'),
	'add fails conflict': () => failedSave({}, 'conflict'),
	'add fails offline': () => failedSave({}, 'offline'),
	'edit fails unverified': () => failedSave({ mode: 'edit', item: editableItem() }, 'unverified'),
	'edit fails conflict': () => failedSave({ mode: 'edit', item: editableItem() }, 'conflict'),
	'edit fails invalid': () => failedSave({ mode: 'edit', item: editableItem() }, 'invalid'),
	'clone fails conflict': () => failedSave({ mode: 'clone', item: editableItem() }, 'conflict'),
	'add fails without an answer': async () => {
		const { sent, user } = renderForm({ unanswered: ['item.create'] })
		await user.type(await screen.findByLabelText('Name'), 'No answer')
		await user.click(save())
		await screen.findByRole('alert')
		return { html: markup(), sent: writes(sent) }
	},
	'totp options': async () => {
		// A new item can no longer be a totp, so the options are traced on an existing one.
		const { sent, user } = renderForm({ mode: 'edit', item: editableItem({ typeId: 't-totp', name: '', key: '', login: null }) })
		await user.type(await screen.findByLabelText('Name'), 'Authenticator')
		await user.type(screen.getByLabelText('Authenticator key'), 'JBSWY3DPEHPK3PXP')
		const steps: string[] = [markup()]
		await user.selectOptions(screen.getByLabelText('Algorithm'), 'SHA256')
		await user.selectOptions(screen.getByLabelText('Digits'), '8')
		steps.push(markup())
		await user.selectOptions(screen.getByLabelText('Period in seconds'), '60')
		await user.selectOptions(screen.getByLabelText('Digits'), '6')
		steps.push(markup())
		await user.click(save())
		await waitFor(() => expect(writes(sent)).toHaveLength(1))
		return { steps, sent: writes(sent) }
	},
	'totp edit with an odd period': async () => {
		const { user } = renderForm({ mode: 'edit', item: editableItem({ typeId: 't-totp', key: 'otpauth://totp/X?secret=JBSWY3DPEHPK3PXP&algorithm=SHA512&digits=8&period=45', login: null }) })
		await screen.findByLabelText('Period in seconds')
		const before = markup()
		await user.selectOptions(screen.getByLabelText('Digits'), '6')
		return { before, after: markup() }
	},
	'card members too big': async () => {
		const key = JSON.stringify({ number: wide(4096), expiry: wide(4096), cvv: wide(4096), pin: wide(4096), cardholder: wide(4096) })
		const { sent, user } = renderForm({ mode: 'edit', item: editableItem({ typeId: 't-card', key, login: null }) })
		await screen.findByLabelText('Number')
		fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Big card' } })
		await user.click(save())
		await screen.findByText(/bytes|too long/i)
		return { html: markup(), sent: writes(sent) }
	},
	'identity members': async () => {
		const key = JSON.stringify({ firstName: 'Alice', lastName: 'Doe', address: '', phone: '0612345678', email: 'a@example.org', bsn: '123456782' })
		renderForm({ mode: 'edit', item: editableItem({ typeId: 't-identity', key, login: null }) })
		await screen.findByLabelText('Email')
		return { html: markup() }
	},
	'card as one secret': async () => {
		renderForm({ mode: 'edit', item: editableItem({ typeId: 't-card', key: 'legacy card text', login: null }) })
		await screen.findByLabelText('Card')
		return { html: markup() }
	},
	'additional fields too big': async () => {
		const additionalFields = Object.fromEntries(['a', 'b', 'c', 'd', 'e'].map((name) => [name, wide(4096)]))
		const { sent, user } = renderForm({ mode: 'edit', item: editableItem({ additionalFields }) })
		await screen.findByLabelText('Name')
		fireEvent.change(screen.getByLabelText('Name'), { target: { value: 'Big fields' } })
		await user.click(save())
		await waitFor(() => expect(document.querySelectorAll('.field__error').length).toBeGreaterThan(0))
		return { html: markup(), sent: writes(sent) }
	},
}

describe('ItemForm markup golden', () => {
	if (process.env.RECORD_GOLDEN === '1') {
		it('records every world', async () => {
			const record: Record<string, Trace> = {}
			for (const [name, world] of Object.entries(WORLDS)) {
				record[name] = await world()
				cleanup()
				footer.remove()
				footer = document.body.appendChild(document.createElement('div'))
			}
			writeFileSync(GOLDEN, JSON.stringify(record, null, '\t') + '\n')
		}, 120_000)
		return
	}

	const golden = JSON.parse(readFileSync(GOLDEN, 'utf8')) as Record<string, Trace>

	it('covers every recorded world', () => {
		expect(Object.keys(golden).sort()).toEqual(Object.keys(WORLDS).sort())
	})

	for (const [name, world] of Object.entries(WORLDS)) {
		it(`renders as recorded: ${name}`, async () => {
			expect(await world()).toEqual(golden[name])
		}, 30_000)
	}
})
