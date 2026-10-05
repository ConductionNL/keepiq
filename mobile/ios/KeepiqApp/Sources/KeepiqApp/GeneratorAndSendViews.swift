// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// The generator (task 3.5), on its own tab and from the item editor. Values
/// are made on the device. The organisation's policy sets the shortest length
/// that can be picked and switches on the kinds of character it requires.
struct GeneratorView: View {
    @ObservedObject var model: VaultModel
    let onUse: ((String) -> Void)?

    init(model: VaultModel, onUse: ((String) -> Void)?) {
        self.model = model
        self.onUse = onUse
    }

    @State private var passphrase = false
    @State private var length = 14.0
    @State private var upper = true
    @State private var lower = true
    @State private var digits = true
    @State private var symbols = false
    @State private var minDigits = 1
    @State private var minSymbols = 1
    @State private var avoidAmbiguous = true
    @State private var words = 5.0
    @State private var separator = "-"
    @State private var capitalise = false
    @State private var number = false
    @State private var round = 0

    private var settings: GeneratorSettings {
        GeneratorSettings(
            mode: passphrase ? .passphrase : .password,
            password: PasswordOptions(
                length: Int32(length),
                includeUppercase: upper,
                includeLowercase: lower,
                includeDigits: digits,
                includeSpecialCharacters: symbols,
                minDigits: Int32(minDigits),
                minSpecial: Int32(minSymbols),
                excludedCharacters: "",
                avoidAmbiguous: avoidAmbiguous,
                regex: nil
            ),
            passphrase: PassphraseOptions(words: Int32(words), separator: separator, capitalise: capitalise, includeNumber: number)
        ).sanitized(policy: model.policy)
    }

    var body: some View {
        let policy = model.policy
        let safe = settings
        let outcome = safe.tryGenerate(policy: policy)
        let _ = round
        Form {
            Section {
                Picker(L("gen_password"), selection: $passphrase) {
                    Text(L("gen_password")).tag(false)
                    Text(L("gen_passphrase")).tag(true)
                }
                .pickerStyle(.segmented)
                .disabled(policy?.allowPassphrase == false)
                if policy?.allowPassphrase == false { Text(L("gen_passphrase_off")).font(.footnote) }
                HStack {
                    if let value = outcome.value {
                        Text(value).font(.body.monospaced()).textSelection(.disabled)
                    } else {
                        Text(generatorProblem(outcome.error)).foregroundStyle(.red)
                    }
                    Spacer()
                    Button { round += 1 } label: { Image(systemName: "arrow.clockwise") }
                        .frame(minWidth: 44, minHeight: 44)
                        .accessibilityLabel(L("cd_regenerate"))
                    if let value = outcome.value {
                        if let onUse {
                            Button(L("action_use")) { onUse(value) }.frame(minWidth: 44, minHeight: 44)
                        } else {
                            Button(L("action_copy")) { model.copy(value) }.frame(minWidth: 44, minHeight: 44)
                        }
                    }
                }
                .buttonStyle(.borderless)
                if let policy { Text(L("gen_policy", Int(policy.minLength))).font(.footnote) }
            }
            if !passphrase {
                Section {
                    let minimum = Double(safe.minimumLength(policy: policy))
                    Text(L("gen_length", Int(safe.password.length)))
                    Slider(value: $length, in: minimum...Double(Generator.shared.MAX_LENGTH), step: 1)
                        .accessibilityLabel(L("gen_length", Int(safe.password.length)))
                    PolicyToggle(label: L("gen_upper"), isOn: $upper, locked: policy?.requireUpper == true)
                    PolicyToggle(label: L("gen_lower"), isOn: $lower, locked: policy?.requireLower == true)
                    PolicyToggle(label: L("gen_digits"), isOn: $digits, locked: policy?.requireDigit == true)
                    PolicyToggle(label: L("gen_symbols"), isOn: $symbols, locked: policy?.requireSymbol == true)
                    if safe.password.includeDigits { Stepper(L("gen_min_digits", minDigits), value: $minDigits, in: 0...9) }
                    if safe.password.includeSpecialCharacters { Stepper(L("gen_min_symbols", minSymbols), value: $minSymbols, in: 0...9) }
                    Toggle(L("gen_avoid_ambiguous"), isOn: $avoidAmbiguous)
                }
            } else {
                Section {
                    Text(L("gen_words", Int(words)))
                    Slider(value: $words, in: Double(Generator.shared.MIN_WORDS)...Double(Generator.shared.MAX_WORDS), step: 1)
                        .accessibilityLabel(L("gen_words", Int(words)))
                    LabeledField(L("gen_separator")) {
                        TextField(L("gen_separator"), text: $separator)
                            .onChange(of: separator) { _, value in if value.count > 3 { separator = String(value.prefix(3)) } }
                    }
                    PolicyToggle(label: L("gen_capitalise"), isOn: $capitalise, locked: policy?.requireUpper == true)
                    PolicyToggle(label: L("gen_number"), isOn: $number, locked: policy?.requireDigit == true)
                }
            }
        }
        .onChange(of: policy) { _, newPolicy in
            if let newPolicy { length = max(length, Double(newPolicy.minLength)) }
        }
    }

