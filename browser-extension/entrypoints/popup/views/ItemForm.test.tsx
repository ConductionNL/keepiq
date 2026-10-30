// @vitest-environment happy-dom
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import type { EditableItem, FolderMeta } from '@/src/messages'
import { CARD_FIELDS, IDENTITY_FIELDS, shownInFull } from '@/src/vault/fields'
import { setLocale } from '@/src/testing/i18n'
import { DEFAULT_SHELL, ShellContext } from '../shell-context'
import { editableItem, fakeBackground, sentOf, snapshotReply, types, type UnlockedSnapshot } from '../testing'
import { ItemForm, type FormMode } from './ItemForm'

let footer: HTMLElement
beforeEach(() => {
	footer = document.body.appendChild(document.createElement('div'))
})
afterEach(() => {
	cleanup()
	footer.remove()
})

const folders: FolderMeta[] = [{ id: 'w', name: 'Work', parentId: null }, { id: 'c', name: 'Clients', parentId: 'w' }]

interface Options {
	mode?: FormMode
	id?: string
	snapshot?: UnlockedSnapshot
	tabUrl?: string
	offline?: boolean
	items?: Record<string, EditableItem>
	replies?: Record<string, unknown>
}

function renderForm({ mode = 'add', id, snapshot = snapshotReply({ folders }), tabUrl, offline = false, items, replies }: Options = {}) {
	const sent = fakeBackground({ snapshot, items, replies })
	const shell = { ...DEFAULT_SHELL, footer, offline, toast: vi.fn(), refreshState: vi.fn(), syncNow: vi.fn(), setLeaveGuard: vi.fn(), generate: vi.fn() }
	const onSaved = vi.fn()
	const onCancel = vi.fn()
	const onGone = vi.fn()
	render(
		<ShellContext.Provider value={shell}>
			<ItemForm mode={mode} id={id} snapshot={snapshot} tabUrl={tabUrl} onSaved={onSaved} onCancel={onCancel} onGone={onGone} />
		</ShellContext.Provider>,
	)
	return { sent, shell, onSaved, onCancel, onGone, user: userEvent.setup() }
}

const save = () => within(footer).getByRole('button', { name: 'Save' })
const field = (label: string) => screen.getByLabelText(label) as HTMLInputElement
const created = (sent: ReturnType<typeof fakeBackground>) => sentOf(sent, 'item.create').map((message) => message.draft)

