// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// One item (task 3.1): secret values hidden until revealed, copy through
/// the sensitive clipboard, the TOTP code with its seconds left. A use-only
/// copy shows no value and offers no reveal or copy of it.
struct ItemDetailView: View {
    @ObservedObject var model: VaultModel
    let itemId: String

    init(model: VaultModel, itemId: String) {
        self.model = model
        self.itemId = itemId
    }

    @State private var result: OpenResult?
    @State private var problem: WriteProblem?
    @State private var confirmTrash = false
    @State private var moving = false
    @State private var editing = false
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        Group {
            if let opened = result as? OpenResult.Opened {
                detail(opened.item)
            } else if result is OpenResult.Missing {
                Text(L("detail_missing"))
            } else if let failed = result as? OpenResult.Failed {
                Text(writeProblemText(failed.problem))
            } else {
                ProgressView(L("vault_loading"))
            }
        }
        .task(id: itemId) { await load() }
    }

    private func load() async {
        result = try? await model.repository.open(id: itemId)
    }

    @ViewBuilder
    private func detail(_ item: DecryptedItem) -> some View {
        let row = item.row
        let offline = model.repository.state.offline
        List {
            Section {
                Text("\(item.type?.label ?? item.typeName) · \(VaultIndex.shared.folderPath(folders: model.repository.state.folders, folderId: row.folderId) ?? L("vault_no_folder"))")
                    .font(.footnote)
                if item.fromCache { Text(L("detail_from_cache")) }
                if row.useOnly { Text(L("detail_use_only")) }
                if row.readOnly && !row.useOnly { Text(L("detail_read_only")) }
                if let problem { Text(writeProblemText(problem)).foregroundStyle(.red) }
            }
            if row.blocked {
                Text(row.blockedReason ?? L("detail_blocked"))
            } else {
                if item.kind == .totp { Section { TotpRow(item: item, onCopy: model.copy) } }
                Section { fields(item) }
                if !row.useOnly {
                    Section {
                        Button { editing = true } label: { Text(L("action_edit")).foregroundStyle(KeepiqPalette.accent) }.disabled(offline)
                        Button { moving = true } label: { Text(L("action_move")).foregroundStyle(KeepiqPalette.accent) }.disabled(offline)
                    }
                }
                Section {
                    Button(role: .destructive) { confirmTrash = true } label: { Text(L("action_trash")).foregroundStyle(KeepiqPalette.destructive) }.disabled(offline)
                    if offline { Text(L("write_offline")).font(.footnote) }
                }
            }
        }
        .navigationTitle(row.name)
        .confirmationDialog(L("detail_trash_confirm", row.name), isPresented: $confirmTrash, titleVisibility: .visible) {
            Button(L("action_trash"), role: .destructive) {
                write({ try await model.repository.trash(id: row.id) }) { dismiss() }
            }
        }
        .sheet(isPresented: $moving) {
            MoveSheet(model: model, current: row.folderId) { folderId in
                moving = false
                write({ try await model.repository.move(id: row.id, folderId: folderId) }) {
                    Task { await load() }
                }
            }
        }
        .navigationDestination(isPresented: $editing) {
            ItemEditView(model: model, itemId: row.id, folderId: row.folderId) { _ in
                editing = false
                Task { await load() }
            }
        }
    }

    @ViewBuilder
    private func fields(_ item: DecryptedItem) -> some View {
        let row = item.row
        let kind = item.kind
        if kind == .login || kind == .generic {
            if !item.login.isEmpty { FieldRow(label: L("detail_username"), value: item.login, onCopy: model.copy) }
            if !row.useOnly {
                FieldRow(label: kind == .login ? L("detail_password") : L("detail_value"), value: item.secret, masked: true, onCopy: model.copy)
            }
        } else if kind == .note {
            if !row.useOnly { FieldRow(label: L("detail_notes"), value: item.secret, onCopy: model.copy) }
        } else if kind == .card || kind == .identity {
            if !row.useOnly {
                if let data = item.composite {
                    ForEach(Composite.shared.fieldsOf(kind: kind), id: \.self) { field in
                        if let value = data[field], !value.isEmpty {
                            FieldRow(label: compositeLabel(field), value: value, masked: Composite.shared.MASKED.contains(field), onCopy: model.copy)
                        }
                    }
                } else {
                    Text(L("detail_fields_error")).foregroundStyle(.red)
                }
            }
        } else if let passkey = item.passkey {
            FieldRow(label: L("detail_passkey_site"), value: passkey.rpName.map { "\($0) (\(passkey.rpId))" } ?? passkey.rpId, copy: false, onCopy: model.copy)
            if let user = passkey.userName { FieldRow(label: L("detail_passkey_account"), value: user, copy: false, onCopy: model.copy) }
            Text(L("detail_passkey_note")).font(.footnote)
        }
        if let url = row.url, !url.isEmpty {
            FieldRow(label: L("detail_address"), value: url, onCopy: model.copy)
        }
        if !row.useOnly {
            ForEach(Array(item.typedValues.enumerated()), id: \.offset) { _, pair in
                if let field = pair.first as? TypeField, let value = pair.second as? String, !value.isEmpty {
                    FieldRow(label: field.label, value: value, masked: field.hidden, onCopy: model.copy)
                }
            }
            if kind != .note && !item.notes.isEmpty {
                FieldRow(label: L("detail_notes"), value: item.notes, onCopy: model.copy)
            }
            if item.additionalFieldsError { Text(L("detail_fields_error")).foregroundStyle(.red) }
            ForEach(Array(item.extraFields.enumerated()), id: \.offset) { _, pair in
                FieldRow(label: (pair.first as? String) ?? "", value: (pair.second as? String) ?? "", onCopy: model.copy)
            }
        }
    }

    private func write(_ block: @escaping () async throws -> WriteResult, after: @escaping () -> Void) {
        Task { @MainActor in
            let outcome = try? await block()
            if outcome is WriteResult.Saved {
                after()
            } else if let refused = outcome as? WriteResult.Refused {
                problem = refused.problem
            }
        }
    }
}

