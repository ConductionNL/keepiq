import { useEffect, useRef, useState } from 'react'
import { i18n } from '#i18n'
import type { FolderChildren, FolderDeletePlan, FolderMeta, SubfolderAction, VaultSnapshotReply, VaultWriteError, WriteResult } from '@/src/messages'
import { Button } from '../../components/Button'
import { ConfirmDialog } from '../../components/ConfirmDialog'
import { EmptyState } from '../../components/EmptyState'
import { Banner } from '../../components/ErrorBanner'
import { FolderNameDialog } from '../../components/FolderNameDialog'
import { FolderTree } from '../../components/FolderTree'
import { Icon } from '../../components/Icon'
import { Loading } from '../../components/Loading'
import { Menu } from '../../components/Menu'
import { Select } from '../../components/Select'
import { writeErrorText } from '../../errors'
import { request } from '../../hooks/useMessage'
import { useShell } from '../../shell-context'

type Unlocked = Extract<VaultSnapshotReply, { state: 'unlocked' }>

type Dialog =
	| { kind: 'create'; parentId: string | null }
	| { kind: 'rename'; folder: FolderMeta }
	| { kind: 'delete'; folder: FolderMeta }

/** Settings → Vault → Folders, as in Bitwarden, over a real tree since Keepiq refuses `/` in names. */
export function FolderManager({ snapshot }: { snapshot: Unlocked }) {
	const { accountId, offline, toast } = useShell()
	const [dialog, setDialog] = useState<Dialog | null>(null)
	const [noticeShown, setNoticeShown] = useState(!snapshot.folderNoticeDismissed)
	const { folders } = snapshot

	function dismiss() {
		setNoticeShown(false)
		void request({ kind: 'folder.dismissNotice', accountId })
	}

	function done(message: string) {
		setDialog(null)
		toast(message)
	}

	return (
		<div className="stack">
			{noticeShown && (
				<Banner tone="warning" role="status" action={<Button variant="link" onClick={dismiss}>{i18n.t('folders.dismiss')}</Button>}>
					{i18n.t('folders.notice')}
				</Banner>
			)}
			{offline && <Banner tone="warning" role="status">{i18n.t('form.offline')}</Banner>}
			<div>
				<Button variant="secondary" disabled={offline} onClick={() => setDialog({ kind: 'create', parentId: null })}><Icon name="plus" />{i18n.t('folders.add')}</Button>
			</div>
			{folders.length === 0
				? <EmptyState icon="folder" title={i18n.t('folders.empty')} />
				: (
					<div className="card card--menus">
						<FolderTree
							folders={folders}
							row={(flat) => {
								const folder = folders.find((f) => f.id === flat.id)!
								return (
									<div className="folder-row">
										<Icon name="folder" />
										<span className="folder-row__name">{folder.name}</span>
										<Menu
											label={i18n.t('folders.more', { name: folder.name })}
											icon="more"
											disabled={offline}
											items={[
												{ label: i18n.t('folders.add'), onSelect: () => setDialog({ kind: 'create', parentId: folder.id }) },
												{ label: i18n.t('folders.rename'), onSelect: () => setDialog({ kind: 'rename', folder }) },
												{ label: i18n.t('folders.delete'), onSelect: () => setDialog({ kind: 'delete', folder }) },
											]}
										/>
									</div>
								)
							}}
						/>
					</div>
				)}
			{(dialog?.kind === 'create' || dialog?.kind === 'rename') && (
				<FolderNameDialog
					target={dialog.kind === 'create' ? { create: { parentId: dialog.parentId } } : { rename: { id: dialog.folder.id, name: dialog.folder.name } }}
					onDone={() => done(i18n.t('folders.saved'))}
					onCancel={() => setDialog(null)}
				/>
			)}
			{dialog?.kind === 'delete' && <DeleteFolderDialog folder={dialog.folder} snapshot={snapshot} onDeleted={() => done(i18n.t('folders.deleted'))} onCancel={() => setDialog(null)} />}
		</div>
	)
}

type Stage =
	| { stage: 'loading' }
	| { stage: 'plain' }
	| { stage: 'cascade'; count: number }
	| { stage: 'resolution'; children: FolderChildren; directSecrets: 'delete' | 'move'; subfolders: Record<string, SubfolderAction> }

/** Which of Keepiq's three delete modes applies, from the server's children. */
function stageFor(children: FolderChildren): Stage {
	if (children.subfolders.length > 0) {
		// Keep and Move to parent lose nothing, so they are the defaults.
		return { stage: 'resolution', children, directSecrets: 'move', subfolders: Object.fromEntries(children.subfolders.map((sub) => [sub.id, 'keep'])) }
	}
	return children.directSecretCount > 0 ? { stage: 'cascade', count: children.directSecretCount } : { stage: 'plain' }
}

/**
 * The counts come from the server, never the cache: the cache holds only live items, but "Delete
 * items too" also destroys archived and trashed ones and anything added since the last sync.
 */
