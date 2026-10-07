// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import SwiftUI

/// The AutoFill sheet (task 4.4): unlock, then the logins for the site, a
/// search over the vault and "Add login".
struct AutofillRootView: View {
    @ObservedObject var model: AutofillModel
    let onCancel: () -> Void

    var body: some View {
        NavigationStack {
            Group {
                switch model.phase {
                case .noAccount: Text(AL("noAccount")).padding().accessibilityIdentifier("autofillNoAccount")
                case .configuration: Text(AL("configured")).padding().accessibilityIdentifier("autofillConfigured")
                case .locked: AutofillUnlockView(model: model)
                case .list:
                    if model.passkey != nil { PasskeyListView(model: model) } else { AutofillListView(model: model) }
                case .working: ProgressView().accessibilityIdentifier("autofillBusy")
                }
            }
            .navigationTitle("Keepiq")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button(AL("cancel"), action: onCancel).accessibilityIdentifier("autofillCancel")
                }
            }
        }
    }
}

struct AutofillUnlockView: View {
    @ObservedObject var model: AutofillModel
    @State private var masterPassword = ""
    @State private var pin = ""
    @FocusState private var masterFocused: Bool

    var body: some View {
        Form {
            Section {
                Text(AL("unlock.title")).font(.headline).accessibilityAddTraits(.isHeader)
                if !model.site.isEmpty { Text(AL(model.passkey == nil ? "unlock.site" : "unlock.passkey", model.site)) }
            }
            if model.hasBiometric {
                Button(AL("unlock.biometric")) { model.unlockWithBiometric() }.accessibilityIdentifier("autofillBiometric")
            }
            if model.hasPin {
                Section {
                    SecureField(AL("unlock.pin"), text: $pin)
                        .textContentType(.oneTimeCode)
                        .keyboardType(.numberPad)
                        .accessibilityIdentifier("autofillPin")
                    Button(AL("unlock.button")) { model.unlock(pin: pin) }
                        .disabled(pin.isEmpty || model.busy)
                        .accessibilityIdentifier("autofillUnlockPin")
                }
            }
            Section {
                // Keepiq's own secrets are typed as one-time codes, not passwords, so iOS
                // never offers to save them in another password manager.
                SecureField(AL("unlock.master"), text: $masterPassword)
                    .textContentType(.oneTimeCode)
                    .focused($masterFocused)
                    .accessibilityIdentifier("autofillMasterPassword")
                Button(AL("unlock.button")) { model.unlock(masterPassword: masterPassword) }
                    .disabled(masterPassword.isEmpty || model.busy)
                    .accessibilityIdentifier("autofillUnlock")
            }
            if model.busy { ProgressView() }
            if let message = model.message {
                Text(message).foregroundStyle(.red).accessibilityIdentifier("autofillMessage")
            }
        }
        // Without biometrics or a PIN, the master password is the way in: start there.
        .onAppear { if !model.hasBiometric && !model.hasPin { masterFocused = true } }
    }
}

struct AutofillListView: View {
    @ObservedObject var model: AutofillModel
    @State private var adding = false
    @State private var user = ""
    @State private var password = ""

    var body: some View {
        List {
            Section {
                TextField(AL("search"), text: $model.query)
                    .textInputAutocapitalization(.never)
                    .autocorrectionDisabled()
                    .onSubmit { model.search() }
                    .accessibilityIdentifier("autofillSearch")
            }
            Section(model.query.isEmpty ? AL("list.forSite", model.site) : AL("list.results")) {
                if model.rows.isEmpty {
                    Text(model.query.isEmpty ? AL("list.none", model.site) : AL("list.noMatch"))
                        .accessibilityIdentifier("autofillEmpty")
                }
                ForEach(model.rows) { row in
                    Button { model.choose(row) } label: {
                        VStack(alignment: .leading) {
                            Text(row.name)
                            Text(row.site).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                    .accessibilityIdentifier("autofillRow")
                }
            }
            Section {
                if adding {
                    // No .username or .newPassword: those make iOS offer its own strong
                    // password and save prompt over the sheet. Keepiq keeps the login itself.
                    TextField(AL("add.user"), text: $user)
                        .textInputAutocapitalization(.never)
                        .accessibilityIdentifier("autofillAddUser")
                    SecureField(AL("add.password"), text: $password)
                        .textContentType(.oneTimeCode)
                        .accessibilityIdentifier("autofillAddPassword")
                    Button(AL("add.save")) { model.addLogin(user: user, password: password) { password = "" } }
                        .disabled(password.isEmpty || model.busy)
                        .accessibilityIdentifier("autofillAddSave")
                } else {
                    Button(AL("add.title", model.site)) { adding = true }.accessibilityIdentifier("autofillAdd")
                }
            }
            if let message = model.message {
                Text(message).foregroundStyle(.red).accessibilityIdentifier("autofillMessage")
            }
        }
    }
}

/// The passkeys for the site (task 5.2), when more than one fits or none
/// does. One that fits signs at once, without this list.
struct PasskeyListView: View {
    @ObservedObject var model: AutofillModel

    var body: some View {
        List {
            Section(AL("passkey.list", model.site)) {
                if model.passkeyRows.isEmpty {
                    Text(AL("passkey.none", model.site)).accessibilityIdentifier("passkeyEmpty")
                }
                ForEach(model.passkeyRows) { row in
                    Button { model.choosePasskey(row) } label: {
                        VStack(alignment: .leading) {
                            Text(row.user)
                            Text(row.name).font(.caption).foregroundStyle(.secondary)
                        }
                    }
                    .accessibilityIdentifier("passkeyRow")
                }
            }
            if let message = model.message {
                Text(message).foregroundStyle(.red).accessibilityIdentifier("autofillMessage")
            }
        }
    }
}
