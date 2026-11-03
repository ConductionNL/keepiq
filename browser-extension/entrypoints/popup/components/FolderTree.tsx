import type { CSSProperties, ReactNode } from 'react'
import type { FolderMeta } from '@/src/messages'
import { flattenFolders, type FlatFolder } from '@/src/vault/list'

/** Folders indented by `parentId`, sorted by name per level; the picker and the manager draw the rows. */
export function FolderTree({ folders, row }: { folders: FolderMeta[]; row: (folder: FlatFolder) => ReactNode }) {
	return (
		<ul className="folder-tree">
			{flattenFolders(folders).map((folder) => (
				<li key={folder.id} className="folder-tree__row" style={{ '--depth': folder.depth } as CSSProperties}>{row(folder)}</li>
			))}
		</ul>
	)
}
