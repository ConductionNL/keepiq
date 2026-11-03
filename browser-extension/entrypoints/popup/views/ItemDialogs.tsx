import { useState } from 'react'
import { i18n } from '#i18n'
import type { FolderMeta, ItemMeta, VaultWriteError } from '@/src/messages'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { writeErrorText } from '../errors'
import { request } from '../hooks/useMessage'
import { useShell } from '../shell-context'
import { FolderPicker } from './FolderPicker'

type Failure = { error: VaultWriteError | undefined } | null

const retryable = (failure: Failure) => !!failure && (!failure.error || failure.error.code === 'offline' || failure.error.code === 'server_error' || failure.error.code === 'write_locked')

/** To Keepiq's trash, which also ends the item's shares (ADR-003); restoring is the web app's. */
export function DeleteItemDialog({ item, onDeleted, onCancel }: { item: ItemMeta; onDeleted: () => Promise<void> | void; onCancel: () => void }) {
	const { accountId, refreshState } = useShell()
	const [busy, setBusy] = useState(false)
	const [failure, setFailure] = useState<Failure>(null)

	async function remove() {
		setBusy(true)
		setFailure(null)
		const reply = await request({ kind: 'item.delete', accountId, id: item.id })
		if (reply?.ok) return onDeleted()
		setBusy(false)
		if (reply?.error.code === 'locked' || reply?.error.code === 'session_revoked') refreshState()
		setFailure({ error: reply?.error })
	}

	return (
		<ConfirmDialog
			title={i18n.t('item.deleteTitle')}
			actions={[{ label: i18n.t('item.delete'), variant: 'danger', busy, onClick: () => void remove() }]}
			onCancel={onCancel}
			error={failure && writeErrorText(failure.error)}
			onRetry={retryable(failure) ? () => void remove() : undefined}
		>
			<p>{i18n.t('item.deleteConfirm', { name: item.name })}</p>
		</ConfirmDialog>
	)
}

/** Sends only `folderId`, which cannot overwrite another field, so it needs no fresh fetch. */
export function MoveItemDialog({ item, folders, onMoved, onCancel }: { item: ItemMeta; folders: FolderMeta[]; onMoved: (folderId: string | null) => Promise<void> | void; onCancel: () => void }) {
	const { accountId, refreshState } = useShell()
	const [busy, setBusy] = useState(false)
	const [failure, setFailure] = useState<Failure>(null)

	async function move(folderId: string | null) {
		if (folderId === item.folderId) return onCancel()
		setBusy(true)
		setFailure(null)
		const reply = await request({ kind: 'item.update', accountId, id: item.id, patch: { folderId } })
		if (reply?.ok) return onMoved(folderId)
		setBusy(false)
		if (reply?.error.code === 'locked' || reply?.error.code === 'session_revoked') refreshState()
		setFailure({ error: reply?.error })
	}

	return (
		<FolderPicker
			title={i18n.t('folders.moveTitle')}
			folders={folders}
			current={item.folderId}
			onPick={(folderId) => void move(folderId)}
			onCancel={onCancel}
			busy={busy}
			error={failure && writeErrorText(failure.error)}
		/>
	)
}