/// The current code and its seconds left, refreshed every second.
private struct TotpRow: View {
    let item: DecryptedItem
    let onCopy: (String) -> Void
    /// The countdown ring grows with Dynamic Type.
    @ScaledMetric(relativeTo: .body) private var gaugeSize: CGFloat = 44
    private static let stillCode = ProcessInfo.processInfo.environment["KEEPIQ_UITEST_STILL_CODE"] == "1"

    var body: some View {
        if let params = item.totp {
            // The accessibility audit renders the screen at several text sizes,
            // and a countdown that redraws every second during that pass makes it
            // report the rows below as clipped. Its UI test holds the code still.
            TimelineView(.periodic(from: .now, by: Self.stillCode ? 3600 : 1)) { context in
                let millis = Int64(context.date.timeIntervalSince1970 * 1000)
                let code = Totp.shared.generate(params: params, epochMillis: millis)
                let left = Totp.shared.secondsRemaining(period: params.period, epochMillis: millis)
                VStack(alignment: .leading) {
                    Text(L("detail_code")).font(.caption).foregroundStyle(KeepiqPalette.secondaryText)
                    HStack {
                        Text(code).font(.title.monospaced()).accessibilityIdentifier("totpCode")
                        // A circular ProgressView spins on iOS whatever its value, so
                        // the seconds left are a gauge with the number in it.
                        Gauge(value: Double(left), in: 0...Double(params.period)) {
                            EmptyView()
                        }
                        .gaugeStyle(.accessoryCircularCapacity)
                        .tint(KeepiqPalette.accent)
                        .scaleEffect(0.7)
                        .frame(width: gaugeSize, height: gaugeSize)
                        // The accessory style draws its value label for widgets, with
                        // vibrancy; a plain Text keeps the full contrast of the primary colour.
                        .overlay { Text("\(Int(left))").font(.caption2).monospacedDigit().foregroundStyle(.primary) }
                        .accessibilityElement(children: .ignore)
                        .accessibilityLabel(L("detail_code_seconds", Int(left)))
                        Spacer()
                        Button(L("action_copy")) { onCopy(code) }
                            .frame(minWidth: 44, minHeight: 44)
                            .accessibilityLabel(L("cd_copy_field", L("detail_code")))
                    }
                }
            }
        } else {
            Text(L("detail_code_invalid")).foregroundStyle(.red)
        }
    }
}