    private func generatorProblem(_ code: GeneratorErrorCode?) -> String {
        guard let code else { return "" }
        if code == .passphraseOff { return L("gen_passphrase_off") }
        if code == .noKindChosen { return L("gen_error", L("gen_err_no_kind")) }
        if code == .charsetEmpty || code == .charsetTooSmall { return L("gen_error", L("gen_err_charset")) }
        if code == .lengthTooShort || code == .lengthTooLong || code == .passphraseWords { return L("gen_error", L("gen_err_length")) }
        return L("gen_error", L("gen_err_other"))
    }
}

/// A switch the policy may decide: then it is on, locked, and says so.
private struct PolicyToggle: View {
    let label: String
    @Binding var isOn: Bool
    let locked: Bool

    var body: some View {
        Toggle(isOn: Binding(get: { isOn || locked }, set: { isOn = $0 })) {
            VStack(alignment: .leading) {
                Text(label)
                if locked { Text(L("gen_policy_required")).font(.footnote) }
            }
        }
        .disabled(locked)
    }
}

/// The account's sends (task 3.6): metadata only, with delete, a new Send
/// and a field to open a Send link.
struct SendListView: View {
    @ObservedObject var model: VaultModel

    init(model: VaultModel) {
        self.model = model
    }

    @State private var sends: [SendSummary]?
    @State private var problem: WriteProblem?
    @State private var deleting: SendSummary?
    @State private var creating = false
    @State private var opening = false

    var body: some View {
        List {
            Button(L("open_send_paste")) { opening = true }
            if let problem { Text(writeProblemText(problem)).foregroundStyle(.red) }
            if let sends {
                if sends.isEmpty { Text(L("send_empty")) }
                ForEach(sends, id: \.id) { send in
                    SendRow(send: send)
                        .swipeActions {
                            Button(L("action_delete"), role: .destructive) { deleting = send }
                        }
                        .accessibilityAction(named: L("action_delete")) { deleting = send }
                }
            } else if problem == nil {
                ProgressView(L("vault_loading"))
            }
        }
        .navigationTitle(L("send_title"))
        .toolbar {
            ToolbarItem(placement: .primaryAction) {
                Button { creating = true } label: { Image(systemName: "plus") }
                    .accessibilityLabel(L("cd_new_send"))
            }
        }
        .task { await load() }
        .refreshable { await load() }
        .confirmationDialog(L("send_delete_confirm"), isPresented: Binding(get: { deleting != nil }, set: { if !$0 { deleting = nil } }), titleVisibility: .visible) {
            Button(L("action_delete"), role: .destructive) {
                guard let send = deleting else { return }
                deleting = nil
                Task { @MainActor in
                    let result = try? await model.session.sends.delete(id: send.id)
                    if let failed = result?.problemOrNull { problem = failed.write } else { await load() }
                }
            }
        }
        .navigationDestination(isPresented: $creating) {
            NewSendView(model: model) { creating = false; Task { await load() } }
        }
        .sheet(isPresented: $opening) {
            NavigationStack {
                OpenSendView(initialLink: "", onCopy: model.copy)
                    .toolbar { ToolbarItem(placement: .cancellationAction) { Button(L("action_close")) { opening = false } } }
            }
        }
    }

    private func load() async {
        let result = try? await model.session.sends.list()
        if let list = result?.valueOrNull as? [SendSummary] {
            sends = list
            problem = nil
        } else {
            problem = result?.problemOrNull?.write ?? WriteProblem(kind: .unreachable, serverMessage: nil, status: 0)
        }
    }
}

private struct SendRow: View {
    let send: SendSummary