describe('adding', () => {
	it('opens on a tab with its URL, No folder and the login type', () => {
		renderForm({ tabUrl: 'https://example.com/login' })
		expect(field('Website URL').value).toBe('https://example.com/login')
		expect(screen.getByRole('button', { name: 'Choose a folder, now No folder' })).toBeTruthy()
		expect((screen.getByLabelText('Type') as HTMLSelectElement).value).toBe('t-login')
		for (const label of ['Name', 'Username', 'Password', 'Notes']) expect(field(label).value).toBe('')
	})

	it('preselects the user\'s default type and leaves passkeys out', () => {
		renderForm({ snapshot: snapshotReply({ defaultSecretType: 'note' }) })
		const type = screen.getByLabelText('Type') as HTMLSelectElement
		expect(type.value).toBe('t-note')
		expect([...type.options].map((option) => option.textContent)).not.toContain('Passkey')
		expect(screen.getByRole('textbox', { name: 'Note' })).toBeTruthy()
		expect(screen.queryByLabelText('Username')).toBeNull()
	})

	it('starts as a login when the default type is one the cached types lack', () => {
		renderForm({ snapshot: snapshotReply({ defaultSecretType: 'wifi' }) })
		expect((screen.getByLabelText('Type') as HTMLSelectElement).value).toBe('t-login')
	})

	it('refuses a missing name without a request', async () => {
		const { sent, user } = renderForm()
		await user.click(save())
		expect(screen.getByText('Name is required')).toBeTruthy()
		expect(created(sent)).toEqual([])
	})

	it('creates a login with its notes and fields, then hands over the new id', async () => {
		const { sent, onSaved, user } = renderForm({ tabUrl: 'https://github.com/login' })
		await user.type(field('Name'), 'GitHub')
		await user.type(field('Username'), 'alice')
		await user.type(field('Password'), 'hunter2')
		await user.type(field('Notes'), 'Recovery codes in the safe')
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(field('Field name'), 'pin')
		await user.type(field('pin'), '1234')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new', undefined))
		expect(created(sent)).toEqual([{
			typeId: 't-login', name: 'GitHub', url: 'https://github.com/login', folderId: null, key: 'hunter2', login: 'alice',
			additionalFields: { pin: '1234', notes: 'Recovery codes in the safe' },
		}])
	})

	it('refuses a reserved or duplicate field name while typing', async () => {
		const { user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(screen.getAllByLabelText('Field name')[0]!, 'Login')
		expect(screen.getByText('This name is reserved')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
		await user.clear(screen.getAllByLabelText('Field name')[0]!)
		await user.type(screen.getAllByLabelText('Field name')[0]!, 'pin')
		await user.click(screen.getByRole('button', { name: 'Add field' }))
		await user.type(screen.getAllByLabelText('Field name')[1]!, 'pin')
		expect(screen.getByText('A field with this name already exists')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
	})

	it('refuses an invalid authenticator secret', async () => {
		const { sent, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-totp')
		await user.type(field('Name'), 'GitHub 2FA')
		await user.type(field('Authenticator key'), 'not a secret!')
		await user.click(save())
		expect(screen.getByText('Not a valid authenticator secret')).toBeTruthy()
		expect(created(sent)).toEqual([])
	})

	it('writes changed TOTP options into the seed', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-totp')
		await user.type(field('Name'), 'GitHub')
		await user.type(field('Authenticator key'), 'JBSWY3DPEHPK3PXP')
		await user.click(screen.getByText('Advanced'))
		await user.selectOptions(screen.getByLabelText('Digits'), '8')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ key: 'otpauth://totp/GitHub?secret=JBSWY3DPEHPK3PXP&algorithm=SHA1&digits=8&period=30', login: null })
	})

	it('writes a card with all five members', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-card')
		await user.type(field('Name'), 'Visa')
		await user.type(field('Number'), '4111111111111111')
		await user.type(field('Expiry'), '12/29')
		await user.type(field('Cardholder'), 'Alice Doe')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(JSON.parse((created(sent)[0] as { key: string }).key)).toEqual({ number: '4111111111111111', expiry: '12/29', cvv: '', pin: '', cardholder: 'Alice Doe' })
	})

	it('keeps a note and the notes from the spell checker, which may send them to a third party', async () => {
		const { user } = renderForm()
		expect(field('Notes').getAttribute('spellcheck')).toBe('false')
		await user.selectOptions(screen.getByLabelText('Type'), 't-note')
		expect(field('Note').getAttribute('spellcheck')).toBe('false')
	})

	it.each([
		['login', ['Username', 'Website URL', 'Field name']],
		['card', ['Website URL', 'Field name']],
		['identity', ['Phone', 'Email', 'Website URL', 'Field name']],
		['totp', ['Website URL', 'Field name']],
	])('keeps every %s field from the spell checker, a revealed secret too, and %j from a phone keyboard\'s corrections', async (typeName, verbatim) => {
		const { user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), `t-${typeName}`)
		await user.click(await screen.findByRole('button', { name: 'Add field' }))
		for (const show of screen.getAllByRole('button', { name: /^Show / })) await user.click(show)
		const inputs = [...document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea')]
		expect(inputs.filter((input) => input.type === 'text').length).toBeGreaterThan(2)
		expect(inputs.filter((input) => input.getAttribute('spellcheck') !== 'false').map((input) => input.id)).toEqual([])
		for (const label of verbatim) expect([label, field(label).getAttribute('autocapitalize'), field(label).getAttribute('autocorrect')]).toEqual([label, 'off', 'off'])
	})

	it('keeps a username shown under the autofill options from a phone keyboard\'s corrections', async () => {
		renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-note', key: 'Wifi', login: 'alice' }) } })
		const username = await screen.findByLabelText('Username')
		expect([username.getAttribute('autocapitalize'), username.getAttribute('autocorrect')]).toEqual(['off', 'off'])
	})

	it('gives an identity\'s email and phone the keyboards for them', async () => {
		const { user } = renderForm()
		await user.selectOptions(screen.getByLabelText('Type'), 't-identity')
		expect([field('Email').getAttribute('inputmode'), field('Phone').getAttribute('inputmode')]).toEqual(['email', 'tel'])
	})

	it('reports itself dirty to the leave guard once something is typed', async () => {
		const { shell, user } = renderForm({ tabUrl: 'https://github.com/' })
		const isDirty = shell.setLeaveGuard.mock.calls[0]![0] as () => boolean
		expect(isDirty()).toBe(false)
		await user.type(field('Name'), 'x')
		expect(isDirty()).toBe(true)
	})
})