/// Pick a folder to move an item to.
private struct MoveSheet: View {
    @ObservedObject var model: VaultModel
    let current: String?
    let onPick: (String?) -> Void

    var body: some View {
        NavigationStack {
            List {
                Button(L("vault_no_folder")) { onPick(nil) }
                    .accessibilityAddTraits(current == nil ? .isSelected : [])
                ForEach(VaultIndex.shared.folderTree(folders: model.repository.state.folders), id: \.id) { node in
                    Button(String(repeating: "  ", count: Int(node.depth)) + node.name) { onPick(node.id) }
                        .accessibilityLabel(node.name)
                        .accessibilityAddTraits(current == node.id ? .isSelected : [])
                }
            }
            .navigationTitle(L("move_title"))
        }
    }
}

/// Create or edit an item (task 3.2). The type's fields come from
/// `/api/v1/secret-types`; values are encrypted on the device to the suite's
/// key before they are sent. A refusal is shown and the form keeps what the
/// user typed.
struct ItemEditView: View {
    @ObservedObject var model: VaultModel
    let itemId: String?
    let folderId: String?
    let onSaved: (String?) -> Void

    init(model: VaultModel, itemId: String?, folderId: String?, onSaved: @escaping (String?) -> Void) {
        self.model = model
        self.itemId = itemId
        self.folderId = folderId
        self.onSaved = onSaved
    }

    @State private var item: DecryptedItem?
    @State private var type: SecretType?
    @State private var base: ItemDraft?
    @State private var name = ""
    @State private var url = ""
    @State private var folder: String?
    @State private var login = ""
    @State private var secret = ""
    @State private var notes = ""
    @State private var composite: [String: String] = [:]
    @State private var typed: [String: String] = [:]
    @State private var fieldNames: [String] = []
    @State private var fieldValues: [String] = []
    @State private var problem: WriteProblem?
    @State private var showErrors = false
    @State private var busy = false
    @State private var generating = false
    @State private var generateTarget: String?
    /// Saved: the secret fields show empty while the form leaves (task 3.2.1).
    @State private var leaving = false

    private var types: [SecretType] { model.repository.state.types.filter { $0.name != "passkey" } }

    var body: some View {
        Group {
            if let base {
                form(base)
            } else if let problem {
                Text(writeProblemText(problem))
            } else {
                ProgressView(L("vault_loading"))
            }
        }
        .navigationTitle(itemId == nil ? L("edit_new_title") : L("edit_title"))
        .task { await load() }
        .sheet(isPresented: $generating) {
            NavigationStack {
                GeneratorView(model: model) { value in
                    if let target = generateTarget { typed[target] = value } else { secret = value }
                    generating = false
                }
            }
        }
    }

    private func load() async {
        // Load once: coming back to the form keeps what the user typed.
        guard base == nil else { return }
        if let itemId {
            let opened = try? await model.repository.open(id: itemId)
            if let opened = opened as? OpenResult.Opened {
                item = opened.item
                type = opened.item.type
                apply(ItemCodec.shared.draft(item: opened.item, type: opened.item.type))
            } else if let failed = opened as? OpenResult.Failed {
                problem = failed.problem
            }
        } else {
            let initial = types.first { $0.name == "login" } ?? types.first
            pickType(initial)
            folder = folderId
        }
    }

    private func pickType(_ picked: SecretType?) {
        type = picked
        let keptName = name
        let keptUrl = url
        let keptFolder = folder
        let keptNotes = notes
        apply(ItemCodec.shared.draft(item: nil, type: picked))
        name = keptName
        url = keptUrl
        folder = keptFolder
        notes = keptNotes
    }

    private func apply(_ draft: ItemDraft) {
        base = draft
        name = draft.name
        url = draft.url
        folder = draft.folderId
        login = draft.login
        secret = draft.secret
        notes = draft.notes
        composite = draft.composite
        typed = draft.typed
        fieldNames = draft.fieldNames
        fieldValues = draft.fieldValues
    }

    private func current(_ base: ItemDraft) -> ItemDraft {
        base.edited(
            name: name, url: url, folderId: folder, login: login, secret: secret, notes: notes,
            composite: composite, typed: typed, fieldNames: fieldNames, fieldValues: fieldValues
        )
    }

