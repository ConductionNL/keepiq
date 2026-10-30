import { useState } from 'react'
import { i18n } from '#i18n'
import type { VaultWriteError } from '@/src/messages'
import { writeErrorText } from '../errors'
import { request } from '../hooks/useMessage'
import { useShell } from '../shell-context'
import { ConfirmDialog } from './ConfirmDialog'
import { TextField } from './TextField'

type Target = { create: { parentId: string | null } } | { rename: { id: string; name: string } }

/** Create or rename one folder; the typed name survives every failure. `onDone` gets the new folder's id, `null` on rename. */
export function FolderNameDialog({ target, onDone, onCancel }: { target: Target; onDone: (id: string | null) => void; onCancel: () => void }) {
	const { accountId, refreshState } = useShell()
	const [name, setName] = useState('rename' in target ? target.rename.name : '')
	const [local, setLocal] = useState<string | null>(null)
	const [failure, setFailure] = useState<{ error: VaultWriteError | undefined } | null>(null)
	const [busy, setBusy] = useState(false)

	async function save() {
		const trimmed = name.trim()
		// The server's own rules, so a typo costs no round trip.
		if (!trimmed) return setLocal(i18n.t('fieldErrors.required'))
		if (trimmed.includes('/')) return setLocal(i18n.t('fieldErrors.slash'))
		setLocal(null)
		setBusy(true)
		const reply = 'create' in target
			? await request({ kind: 'folder.create', accountId, name: trimmed, parentId: target.create.parentId })
			: await request({ kind: 'folder.rename', accountId, id: target.rename.id, name: trimmed })
		setBusy(false)
		if (reply?.ok) return onDone(reply.value?.id ?? null)
		if (reply?.error.code === 'locked' || reply?.error.code === 'session_revoked') refreshState()
		setFailure({ error: reply?.error })
	}

	const retryable = !failure?.error || failure.error.code === 'offline' || failure.error.code === 'server_error'
	return (
		<ConfirmDialog
			title={'create' in target ? i18n.t('folders.newFolder') : i18n.t('folders.renameTitle')}
			actions={[{ label: i18n.t('form.save'), onClick: () => void save(), busy }]}
			onCancel={onCancel}
			error={failure && writeErrorText(failure.error, 'folderName')}
			onRetry={retryable ? () => void save() : undefined}
		>
			<form onSubmit={(event) => {
				event.preventDefault()
				void save()
			}}>
				<TextField label={i18n.t('folders.name')} value={name} onChange={setName} error={local} autoComplete="off" />
			</form>
		</ConfirmDialog>
	)
}