describe('generate buttons', () => {
	const withTypes = (...names: string[]) => snapshotReply({ types: [...types, ...names.map((name) => ({ id: `t-${name}`, name, label: name }))] })

	it('opens pick mode for the password and the username, and fills in what comes back', async () => {
		const { shell, user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Generate password' }))
		expect(shell.generate).toHaveBeenCalledWith('password', expect.any(Function), screen.getByRole('button', { name: 'Generate password' }))
		act(() => shell.generate.mock.calls[0]![1]('Gen3rated!'))
		expect(field('Password').value).toBe('Gen3rated!')

		await user.click(screen.getByRole('button', { name: 'Generate username' }))
		expect(shell.generate).toHaveBeenLastCalledWith('username', expect.any(Function), screen.getByRole('button', { name: 'Generate username' }))
		act(() => shell.generate.mock.calls[1]![1]('brave-otter'))
		expect(field('Username').value).toBe('brave-otter')
		expect(field('Password').value).toBe('Gen3rated!')
	})

	it.each(['database', 'api_key', 'custom_thing'])('offers the password button on the credential type %s', async (name) => {
		const snapshot = withTypes('database', 'custom_thing')
		renderForm({ snapshot })
		await userEvent.setup().selectOptions(screen.getByLabelText('Type'), `t-${name}`)
		expect(screen.getByRole('button', { name: 'Generate password' })).toBeTruthy()
	})

	it('offers no button on a multiline key, which a generated value would not fit, but keeps the username one', async () => {
		renderForm({ snapshot: withTypes('ssh_key') })
		await userEvent.setup().selectOptions(screen.getByLabelText('Type'), 't-ssh_key')
		expect(screen.getByLabelText('ssh_key').tagName).toBe('TEXTAREA')
		expect(screen.queryByRole('button', { name: 'Generate password' })).toBeNull()
		expect(screen.getByRole('button', { name: 'Generate username' })).toBeTruthy()
	})

	it.each(['note', 'card'])('offers no generate button on %s', async (name) => {
		renderForm()
		await userEvent.setup().selectOptions(screen.getByLabelText('Type'), `t-${name}`)
		expect(screen.queryByRole('button', { name: 'Generate password' })).toBeNull()
		expect(screen.queryByRole('button', { name: 'Generate username' })).toBeNull()
	})
})

describe('folders', () => {
	it('picks a nested folder without a request', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		const dialog = screen.getByRole('dialog', { name: 'Choose folder' })
		expect(within(dialog).getAllByRole('button').map((button) => button.textContent)).toEqual(['No folderCurrent folder', 'Work', 'Clients', 'New folder', 'Cancel'])
		await user.click(within(dialog).getByRole('button', { name: 'Clients' }))
		expect(screen.getByRole('button', { name: 'Choose a folder, now Work / Clients' })).toBeTruthy()
		expect(sentOf(sent, 'folder.create')).toEqual([])
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ folderId: 'c' })
	})

	it('creates a folder from the picker and keeps the other fields', async () => {
		const { sent, onSaved, user } = renderForm()
		await user.type(field('Name'), 'Bank')
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(field('Folder name'), 'Banking')
		await user.click(within(screen.getByRole('dialog', { name: 'New folder' })).getByRole('button', { name: 'Save' }))
		await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull())
		expect(sentOf(sent, 'folder.create')).toEqual([{ kind: 'folder.create', accountId: 'a1', name: 'Banking', parentId: null }])
		expect(field('Name').value).toBe('Bank')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(created(sent)[0]).toMatchObject({ folderId: 'f-new' })
	})

	it('refuses a slash in a new folder name', async () => {
		const { sent, user } = renderForm()
		await user.click(screen.getByRole('button', { name: 'Choose a folder, now No folder' }))
		await user.click(screen.getByRole('button', { name: 'New folder' }))
		await user.type(field('Folder name'), 'Work/Clients')
		await user.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Save' }))
		expect(screen.getByText('Folder names cannot contain slashes')).toBeTruthy()
		expect(sentOf(sent, 'folder.create')).toEqual([])
	})
})