    var body: some View {
        let kind = send.payloadType == "credential" ? L("send_credential") : L("send_text")
        let created = IsoTime.shared.parseMillis(text: send.createdAt).map {
            Date(timeIntervalSince1970: TimeInterval($0.int64Value) / 1000).formatted(date: .abbreviated, time: .shortened)
        }
        let nowMillis = Int64(Date().timeIntervalSince1970 * 1000)
        let minutes = SendForm.shared.minutesLeft(expiresAtMillis: IsoTime.shared.parseMillis(text: send.expiresAt), nowMillis: nowMillis)?.int64Value
        let expiry: String? = minutes.map(expiryText)
        VStack(alignment: .leading, spacing: 2) {
            Text(created.map { L("send_row", kind, $0) } ?? kind)
            Text(detailText(expiry: expiry))
                .font(.footnote)
                .foregroundStyle(.secondary)
        }
        .frame(minHeight: 44)
    }

    private func expiryText(_ m: Int64) -> String {
        if m <= 0 { return L("send_expired") }
        if m < 60 { return L("send_expires_minutes", Int(m)) }
        if m < 48 * 60 { return L("send_expires_hours", Int((m + 30) / 60)) }
        return L("send_expires_days", Int((m + 720) / 1440))
    }

    private func detailText(expiry: String?) -> String {
        var parts: [String] = []
        if let expiry { parts.append(expiry) }
        parts.append(L("send_views", Int(send.viewCount), Int(send.maxViews)))
        if send.hasPassword { parts.append(L("send_password_badge")) }
        return parts.joined(separator: " · ")
    }
}

/// Create a Send (task 3.6). A password needs Argon2id, which this device
/// gets with task 1.3.1; until then the password field says so.
struct NewSendView: View {
    @ObservedObject var model: VaultModel
    let onDone: () -> Void

    init(model: VaultModel, onDone: @escaping () -> Void) {
        self.model = model
        self.onDone = onDone
    }

    @State private var credential = false
    @State private var text = ""
    @State private var username = ""
    @State private var password = ""
    @State private var views = "1"
    @State private var expiry = 4
    @State private var hours = ""
    @State private var sendPassword = ""
    @State private var busy = false
    @State private var problem: String?
    @State private var created: CreatedSend?

    /// Argon2id is not on iOS yet (task 1.3.1), so a password send cannot be made here.
    private let passwordAvailable = false

    private let expiries: [(SendExpiry, String)] = [
        (.hour, "expiry_1h"), (.day, "expiry_1d"), (.twoDays, "expiry_2d"), (.threeDays, "expiry_3d"),
        (.sevenDays, "expiry_7d"), (.thirtyDays, "expiry_30d"), (.custom, "expiry_custom"),
    ]

    var body: some View {
        Form {
            if let created {
                Section {
                    Text(created.hasPassword ? L("send_created_password") : L("send_created"))
                    Text(created.link).font(.footnote).textSelection(.enabled)
                    if let url = URL(string: created.link) {
                        ShareLink(item: url) { Label(L("action_share"), systemImage: "square.and.arrow.up") }
                    }
                    Button(L("send_copy_link")) { model.copy(created.link) }
                    Button(L("action_close"), action: onDone)
                }
            } else {
                Section {
                    Picker(L("send_kind_text"), selection: $credential) {
                        Text(L("send_kind_text")).tag(false)
                        Text(L("send_kind_login")).tag(true)
                    }
                    .pickerStyle(.segmented)
                    if credential {
                        LabeledField(L("detail_username")) {
                            TextField(L("detail_username"), text: $username).textInputAutocapitalization(.never)
                        }
                        LabeledField(L("detail_password")) {
                            SecureField(L("detail_password"), text: $password)
                        }
                    } else {
                        LabeledField(L("send_text_label")) {
                            TextField(L("send_text_label"), text: $text, axis: .vertical).lineLimit(3...8)
                        }
                    }
                }
                Section {
                    LabeledField(L("send_views_label")) {
                        TextField(L("send_views_label"), text: $views).keyboardType(.numberPad)
                    }
                    Picker(L("send_expiry_label"), selection: $expiry) {
                        ForEach(expiries.indices, id: \.self) { i in Text(L(expiries[i].1)).tag(i) }
                    }
                    if expiries[expiry].0 == .custom {
                        LabeledField(L("send_hours_label")) {
                            TextField(L("send_hours_label"), text: $hours).keyboardType(.numberPad)
                        }
                    }
                    LabeledField(L("send_password_label")) {
                        SecureField(L("send_password_label"), text: $sendPassword).disabled(!passwordAvailable)
                    }
                    if !passwordAvailable { Text(L("send_password_unavailable")).font(.footnote) }
                }
                Section {
                    if let problem { Text(problem).foregroundStyle(.red) }
                    Button(L("send_create")) { create() }.disabled(busy)
                }
            }
        }
        .navigationTitle(L("send_new_title"))
    }

