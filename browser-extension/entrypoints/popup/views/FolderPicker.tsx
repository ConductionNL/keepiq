import { useState } from 'react'
import { i18n } from '#i18n'
import type { FolderMeta } from '@/src/messages'
import { ConfirmDialog } from '../components/ConfirmDialog'
import { FolderNameDialog } from '../components/FolderNameDialog'
import { FolderTree } from '../components/FolderTree'
import { Icon } from '../components/Icon'

interface Props {
	title: string
	folders: FolderMeta[]
	current: string | null
	/** `null` is "No folder". */
	onPick: (folderId: string | null) => void
	onCancel: () => void
	/** For a caller that writes the pick, as Move does. */
	busy?: boolean
	error?: string | null
}

function Choice({ label, current, onClick, disabled }: { label: string; current: boolean; onClick: () => void; disabled?: boolean }) {
	return (
		<button type="button" className="picker__choice" aria-current={current ? 'true' : undefined} disabled={disabled} onClick={onClick}>
			<Icon name="folder" />
			<span className="picker__label">{label}</span>
			{current && <span className="picker__current"><Icon name="check" /><span className="sr-only">{i18n.t('folders.current')}</span></span>}
		</button>
	)
}

/** Picking sends nothing; "New folder" creates one at the root and picks it. */
export function FolderPicker({ title, folders, current, onPick, onCancel, busy = false, error }: Props) {
	const [creating, setCreating] = useState(false)
	if (creating) {
		// Back to the picker once the folder exists, so a failed pick shows its error there and Save cannot create it twice.
		const done = (id: string | null) => {
			setCreating(false)
			if (id) onPick(id)
		}
		return <FolderNameDialog target={{ create: { parentId: null } }} onDone={done} onCancel={() => setCreating(false)} />
	}
	return (
		<ConfirmDialog title={title} actions={[]} onCancel={onCancel} error={error}>
			<div className="picker" aria-busy={busy}>
				<Choice label={i18n.t('filters.noFolder')} current={current === null} disabled={busy} onClick={() => onPick(null)} />
				<FolderTree folders={folders} row={(folder) => <Choice label={folder.name} current={folder.id === current} disabled={busy} onClick={() => onPick(folder.id)} />} />
				<button type="button" className="picker__choice picker__choice--new" disabled={busy} onClick={() => setCreating(true)}>
					<Icon name="plus" />
					<span className="picker__label">{i18n.t('folders.newFolder')}</span>
				</button>
			</div>
		</ConfirmDialog>
	)
}
