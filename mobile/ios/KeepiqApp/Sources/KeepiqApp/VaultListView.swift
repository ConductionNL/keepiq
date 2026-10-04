// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// The vault list (task 3.1): one folder's subfolders and items, or, while
/// searching, every match. Names and addresses only: nothing is decrypted
/// here. Search runs on the device.
struct VaultListView: View {
    @ObservedObject var model: VaultModel
    let folderId: String?

    init(model: VaultModel, folderId: String?) {
        self.model = model
        self.folderId = folderId
    }

    @State private var state: VaultState?
    @State private var query = ""
    @State private var folderSheet: FolderSheet?
    @State private var addItem = false
    @Environment(\.scenePhase) private var scenePhase
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        content
            .navigationTitle(folderName ?? L("tab_vault"))
            .searchable(text: $query, prompt: L("search_hint"))
            .refreshable { await sync(.manual) }
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Menu {
                        Button(L("folder_new")) { folderSheet = .create(parentId: folderId) }
                        if let folderId, let name = folderName {
                            Button(L("folder_rename")) { folderSheet = .rename(id: folderId, name: name) }
                            Button(L("folder_delete"), role: .destructive) { folderSheet = .delete(id: folderId, name: name) }
                        }
                    } label: {
                        Image(systemName: "folder.badge.gearshape")
                    }
                    .accessibilityLabel(L("cd_folder_actions"))
                }
                if let state, !state.offline, state.locked == nil {
                    ToolbarItem(placement: .primaryAction) {
                        Button { addItem = true } label: { Image(systemName: "plus") }
                            .accessibilityLabel(L("cd_add_item"))
                    }
                }
            }
            .navigationDestination(isPresented: $addItem) {
                ItemEditView(model: model, itemId: nil, folderId: folderId) { _ in
                    addItem = false
                    state = model.repository.state
                }
            }
            .sheet(item: $folderSheet) { sheet in
                FolderSheetView(model: model, sheet: sheet) { changed, gone in
                    folderSheet = nil
                    if changed { state = model.repository.state }
                    if gone { dismiss() }
                }
            }
            .task {
                if model.repository.state.rows.isEmpty && model.repository.state.syncedAtMillis == nil {
                    await sync(.start)
                } else {
                    state = model.repository.state
                }
                // The extension's 15-minute timer while the app is open.
                while !Task.isCancelled {
                    try? await Task.sleep(nanoseconds: UInt64(VaultSync.companion.SYNC_INTERVAL_MILLIS) * 1_000_000)
                    if Task.isCancelled { break }
                    await sync(.timer)
                }
            }
            .onChange(of: scenePhase) { _, phase in
                if phase == .active { Task { await sync(.foreground) } }
            }
    }

    private var folderName: String? {
        guard let folderId else { return nil }
        return model.repository.state.folders.first { $0.id == folderId }?.name
    }

    private func sync(_ trigger: SyncTrigger) async {
        state = try? await model.repository.refresh(trigger: trigger)
    }

    @ViewBuilder
    private var content: some View {
        if let state {
            List {
                SyncNote(state: state)
                if let locked = state.locked {
                    Text(lockText(locked))
                } else if state.needsConnection {
                    Text(L("vault_needs_connection"))
                } else {
                    entries(state)
                }
            }
            .listStyle(.plain)
        } else {
            ProgressView(L("vault_loading"))
        }
    }

    @ViewBuilder
    private func entries(_ state: VaultState) -> some View {
        let index = state.index
        let searching = !query.trimmingCharacters(in: .whitespaces).isEmpty
        let folderIds = Set(state.folders.map { $0.id })
        let items: [IndexEntry] = {
            if searching { return VaultIndex.shared.filter(entries: index, query: query, folderId: nil, typeName: nil) }
            if let folderId { return VaultIndex.shared.filter(entries: index, query: "", folderId: folderId, typeName: nil) }
            // The top level also holds items whose folder is gone, so none is ever out of reach.
            return index.filter { entry in entry.folderId == nil || !folderIds.contains(entry.folderId!) }
        }()
        let folders: [VaultFolder] = searching ? [] : VaultIndex.shared.subfolders(folders: state.folders, parentId: folderId)
        if index.isEmpty {
            Text(L("vault_empty"))
        } else if !searching && index.allSatisfy({ $0.blocked }) {
            Text(L("vault_all_blocked"))
        } else if searching && items.isEmpty {
            Text(L("vault_no_match"))
        } else if !searching && items.isEmpty && folders.isEmpty {
            Text(L("vault_folder_empty"))
        } else {
            ForEach(folders, id: \.id) { folder in
                NavigationLink {
                    VaultListView(model: model, folderId: folder.id)
                } label: {
                    Label(folder.name, systemImage: "folder")
                }
                .accessibilityLabel(L("cd_open_folder", folder.name))
            }
            ForEach(items, id: \.id) { entry in
                NavigationLink {
                    ItemDetailView(model: model, itemId: entry.id)
                } label: {
                    EntryRow(entry: entry, showFolder: searching)
                }
            }
        }
    }

    private func lockText(_ reason: LockReason) -> String {
        switch reason {
        case .suiteChanged: return L("vault_locked_suite")
        case .masterPasswordChanged: return L("vault_locked_password")
        default: return L("vault_locked_two_factor")
        }
    }
}