    private func create() {
        busy = true
        problem = nil
        let plaintext = credential ? SendForm.shared.credentialPayload(username: username, password: password) : text
        Task { @MainActor in
            let result = try? await model.session.sends.create(
                payloadType: credential ? .credential : .text,
                plaintext: plaintext,
                maxViews: views,
                expiry: expiries[expiry].0,
                customHours: hours,
                password: sendPassword,
                passwordAvailable: passwordAvailable
            )
            busy = false
            if let done = result?.valueOrNull as? CreatedSend {
                created = done
            } else if let failed = result?.problemOrNull {
                if let form = failed.form {
                    problem = sendProblemText(form)
                } else if let write = failed.write {
                    problem = writeProblemText(write)
                }
            } else {
                problem = L("write_failed")
            }
        }
    }

    private func sendProblemText(_ problem: SendFormProblem) -> String {
        if problem == .nothingToSend { return L("send_nothing") }
        if problem == .customHours { return L("send_hours_range") }
        if problem == .customHoursTooMany { return L("send_hours_max") }
        if problem == .viewsOutOfRange { return L("send_views_range") }
        return L("send_password_unavailable")
    }
}

/// Opens a Send link on this phone (task 3.6), decrypting on the device as
/// the public page does. Needs no account. Opening uses a view, so the app
/// asks first. Hand a tapped link to `initialLink`.
public struct OpenSendView: View {
    private let client = OpenSendClient.companion.platform()
    private let onCopy: ((String) -> Void)?
    @State private var link: String
    @State private var state: OpenSendResult?
    @State private var password = ""
    @State private var busy = false

    public init(initialLink: String, onCopy: ((String) -> Void)?) {
        _link = State(initialValue: initialLink)
        self.onCopy = onCopy
    }

    public var body: some View {
        let parsed = SendLink.companion.parse(link: link)
        let needsPassword = state is OpenSendResult.NeedsPassword || ((state as? OpenSendResult.WrongPassword)?.burned == false)
        Form {
            if !(state is OpenSendResult.Opened) {
                LabeledField(L("open_send_link")) {
                    TextField(L("open_send_link"), text: $link)
                        .keyboardType(.URL)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()
                }
                if !link.isEmpty && parsed == nil { Text(L("open_send_invalid")).foregroundStyle(.red) }
            }
            if state is OpenSendResult.Ready {
                Text(L("open_send_ready"))
            } else if state is OpenSendResult.NeedsPassword {
                Text(L("open_send_password"))
            } else if let wrong = state as? OpenSendResult.WrongPassword {
                Text(wrong.burned ? L("open_send_burned") : L("open_send_wrong", Int(wrong.attemptsLeft))).foregroundStyle(.red)
            } else if state is OpenSendResult.Gone {
                Text(L("open_send_gone"))
            } else if state is OpenSendResult.NoKey {
                Text(L("open_send_no_key"))
            } else if state is OpenSendResult.Failed {
                Text(L("open_send_failed")).foregroundStyle(.red)
            } else if let opened = state as? OpenSendResult.Opened {
                Text(opened.payload).textSelection(.enabled)
                if opened.burned { Text(L("open_send_last_view")).font(.footnote) }
                if let onCopy { Button(L("action_copy")) { onCopy(opened.payload) } }
            }
            if needsPassword {
                LabeledField(L("detail_password")) {
                    SecureField(L("detail_password"), text: $password)
                }
            }
            if let parsed, state is OpenSendResult.Ready || needsPassword {
                Button(L("action_open")) {
                    busy = true
                    Task { @MainActor in
                        state = try? await client.open(link: parsed, password: password)
                        busy = false
                    }
                }
                .disabled(busy || (needsPassword && password.isEmpty))
            }
        }
        .navigationTitle(L("open_send_title"))
        .task(id: link) {
            state = nil
            if let parsed { state = try? await client.peek(link: parsed) }
        }
    }
}