function DeleteFolderDialog({ folder, snapshot, onDeleted, onCancel }: { folder: FolderMeta; snapshot: Unlocked; onDeleted: () => void; onCancel: () => void }) {
	const { accountId, refreshState, syncNow } = useShell()
	const [state, setState] = useState<Stage>({ stage: 'loading' })
	const [busy, setBusy] = useState(false)
	/** `retry`: re-read the children, or send the same plan again; `null` when neither helps. */
	const [failure, setFailure] = useState<{ text: string; retry: 'children' | 'delete' | null } | null>(null)
	const lastPlan = useRef<FolderDeletePlan>({ mode: 'plain' })
	const parent = snapshot.folders.find((f) => f.id === folder.parentId)?.name ?? i18n.t('filters.noFolder')

	function fail(error: VaultWriteError | undefined, retry: 'children' | 'delete') {
		if (error?.code === 'locked' || error?.code === 'session_revoked') refreshState()
		const transient = !error || error.code === 'offline' || error.code === 'server_error' || error.code === 'write_locked'
		setFailure({ text: writeErrorText(error, 'folderDelete'), retry: retry === 'children' || transient ? retry : null })
	}

	function showChildren(reply: WriteResult<FolderChildren> | undefined) {
		if (reply?.ok) return setState(stageFor(reply.value))
		fail(reply?.error, 'children')
	}

	async function loadChildren() {
		setFailure(null)
		showChildren(await request({ kind: 'folder.children', accountId, id: folder.id }))
	}

	useEffect(() => {
		void request({ kind: 'folder.children', accountId, id: folder.id }).then(showChildren)
		// Once, for the folder the dialog opened on.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [])

	async function remove(plan: FolderDeletePlan) {
		lastPlan.current = plan
		setBusy(true)
		setFailure(null)
		const reply = await request({ kind: 'folder.delete', accountId, id: folder.id, plan })
		setBusy(false)
		if (reply?.ok) return onDeleted()
		if (reply?.error.code === 'conflict' && plan.mode === 'resolution') {
			// The cache missed a folder one level up with a kept subfolder's name. Nothing changed; syncing lets the check below name it.
			syncNow()
			return setFailure({ text: i18n.t('writeErrors.keepClashAfter'), retry: 'children' })
		}
		// An item or a subfolder landed since the children were read: read them again and offer the matching choice.
		if (reply?.error.code === 'conflict') return loadChildren()
		// A 400 on a plan means a subfolder appeared since the children were read.
		fail(reply?.error, reply?.error.code === 'invalid' ? 'children' : 'delete')
	}

	const onRetry = failure?.retry ? () => void (failure.retry === 'children' ? loadChildren() : remove(lastPlan.current)) : undefined
	const common = { title: i18n.t('folders.deleteTitle'), onCancel, error: failure?.text, onRetry }

	if (state.stage === 'loading') return <ConfirmDialog {...common} actions={[]}>{!failure && <Loading />}</ConfirmDialog>
	if (state.stage === 'plain') {
		return (
			<ConfirmDialog {...common} actions={[{ label: i18n.t('folders.delete'), variant: 'danger', busy, onClick: () => void remove({ mode: 'plain' }) }]}>
				<p>{i18n.t('folders.deleteConfirm', { name: folder.name })}</p>
			</ConfirmDialog>
		)
	}
	if (state.stage === 'cascade') {
		return (
			<ConfirmDialog
				{...common}
				actions={[
					{ label: i18n.t('folders.deleteItems'), variant: 'danger', disabled: busy, onClick: () => void remove({ mode: 'cascade', cascade: 'delete' }) },
					{ label: i18n.t('folders.moveItems'), disabled: busy, onClick: () => void remove({ mode: 'cascade', cascade: 'move' }) },
				]}
			>
				<p>{i18n.t('folders.cascade', state.count, { name: folder.name, parent })}</p>
				<p>{i18n.t('folders.countsAll')}</p>
			</ConfirmDialog>
		)
	}
	const { children, directSecrets, subfolders } = state
	// Caught here, so the choice can change before anything is sent.
	const taken = new Set(snapshot.folders.filter((f) => f.parentId === folder.parentId && f.id !== folder.id).map((f) => f.name.toLowerCase()))
	const clashes = new Set(children.subfolders.filter((sub) => (subfolders[sub.id] ?? 'keep') === 'keep' && taken.has(sub.name.toLowerCase())).map((sub) => sub.id))
	return (
		<ConfirmDialog
			{...common}
			actions={[{ label: i18n.t('folders.delete'), variant: 'danger', busy, disabled: clashes.size > 0, onClick: () => void remove({ mode: 'resolution', directSecrets, subfolders }) }]}
		>
			<div className="resolution">
				<p>{i18n.t('folders.countsAll')}</p>
				{children.directSecretCount > 0 && (
					<Select<'delete' | 'move'>
						label={i18n.t('folders.directRow', { name: folder.name, items: i18n.t('folders.items', children.directSecretCount) })}
						value={directSecrets}
						options={[{ value: 'move', label: i18n.t('folders.directMove') }, { value: 'delete', label: i18n.t('folders.actionDelete') }]}
						onChange={(value) => setState({ ...state, directSecrets: value })}
					/>
				)}
				{children.subfolders.map((sub) => (
					<div key={sub.id}>
						<Select<SubfolderAction>
							label={i18n.t('folders.subfolderRow', { name: sub.name, items: i18n.t('folders.items', sub.secretCount), subfolders: i18n.t('folders.subfolders', sub.subfolderCount) })}
							value={subfolders[sub.id] ?? 'keep'}
							options={[
								{ value: 'keep', label: i18n.t('folders.actionKeep') },
								{ value: 'move', label: i18n.t('folders.actionMove') },
								{ value: 'delete', label: i18n.t('folders.actionDelete') },
							]}
							onChange={(value) => setState({ ...state, subfolders: { ...subfolders, [sub.id]: value } })}
						/>
						{clashes.has(sub.id) && <p className="field__error">{i18n.t('writeErrors.keepClash', { name: sub.name })}</p>}
					</div>
				))}
			</div>
		</ConfirmDialog>
	)
}