    @ViewBuilder
    private func form(_ base: ItemDraft) -> some View {
        let draft = current(base)
        let errors = ItemCodec.shared.validate(draft: draft, type: type)
        let error: (String) -> String? = { key in showErrors ? errors[key].map(draftProblemText) : nil }
        let kind = draft.kind
        Form {
            Section {
                if itemId == nil && !types.isEmpty {
                    Picker(L("edit_type"), selection: Binding(get: { type?.id ?? "" }, set: { id in pickType(types.first { $0.id == id }) })) {
                        ForEach(types, id: \.id) { t in Text(t.label ?? t.name).tag(t.id) }
                    }
                }
                if kind == .passkey { Text(L("edit_passkey_note")).font(.footnote) }
                LabeledInput(label: L("edit_name"), text: $name, problem: error("name"))
                LabeledInput(label: L("edit_url"), text: $url, problem: error("url"), keyboard: .URL)
                Picker(L("detail_folder"), selection: $folder) {
                    Text(L("vault_no_folder")).tag(String?.none)
                    ForEach(VaultIndex.shared.folderTree(folders: model.repository.state.folders), id: \.id) { node in
                        Text(String(repeating: "  ", count: Int(node.depth)) + node.name).tag(String?.some(node.id))
                    }
                }
            }
            Section {
                if kind == .login || kind == .generic {
                    LabeledInput(label: L("detail_username"), text: $login, problem: error("login"))
                    SecretInput(label: kind == .login ? L("detail_password") : L("detail_value"), text: $secret, problem: error("secret")) {
                        generateTarget = nil
                        generating = true
                    }
                } else if kind == .totp {
                    LabeledInput(label: L("edit_totp_secret"), text: $secret, problem: error("secret"))
                } else if kind == .card || kind == .identity {
                    ForEach(Composite.shared.fieldsOf(kind: kind), id: \.self) { field in
                        let binding = Binding(get: { composite[field] ?? "" }, set: { composite[field] = $0 })
                        if Composite.shared.MASKED.contains(field) {
                            SecretInput(label: compositeLabel(field), text: binding, problem: nil, onGenerate: nil)
                        } else {
                            LabeledInput(label: compositeLabel(field), text: binding, problem: nil)
                        }
                    }
                }
                ForEach(type?.fields ?? [], id: \.key) { field in
                    let binding = Binding(get: { typed[field.key] ?? "" }, set: { typed[field.key] = $0 })
                    let label = field.required ? "\(field.label) (\(L("edit_required")))" : field.label
                    if field.hidden {
                        SecretInput(label: label, text: binding, problem: error("typed-\(field.key)")) {
                            generateTarget = field.key
                            generating = true
                        }
                    } else {
                        LabeledInput(label: label, text: binding, problem: error("typed-\(field.key)"), keyboard: keyboardFor(field))
                    }
                }
                VStack(alignment: .leading) {
                    Text(L("detail_notes")).font(.caption)
                    TextEditor(text: $notes).frame(minHeight: 80).accessibilityLabel(L("detail_notes"))
                }
            }
            if kind != .passkey {
                Section(titled: L("detail_extra_fields")) {
                    ForEach(fieldNames.indices, id: \.self) { i in
                        VStack {
                            LabeledInput(label: L("edit_field_name"), text: $fieldNames[i], problem: error("field-\(i)"))
                            LabeledInput(label: L("edit_field_value"), text: $fieldValues[i], problem: nil)
                            Button(L("action_remove"), role: .destructive) {
                                fieldNames.remove(at: i)
                                fieldValues.remove(at: i)
                            }
                            .accessibilityLabel(L("cd_remove_field", fieldNames[i]))
                        }
                    }
                    Button(L("edit_add_field")) {
                        fieldNames.append("")
                        fieldValues.append("")
                    }
                }
            }
            Section {
                if let problem { Text(writeProblemText(problem)).foregroundStyle(.red) }
                if let fieldsProblem = error("fields") { Text(fieldsProblem).foregroundStyle(.red) }
                Button(L("action_save")) { save(draft, valid: errors.isEmpty) }
                    .disabled(busy || model.repository.state.offline)
                if model.repository.state.offline { Text(L("write_offline")).font(.footnote) }
            }
        }
        .secretFieldsCleared(leaving)
    }