describe('editing', () => {
	it('fills from a fresh fetch and sends only the rename with the base version and the fetch\'s record', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ additionalFields: { notes: 'n' }, recordId: 'r1' }) } })
		expect((await screen.findByLabelText('Username') as HTMLInputElement).value).toBe('alice')
		expect(field('Notes').value).toBe('n')
		expect((screen.getByLabelText('Type') as HTMLSelectElement).disabled).toBe(true)
		await user.type(field('Name'), ' work')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1', undefined))
		expect(sentOf(sent, 'item.update')).toEqual([{ kind: 'item.update', accountId: 'a1', id: 'i1', patch: { name: 'GitHub work' }, baseUpdatedAt: '2026-03-02T10:00:00Z', recordId: 'r1' }])
	})

	it("hands the save's note to the shell", async () => {
		const shareSave = { ownerOnly: true }
		const { onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: true, value: { id: 'i1', shareSave } } } })
		await user.type(await screen.findByLabelText('Username'), '2')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1', shareSave))
	})

	describe('when a partner organisation cannot be reached', () => {
		const unreachable = (...cloudIds: string[]) => ({ ok: false, error: { code: 'partner_unreachable', partners: cloudIds.map((cloudId, i) => ({ shareId: `fs${i}`, cloudId })) } })

		it('names the partners and saves the same draft without them', async () => {
			let attempts = 0
			const { sent, onSaved, user } = renderForm({
				mode: 'edit', id: 'i1', items: { i1: editableItem({ recordId: 'r1' }) },
				replies: { 'item.update': () => (++attempts === 1 ? unreachable('bob@cloud.partner.example', 'carol@cloud.partner.example') : { ok: true, value: { id: 'i1' } }) },
			})
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			const alert = await screen.findByRole('alert')
			expect(alert.textContent).toContain("a partner organisation this item is shared with can't be reached (bob@cloud.partner.example and carol@cloud.partner.example)")
			expect(within(alert).queryByRole('button', { name: 'Try again' })).toBeNull()
			await user.click(within(alert).getByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1', undefined))
			const [first, second] = sentOf(sent, 'item.update')
			expect(first).not.toHaveProperty('withoutUnreachable')
			expect(second).toEqual({ ...first, withoutUnreachable: ['fs0', 'fs1'] })
		})

		it('offers Save without them again for a partner that fails only then', async () => {
			let attempts = 0
			const { sent, user } = renderForm({
				mode: 'edit', id: 'i1', items: { i1: editableItem() },
				replies: { 'item.update': () => (++attempts === 1 ? unreachable('bob@cloud.partner.example') : unreachable('bob@cloud.partner.example', 'carol@cloud.partner.example')) },
			})
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('carol@cloud.partner.example'))
			await user.click(screen.getByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			expect(sentOf(sent, 'item.update')[2]).toMatchObject({ withoutUnreachable: ['fs0', 'fs1'] })
		})

		const bob = { shareId: 'fs-bob', cloudId: 'bob@cloud.partner.example' }
		const carol = { shareId: 'fs-carol', cloudId: 'carol@cloud.partner.example' }
		/** Bob fails the first save, Carol every later one. */
		const bobThenCarol = () => {
			let attempts = 0
			return renderForm({
				mode: 'edit', id: 'i1', items: { i1: editableItem() },
				replies: { 'item.update': () => ({ ok: false, error: { code: 'partner_unreachable', partners: [++attempts === 1 ? bob : carol] } }) },
			})
		}

		it('keeps the partners already agreed to when another one fails, and names them all', async () => {
			const { sent, user } = bobThenCarol()
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('(bob@cloud.partner.example and carol@cloud.partner.example)'))
			await user.click(screen.getByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			expect(sentOf(sent, 'item.update').map((message) => message.withoutUnreachable)).toEqual([undefined, ['fs-bob'], ['fs-bob', 'fs-carol']])
		})

		it('asks again about every partner once the username, password or fields change', async () => {
			const { sent, user } = bobThenCarol()
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(2))
			await user.type(screen.getByLabelText('Username'), '3')
			await user.click(save())
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('(carol@cloud.partner.example)'))
			await user.click(screen.getByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(4))
			expect(sentOf(sent, 'item.update')[3]).toMatchObject({ withoutUnreachable: ['fs-carol'] })
		})

		it('asks again about every partner once a username it did not change is cleared', async () => {
			const { sent, user } = bobThenCarol()
			await user.type(await screen.findByLabelText('Password'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(2))
			await user.clear(screen.getByLabelText('Username'))
			await user.click(save())
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			expect(sentOf(sent, 'item.update')[2]).toMatchObject({ patch: { key: 'hunter22', login: null } })
			await waitFor(() => expect(screen.getByRole('alert').textContent).toContain('(carol@cloud.partner.example)'))
		})

		it('tries a Save without them again as it was, without asking again', async () => {
			let attempts = 0
			const { sent, user } = renderForm({
				mode: 'edit', id: 'i1', items: { i1: editableItem() },
				replies: { 'item.update': () => (++attempts === 1 ? { ok: false, error: { code: 'partner_unreachable', partners: [bob] } } : { ok: false, error: { code: 'offline' } }) },
			})
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await user.click(await screen.findByRole('button', { name: 'Try again' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			expect(sentOf(sent, 'item.update').map((message) => message.withoutUnreachable)).toEqual([undefined, ['fs-bob'], ['fs-bob']])
		})

		it('tries it again without them only while the username, password and fields are the same', async () => {
			let attempts = 0
			const { sent, user } = renderForm({
				mode: 'edit', id: 'i1', items: { i1: editableItem() },
				replies: { 'item.update': () => (++attempts === 1 ? { ok: false, error: { code: 'partner_unreachable', partners: [bob] } } : { ok: false, error: { code: 'offline' } }) },
			})
			await user.type(await screen.findByLabelText('Username'), '2')
			await user.click(save())
			await user.click(await screen.findByRole('button', { name: 'Save without them' }))
			await screen.findByRole('button', { name: 'Try again' })
			await user.type(screen.getByLabelText('Username'), '3')
			await user.click(screen.getByRole('button', { name: 'Try again' }))
			await waitFor(() => expect(sentOf(sent, 'item.update')).toHaveLength(3))
			expect(sentOf(sent, 'item.update')[2]).not.toHaveProperty('withoutUnreachable')
		})
	})

	it('points to the web app when partners could not get the item as shown', async () => {
		const { user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: false, error: { code: 'unverified' } } } })
		await user.type(await screen.findByLabelText('Username'), '2')
		await user.click(save())
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toContain("this browser can't confirm they would get it exactly as you saw it. Nothing was saved; make this change in the Keepiq web app.")
		expect(within(alert).getByRole('link', { name: 'Open the Keepiq web app' }).getAttribute('href')).toBe(snapshotReply().webAppUrl)
	})

	it('offers no Try again when the server cannot share with partners', async () => {
		const { user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: false, error: { code: 'federation_unavailable' } } } })
		await user.type(await screen.findByLabelText('Username'), '2')
		await user.click(save())
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toBe("Your Keepiq server can't share with partner organisations, so this item can't be saved here. Nothing was saved; ask your administrator.")
		expect(within(alert).queryByRole('button')).toBeNull()
	})

	it.each([
		['en', 'Shared with you to view only. Ask its owner for edit rights to change it.'],
		['nl', 'Met je gedeeld om alleen te bekijken. Vraag de eigenaar om bewerkrechten om het te wijzigen.'],
	] as const)('in %s, says a copy became view-only since the form opened, keeping the values and offering no Try again', async (locale, text) => {
		setLocale(locale)
		const { onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: false, error: { code: 'view_only' } } } })
		await user.type(await screen.findByLabelText(locale === 'en' ? 'Username' : 'Gebruikersnaam'), '2')
		await user.click(within(footer).getByRole('button', { name: locale === 'en' ? 'Save' : 'Opslaan' }))
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toBe(text)
		expect(within(alert).queryByRole('button')).toBeNull()
		expect(field(locale === 'en' ? 'Username' : 'Gebruikersnaam').value).toBe('alice2')
		expect(onSaved).not.toHaveBeenCalled()
	})

	it('says why a view-only copy does not open for editing', async () => {
		renderForm({ mode: 'edit', id: 'i1', replies: { 'item.fetch': { ok: false, error: { code: 'view_only' } } } })
		expect((await screen.findByRole('alert')).textContent).toBe('Shared with you to view only. Ask its owner for edit rights to change it.')
		expect(screen.queryByLabelText('Name')).toBeNull()
	})

	it('clears an emptied username with null', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() } })
		await user.clear(await screen.findByLabelText('Username'))
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { login: null } })
	})

	it('closes without a request when nothing changed', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() } })
		await screen.findByLabelText('Username')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('i1'))
		expect(sentOf(sent, 'item.update')).toEqual([])
	})

	it('keeps the typed values when the item changed elsewhere', async () => {
		const { onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem() }, replies: { 'item.update': { ok: false, error: { code: 'conflict' } } } })
		await user.type(await screen.findByLabelText('Username'), '2')
		await user.click(save())
		expect(await screen.findByText('This item was changed elsewhere. Go back and edit it again to see the latest version.')).toBeTruthy()
		expect(field('Username').value).toBe('alice2')
		expect(onSaved).not.toHaveBeenCalled()
	})

	it('keeps the values on a write lock', async () => {
		const { user } = renderForm({ replies: { 'item.create': { ok: false, error: { code: 'write_locked' } } } })
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		expect(await screen.findByText('Vault is temporarily locked for a key migration, try again later')).toBeTruthy()
		expect(field('Name').value).toBe('GitHub')
	})

	it('offers Retry when the server could not be reached', async () => {
		let attempts = 0
		const { onSaved, user } = renderForm({ replies: { 'item.create': () => (++attempts === 1 ? { ok: false, error: { code: 'offline' } } : { ok: true, value: { id: 'new' } }) } })
		await user.type(field('Name'), 'GitHub')
		await user.click(save())
		await user.click(await screen.findByRole('button', { name: 'Try again' }))
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new', undefined))
	})

	it.each([
		['a passkey', 't-passkey'],
		['a passkey the server sends as a login', 't-login'],
	])('edits only name, folder and URL of %s', async (_, typeId) => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'p', items: { p: editableItem({ id: 'p', typeId, typeName: 'passkey', key: '', login: null }) } })
		expect(await screen.findByText('Passkey credentials can only be changed in the Keepiq web app')).toBeTruthy()
		for (const label of ['Username', 'Password', 'Notes', 'Field name']) expect(screen.queryByLabelText(label)).toBeNull()
		expect(screen.queryByRole('button', { name: 'Add field' })).toBeNull()
		await user.type(field('Name'), '!')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { name: 'GitHub!' } })
	})

	it('leaves when the item was deleted elsewhere', async () => {
		const { shell, onGone } = renderForm({ mode: 'edit', id: 'gone' })
		await waitFor(() => expect(onGone).toHaveBeenCalled())
		expect(shell.toast).toHaveBeenCalledWith('This item no longer exists')
	})

	it('refuses to edit a blocked item and says why', async () => {
		renderForm({ mode: 'edit', id: 'b', replies: { 'item.fetch': { ok: false, error: { code: 'blocked', reason: 'suite_revoked' } } } })
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toContain('Its encryption key was revoked.')
		expect(within(alert).getByRole('link', { name: 'Open the Keepiq web app' }).getAttribute('href')).toBe('https://cloud.example.org/index.php/apps/keepiq/')
		expect(screen.queryByLabelText('Name')).toBeNull()
	})

	it('says when the fetched item does not match the vault, and fetches again on Try again', async () => {
		let healed = false
		const { user } = renderForm({
			mode: 'edit', id: 'i1',
			replies: { 'item.fetch': () => (healed ? { ok: true, value: editableItem() } : { ok: false, error: { code: 'conflict' } }) },
		})
		const alert = await screen.findByRole('alert')
		expect(alert.textContent).toContain('Keepiq sent a version of this item that your vault does not hold, so it was not opened.')
		expect(screen.queryByLabelText('Name')).toBeNull()
		healed = true
		await user.click(within(alert).getByRole('button', { name: 'Try again' }))
		expect((await screen.findByLabelText('Name') as HTMLInputElement).value).toBe('GitHub')
	})
})

