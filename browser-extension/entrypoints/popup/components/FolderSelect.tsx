import type { FolderMeta } from '@/src/messages'
import { flattenFolders, type FolderFilter } from '@/src/vault/list'
import { Icon } from './Icon'

export function FolderSelect({ folders, value, onChange, disabled }: { folders: FolderMeta[]; value: FolderFilter; onChange: (value: FolderFilter) => void; disabled?: boolean }) {
	return (
		<div className="select">
			<Icon name="folder" />
			<select className="select__input" aria-label="Folder" value={value} disabled={disabled} onChange={(event) => onChange(event.target.value)}>
				<option value="all">All items</option>
				{flattenFolders(folders).map((folder) => (
					<option key={folder.id} value={folder.id}>{'   '.repeat(folder.depth)}{folder.name}</option>
				))}
				<option value="none">No folder</option>
			</select>
			<Icon name="chevronDown" />
		</div>
	)
}