    private func keyboardFor(_ field: TypeField) -> UIKeyboardType {
        switch field.kind {
        case "url": return .URL
        case "email": return .emailAddress
        default: return .default
        }
    }

    private func save(_ draft: ItemDraft, valid: Bool) {
        dismissKeyboard()
        showErrors = true
        guard valid else { return }
        busy = true
        problem = nil
        Task { @MainActor in
            let outcome: WriteResult?
            if let item {
                outcome = try? await model.repository.update(item: item, draft: draft)
            } else {
                outcome = try? await model.repository.create(draft: draft, type: type)
            }
            busy = false
            if let saved = outcome as? WriteResult.Saved {
                leaving = true
                await leaveWithEmptySecrets()
                onSaved(saved.id)
            } else if let refused = outcome as? WriteResult.Refused {
                problem = refused.problem
            } else {
                problem = WriteProblem(kind: .failed, serverMessage: nil, status: 0)
            }
        }
    }
}

extension View {
    /// Empties the secret fields before a saved form leaves the screen, so
    /// iOS does not offer to save Keepiq's own secrets as a password (task
    /// 3.2.1). iOS reads the user name and password fields when they leave
    /// the view hierarchy ("Make sure to only clear the username and password
    /// fields after they've been removed from the view hierarchy. This way,
    /// we can read out the data and save it into credential.", WWDC 2018
    /// session 204); fields that are empty by then hold nothing to save. The
    /// values were sent already, and the fields stay secure fields, so
    /// VoiceOver still announces them as such.
    func secretFieldsCleared(_ cleared: Bool) -> some View {
        environment(\.secretFieldsCleared, cleared)
    }
}

/// Lets SwiftUI push the empty values into the text fields before the form goes.
@MainActor
func leaveWithEmptySecrets() async {
    try? await Task.sleep(nanoseconds: 200_000_000)
}

private struct SecretFieldsClearedKey: EnvironmentKey {
    static let defaultValue = false
}

extension EnvironmentValues {
    var secretFieldsCleared: Bool {
        get { self[SecretFieldsClearedKey.self] }
        set { self[SecretFieldsClearedKey.self] = newValue }
    }
}

struct LabeledInput: View {
    let label: String
    @Binding var text: String
    let problem: String?
    var keyboard: UIKeyboardType = .default

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            LabeledField(label) {
                TextField(label, text: $text)
                    .keyboardType(keyboard)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
            }
            if let problem { Text(problem).font(.footnote).foregroundStyle(.red) }
        }
    }
}

/// A hidden input with Show and, where it fits, Generate.
struct SecretInput: View {
    let label: String
    @Binding var text: String
    let problem: String?
    let onGenerate: (() -> Void)?

    init(label: String, text: Binding<String>, problem: String?, onGenerate: (() -> Void)?) {
        self.label = label
        _text = text
        self.problem = problem
        self.onGenerate = onGenerate
    }

    @State private var shown = false
    @Environment(\.secretFieldsCleared) private var cleared

    /// The value, or nothing once the saved form is leaving.
    private var value: Binding<String> { cleared ? .constant("") : $text }

    var body: some View {
        VStack(alignment: .leading, spacing: 2) {
            Text(label).font(.footnote).accessibilityHidden(true)
            HStack {
                // Keepiq's own secrets are typed as one-time codes, not passwords, so iOS
                // never offers to save them in another password manager (or in Keepiq).
                if shown {
                    TextField(label, text: value).textInputAutocapitalization(.never).autocorrectionDisabled()
                        .textContentType(.oneTimeCode)
                        .accessibilityLabel(label)
                } else {
                    SecureField(label, text: value).textContentType(.oneTimeCode).accessibilityLabel(label)
                }
                Button(shown ? L("action_hide") : L("action_show")) { shown.toggle() }
                    .frame(minWidth: 44, minHeight: 44)
                    .accessibilityLabel(shown ? L("cd_hide_field", label) : L("cd_show_field", label))
                if let onGenerate {
                    Button(L("action_generate"), action: onGenerate).frame(minWidth: 44, minHeight: 44)
                }
            }
            .buttonStyle(.borderless)
            if let problem { Text(problem).font(.footnote).foregroundStyle(.red) }
        }
    }
}