describe('cloning', () => {
	it('prefills every field with the name suffixed and creates a new item', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'clone', id: 'i1', items: { i1: editableItem({ folderId: 'w', additionalFields: { pin: '1' } }) } })
		expect((await screen.findByLabelText('Name') as HTMLInputElement).value).toBe('GitHub - Clone')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new', undefined))
		expect(created(sent)).toEqual([{
			typeId: 't-login', name: 'GitHub - Clone', url: 'https://github.com', folderId: 'w', key: 'hunter2', login: 'alice', additionalFields: { pin: '1' },
		}])
		expect(sentOf(sent, 'item.update')).toEqual([])
	})

	it('renders by the type the fetch opened it under until another is picked, so a rename since drops nothing', async () => {
		// The snapshot calls the type a passkey by now, which would hide the password and drop the username.
		const { sent, onSaved, user } = renderForm({ mode: 'clone', id: 'i1', items: { i1: editableItem({ typeId: 't-passkey', typeName: 'login' }) } })
		expect((await screen.findByLabelText('Password') as HTMLInputElement).value).toBe('hunter2')
		expect(field('Username').value).toBe('alice')
		expect(screen.queryByText('Passkey credentials can only be changed in the Keepiq web app')).toBeNull()
		const type = screen.getByLabelText('Type') as HTMLSelectElement
		expect([type.disabled, type.selectedOptions[0]!.textContent]).toEqual([false, 'Login'])
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalledWith('new', undefined))
		expect(created(sent)[0]).toMatchObject({ typeId: 't-passkey', key: 'hunter2', login: 'alice' })
	})

	it('renders by the snapshot once another type is picked', async () => {
		const { user } = renderForm({ mode: 'clone', id: 'i1', items: { i1: editableItem({ typeId: 't-card', typeName: 'login', key: '{"number":"4111"}', login: null }) } })
		expect((await screen.findByLabelText('Password') as HTMLInputElement).value).toBe('{"number":"4111"}')
		await user.selectOptions(screen.getByLabelText('Type'), 't-identity')
		expect(screen.getByRole('heading', { name: 'Identity' })).toBeTruthy()
	})
})