private struct EntryRow: View {
    let entry: IndexEntry
    let showFolder: Bool

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(entry.name)
            let host = entry.url
                .replacingOccurrences(of: "https://", with: "")
                .replacingOccurrences(of: "http://", with: "")
                .split(separator: "/").first.map(String.init) ?? ""
            let badges = [
                entry.useOnly ? L("badge_use_only") : nil,
                entry.readOnly && !entry.useOnly ? L("badge_read_only") : nil,
                entry.blocked ? L("badge_blocked") : nil,
            ].compactMap { $0 }
            let details = [host.isEmpty ? nil : host, showFolder && !entry.folderName.isEmpty ? entry.folderName : nil, badges.isEmpty ? nil : badges.joined(separator: ", ")]
                .compactMap { $0 }
                .joined(separator: " · ")
            if !details.isEmpty {
                Text(details).font(.footnote).foregroundStyle(.secondary)
            }
        }
        .frame(minHeight: 44)
    }
}

/// The last-synced note, or what offline means for this vault.
struct SyncNote: View {
    let state: VaultState

    var body: some View {
        if let text {
            Text(text)
                .font(.footnote)
                .padding(8)
                .frame(maxWidth: .infinity, alignment: .leading)
                .background(state.offline ? Color.yellow.opacity(0.2) : Color.secondary.opacity(0.1), in: RoundedRectangle(cornerRadius: 8))
                .listRowSeparator(.hidden)
        }
        if let problem = state.problem {
            Text(writeProblemText(problem)).foregroundStyle(.red)
        }
    }

    private var text: String? {
        let synced = state.syncedAtMillis?.int64Value
        if state.offline, let synced { return L("vault_offline", relativeTime(millis: synced)) }
        if state.offline { return L("vault_offline_never") }
        if state.onlineOnly { return L("vault_online_only") }
        if let synced { return L("vault_synced", relativeTime(millis: synced)) }
        return nil
    }
}

enum FolderSheet: Identifiable {
    case create(parentId: String?)
    case rename(id: String, name: String)
    case delete(id: String, name: String)

    var id: String {
        switch self {
        case .create(let parentId): return "create-\(parentId ?? "")"
        case .rename(let id, _): return "rename-\(id)"
        case .delete(let id, _): return "delete-\(id)"
        }
    }
}

/// Create, rename and delete a folder (task 3.2).
struct FolderSheetView: View {
    @ObservedObject var model: VaultModel
    let sheet: FolderSheet
    let onDone: (_ changed: Bool, _ gone: Bool) -> Void

    init(model: VaultModel, sheet: FolderSheet, onDone: @escaping (_ changed: Bool, _ gone: Bool) -> Void) {
        self.model = model
        self.sheet = sheet
        self.onDone = onDone
    }

    @State private var name = ""
    @State private var problem: WriteProblem?
    @State private var busy = false
    @State private var deleteKind: FolderDeleteKind?
    @State private var loaded = false

    var body: some View {
        NavigationStack {
            Form {
                switch sheet {
                case .create, .rename:
                    TextField(L("folder_name"), text: $name)
                    if let nameProblem, !name.isEmpty { Text(nameProblem).foregroundStyle(.red) }
                case .delete(let id, let folderName):
                    if !loaded {
                        ProgressView().task {
                            deleteKind = try? await model.repository.folderDeleteKind(id: id)
                            loaded = true
                        }
                    } else if let deleteKind {
                        if deleteKind == .empty {
                            Text(L("folder_delete_empty", folderName))
                            Button(L("action_delete"), role: .destructive) { run(gone: true) { try await model.repository.deleteFolder(id: id, kind: .empty, deleteItems: false) } }
                        } else if deleteKind == .items {
                            Text(L("folder_delete_items", folderName))
                            Button(L("folder_delete_move")) { run(gone: true) { try await model.repository.deleteFolder(id: id, kind: .items, deleteItems: false) } }
                            Button(L("folder_delete_with_items"), role: .destructive) { run(gone: true) { try await model.repository.deleteFolder(id: id, kind: .items, deleteItems: true) } }
                        } else {
                            Text(L("folder_delete_subfolders"))
                        }
                    } else {
                        Text(L("write_unreachable"))
                    }
                }
                if let problem { Text(writeProblemText(problem)).foregroundStyle(.red) }
            }
            .navigationTitle(title)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) { Button(L("action_cancel")) { onDone(false, false) } }
                if !isDelete {
                    ToolbarItem(placement: .confirmationAction) {
                        Button(L("action_save")) { save() }.disabled(busy || nameProblem != nil)
                    }
                }
            }
            .onAppear {
                if case .rename(_, let current) = sheet { name = current }
            }
        }
    }

    private var isDelete: Bool {
        if case .delete = sheet { return true }
        return false
    }

    private var title: String {
        switch sheet {
        case .create: return L("folder_new")
        case .rename: return L("folder_rename")
        case .delete: return L("folder_delete")
        }
    }

    /// folderNameProblem (browser-extension/src/lib/folder-rules.js).
    private var nameProblem: String? {
        let clean = name.trimmingCharacters(in: .whitespacesAndNewlines)
        if clean.isEmpty { return L("folder_name_missing") }
        if clean.contains("/") { return L("folder_name_slash") }
        if clean.count > 255 { return L("problem_too_long") }
        return nil
    }

    private func save() {
        switch sheet {
        case .create(let parentId): run(gone: false) { try await model.repository.createFolder(name: name, parentId: parentId) }
        case .rename(let id, _): run(gone: false) { try await model.repository.renameFolder(id: id, name: name) }
        case .delete: break
        }
    }

    private func run(gone: Bool, _ block: @escaping () async throws -> WriteResult) {
        busy = true
        Task { @MainActor in
            let result = try? await block()
            busy = false
            if result is WriteResultSaved {
                onDone(true, gone)
            } else if let refused = result as? WriteResultRefused {
                problem = refused.problem
            }
        }
    }
}