describe('what an edit shows', () => {
	const FIELDS = ['key', 'login', 'additionalFields'] as const
	const card = (extra = '') => `{"number":"KEY-SEEN-1","expiry":"","cvv":"","pin":"","cardholder":""${extra}}`
	const identity = '{"firstName":"KEY-SEEN-1","lastName":"","address":"","phone":"","email":"","bsn":""}'
	/** Every value the form holds, in inputs and text areas alike. */
	const shownValues = () => [...document.querySelectorAll<HTMLInputElement | HTMLTextAreaElement>('input, textarea')].map((input) => input.value)
	const markersOf = (plaintext: string) => plaintext.match(/[A-Z]+-SEEN-\d/g)!

	/** The background records a field only when `shownInFull` says so, so it must find every marker in the form exactly then. */
	it.each([
		['login', 'KEY-SEEN-1'], ['api_key', 'KEY-SEEN-1'], ['note', 'KEY-SEEN-1'], ['totp', 'KEY-SEEN-1'], ['passkey', 'KEY-SEEN-1'],
		['card', card()], ['card', 'KEY-SEEN-1'], ['card', card(',"recovery":"KEY-SEEN-2"')], ['identity', identity], ['identity', card()],
	])('agrees with shownInFull for a %s whose key is %s', async (typeName, key) => {
		const plain = { key, login: 'LOGIN-SEEN-1', additionalFields: '{"pin":"FIELDS-SEEN-1","notes":"FIELDS-SEEN-2"}' }
		renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: `t-${typeName}`, key, login: plain.login, additionalFields: JSON.parse(plain.additionalFields) }) } })
		await screen.findByLabelText('Name')
		const shown = shownValues().join('\n')
		for (const field of FIELDS) {
			expect([field, markersOf(plain[field]).every((marker) => shown.includes(marker))]).toEqual([field, shownInFull(field, plain[field], typeName)])
		}
	})

	/** A value in every part `shownInFull` can count, so hiding any of them fails here. */
	const sampleKey = (typeName: string) => {
		const members = typeName === 'card' ? CARD_FIELDS : typeName === 'identity' ? IDENTITY_FIELDS : null
		return members ? JSON.stringify(Object.fromEntries(members.map((member, i) => [member, `KEY-SEEN-${i}`]))) : 'KEY-SEEN-0'
	}

	it.each(types.map((type) => type.name))('shows each value of a %s item that shownInFull counts, which is all of it but a passkey\'s', async (typeName) => {
		const plain = { key: sampleKey(typeName), login: 'LOGIN-SEEN-0', additionalFields: '{"pin":"FIELDS-SEEN-0","api":"FIELDS-SEEN-1","Notes":"FIELDS-SEEN-2"}' }
		const passkey = typeName === 'passkey'
		renderForm({
			mode: 'edit', id: 'i1',
			items: { i1: editableItem({ typeId: `t-${typeName}`, key: passkey ? '' : plain.key, login: plain.login, additionalFields: JSON.parse(plain.additionalFields) }) },
		})
		await screen.findByLabelText('Name')
		const counted = FIELDS.filter((field) => shownInFull(field, plain[field], typeName))
		expect(counted).toEqual(passkey ? [] : FIELDS)
		const shown = shownValues()
		for (const field of counted) for (const marker of markersOf(plain[field])) expect(shown).toContain(marker)
	})

	it('shows the username a note came with, keeps it while cleared, and clears it with null', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-note', key: 'Wifi', login: 'alice' }) } })
		await user.clear(await screen.findByLabelText('Username'))
		expect(field('Username').value).toBe('')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { login: null } })
	})

	it('shows a card key that is not a JSON object as one secret and saves it as typed', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-card', key: 'legacy card text', login: null }) } })
		expect((await screen.findByLabelText('Card') as HTMLInputElement).value).toBe('legacy card text')
		expect(screen.queryByLabelText('Number')).toBeNull()
		await user.type(field('Card'), '!')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { key: 'legacy card text!' } })
	})

	/** A multi-line value as it is, which the default normalizer would join. */
	const holding = (value: string) => screen.queryByDisplayValue(value, { normalizer: (shown) => shown })

	it.each([
		['LF', 'card', 'Card', 'line1\nline2'],
		['LF', 'identity', 'Identity', 'line1\nline2'],
		['LF', 'login', 'Password', 'line1\nline2'],
		['CR', 'login', 'Password', 'line1\rline2'],
		['CRLF', 'login', 'Password', 'line1\r\nline2'],
	])('keeps the %s lines of a %s key through an edit, masked until shown', async (_, name, label, key) => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: `t-${name}`, key, login: null }) } })
		await user.click(await screen.findByRole('button', { name: `Show ${label.toLowerCase()}` }))
		const area = field(label)
		expect(area.tagName).toBe('TEXTAREA')
		expect(area.value).toBe(key)
		await user.type(area, '!')
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch: { key: `${key}!` } })
	})

	// happy-dom hands a text area's value back unchanged, where a browser reads every break as LF; this sends what a browser would.
	it.each([
		['CRLF', 'Password', { key: 'a\r\nb' }, 'a\nb!', { key: 'a\r\nb!' }],
		['CR', 'Password', { key: 'a\rb' }, 'a\nb!', { key: 'a\rb!' }],
		['CRLF', 'Notes', { additionalFields: { notes: 'a\r\nb' } }, 'a\nb!', { additionalFields: { notes: 'a\r\nb!' } }],
		['CR', 'Notes', { additionalFields: { notes: 'a\rb' } }, 'a\nb!', { additionalFields: { notes: 'a\rb!' } }],
		['mixed', 'Password', { key: 'a\r\nb\nc' }, 'a\nb\nc!', { key: 'a\nb\nc!' }],
	])('gives %s breaks back to the %s a browser hands over as LF', async (_, label, item, typed, patch) => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ login: null, ...item }) } })
		await screen.findByLabelText('Name')
		const show = screen.queryByRole('button', { name: `Show ${label.toLowerCase()}` })
		if (show) await user.click(show)
		fireEvent.change(field(label), { target: { value: typed } })
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		expect(sentOf(sent, 'item.update')[0]).toMatchObject({ patch })
	})

	it.each([
		['Login', 't-login', 'Password'],
		['Authenticator', 't-totp', 'Authenticator key'],
	])('keeps the lines of a key typed under SSH key once switched to %s', async (_, typeId, label) => {
		const snapshot = snapshotReply({ types: [...types, { id: 't-ssh_key', name: 'ssh_key', label: 'SSH key' }] })
		const { user } = renderForm({ snapshot })
		await user.selectOptions(screen.getByLabelText('Type'), 't-ssh_key')
		await user.type(field('SSH key'), 'line1{Enter}line2')
		await user.selectOptions(screen.getByLabelText('Type'), typeId)
		const show = screen.queryByRole('button', { name: `Show ${label.toLowerCase()}` })
		if (show) await user.click(show)
		expect([field(label).tagName, field(label).value]).toEqual(['TEXTAREA', 'line1\nline2'])
		await user.clear(field(label))
		expect(field(label).tagName).toBe('TEXTAREA')
	})

	it.each([
		['login', 'Password', 'password', 'line1\nline2'],
		['card', 'Card', 'card', 'line1\r\nline2'],
		['ssh_key', 'SSH key', 'SSH key', '-----BEGIN OPENSSH PRIVATE KEY-----'],
		['totp', 'Authenticator key', 'authenticator key', 'JBSW Y3DP\r\nEHPK 3PXP'],
	])('opens a %s key behind dots, as a password field would, with a toggle', async (name, label, named, key) => {
		const snapshot = snapshotReply({ types: [...types, { id: 't-ssh_key', name: 'ssh_key', label: 'SSH key' }] })
		const { user } = renderForm({ mode: 'edit', id: 'i1', snapshot, items: { i1: editableItem({ typeId: `t-${name}`, typeName: name, key, login: null }) } })
		const show = await screen.findByRole('button', { name: `Show ${named}` })
		expect(holding(key)).toBeNull()
		expect(document.body.textContent).not.toContain('line1')
		expect(document.body.textContent).not.toContain('BEGIN')
		expect(show.getAttribute('aria-pressed')).toBe('false')
		await user.click(show)
		expect([field(label).tagName, field(label).value]).toEqual(['TEXTAREA', key])
		expect(field(label).getAttribute('spellcheck')).toBe('false')
		await user.click(screen.getByRole('button', { name: `Hide ${named}` }))
		expect(holding(key)).toBeNull()
	})

	it('opens an empty multi-line secret ready to type in', async () => {
		const snapshot = snapshotReply({ types: [...types, { id: 't-ssh_key', name: 'ssh_key', label: 'SSH key' }] })
		const { user } = renderForm({ snapshot })
		await user.selectOptions(screen.getByLabelText('Type'), 't-ssh_key')
		expect(field('SSH key').tagName).toBe('TEXTAREA')
		expect(screen.getByRole('button', { name: 'Hide SSH key' })).toBeTruthy()
	})

	it('shows in full what the fetch vouched for under its type, whatever the snapshot calls that type', async () => {
		// The fetch's sync had the server call card type `t-card` a login, which vouched for item Y's identity in card X as one secret.
		// The snapshot still calls it a card, under which the form would show blank card fields instead.
		const y = '{"firstName":"Y-IDENTITY"}'
		expect([shownInFull('key', y, 'login'), shownInFull('key', y, 'card')]).toEqual([true, false])
		renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-card', typeName: 'login', key: y, login: null }) } })
		expect((await screen.findByLabelText('Password') as HTMLInputElement).value).toBe(y)
		expect(screen.queryByRole('heading', { name: 'Card details' })).toBeNull()
		expect(screen.queryByLabelText('Number')).toBeNull()
		const type = screen.getByLabelText('Type') as HTMLSelectElement
		expect(type.selectedOptions[0]!.textContent).toBe('Login')
	})

	it('shows a plain key the fetch vouched for under card as one secret, whatever the snapshot calls that type', async () => {
		renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-login', typeName: 'card', key: 'legacy card text', login: null }) } })
		expect((await screen.findByLabelText('Card') as HTMLInputElement).value).toBe('legacy card text')
		expect(screen.getByRole('heading', { name: 'Card details' })).toBeTruthy()
		expect(screen.queryByLabelText('Number')).toBeNull()
		expect(screen.queryByLabelText('Password')).toBeNull()
		expect((screen.getByLabelText('Type') as HTMLSelectElement).selectedOptions[0]!.textContent).toBe('Card')
	})

	it('never sends a note\'s notes the form does not show, even another item\'s', async () => {
		const { sent, onSaved, user } = renderForm({ mode: 'edit', id: 'i1', items: { i1: editableItem({ typeId: 't-note', key: 'Wifi', login: null, additionalFields: { notes: 'Y-RECOVERY-CODES' } }) } })
		await user.click(await screen.findByRole('button', { name: 'Add field' }))
		await user.type(field('Field name'), 'pin')
		await user.type(field('pin'), '1')
		const shown = shownValues()
		await user.click(save())
		await waitFor(() => expect(onSaved).toHaveBeenCalled())
		const { additionalFields } = sentOf(sent, 'item.update')[0]!.patch as { additionalFields: Record<string, string> }
		expect(additionalFields).toEqual({ pin: '1', notes: 'Y-RECOVERY-CODES' })
		for (const value of Object.values(additionalFields)) expect(shown).toContain(value)
	})
})

describe('offline', () => {
	it('disables Save and explains why, with a way to retry', async () => {
		const { shell, user } = renderForm({ offline: true })
		expect(screen.getByText('You are offline. Changes need a connection to Keepiq.')).toBeTruthy()
		expect((save() as HTMLButtonElement).disabled).toBe(true)
		await user.click(screen.getByRole('button', { name: 'Sync now' }))
		expect(shell.syncNow).toHaveBeenCalled()
	})
})
