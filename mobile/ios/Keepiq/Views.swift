// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// Connect an account (2.1): the server address, then the server's own login
/// page in the browser sheet; an app password as the fallback.
struct PairView: View {
    @EnvironmentObject private var model: AppModel
    @State private var server = ""
    @State private var manual = false
    @State private var loginName = ""
    @State private var appPassword = ""

    var body: some View {
        Form {
            if model.waiting != nil {
                Section {
                    Text("Sign in on the page that opened, then come back here. Keepiq waits up to 20 minutes.")
                    ProgressView().accessibilityIdentifier("waiting")
                    Button("Cancel", role: .cancel) { model.cancelLogin() }.accessibilityIdentifier("cancelLogin")
                }
            } else {
                Section {
                    LabeledField("Server address") {
                        TextField("Server address", text: $server, prompt: Text("cloud.example.com"))
                            .textContentType(.URL)
                            .keyboardType(.URL)
                            .textInputAutocapitalization(.never)
                            .autocorrectionDisabled()
                            .accessibilityIdentifier("server")
                    }
                }
                if !manual {
                    Section {
                        Button("Sign in with your browser") { model.startLogin(server: server) }
                            .disabled(model.busy || server.isEmpty)
                            .accessibilityIdentifier("signIn")
                        Button("Use an app password instead") { manual = true }
                            .accessibilityIdentifier("useAppPassword")
                    }
                } else {
                    Section(footer: Text("Create an app password in Nextcloud under Personal settings, Security.")) {
                        LabeledField("User name") {
                            TextField("User name", text: $loginName, prompt: Text("Your Nextcloud user name"))
                                .textInputAutocapitalization(.never)
                                .autocorrectionDisabled()
                                .accessibilityIdentifier("loginName")
                        }
                        LabeledField("App password") {
                            SecureField("App password", text: $appPassword, prompt: Text("Paste the app password"))
                                .textContentType(.oneTimeCode)
                                .accessibilityIdentifier("appPassword")
                        }
                        Button("Connect") { model.pairManually(server: server, loginName: loginName, appPassword: appPassword) }
                            .disabled(model.busy || server.isEmpty || loginName.isEmpty || appPassword.isEmpty)
                            .accessibilityIdentifier("connect")
                        Button("Sign in with your browser instead") { manual = false }
                    }
                }
            }
            if model.busy { ProgressView() }
            ProblemText(message: model.message)
            if let active = model.client.accounts.activeId() {
                Button("Back to your accounts") { model.switchAccount(active) }
            }
        }
        .navigationTitle("Connect to Nextcloud")
    }
}

/// Unlock (2.2, 2.3): the master password, a PIN or Face ID. A blocked
/// unlock says why and shows no field.
struct UnlockView: View {
    @EnvironmentObject private var model: AppModel
    let accountId: String
    @State private var password = ""
    @State private var pin = ""
    @State private var usePassword = false

    var body: some View {
        let account = model.account(accountId)
        let pinSet = model.client.pins.has(accountId: accountId)
        let biometricOn = model.biometric.isEnabled(accountId) && model.biometric.canUse()
        Form {
            if let account {
                Text("\(account.loginName) on \(account.server.withoutScheme)").accessibilityIdentifier("account")
            }
            if let blocked = model.gate as? UnlockGate.Blocked {
                Section {
                    Text(blocked.message).accessibilityIdentifier("blocked")
                    Button("Check again") { model.refreshGate(accountId) }.disabled(model.busy)
                }
            } else if let ready = model.gate as? UnlockGate.Ready {
                if ready.offline {
                    Text("You are offline. Keepiq unlocks with what this phone stored at the last unlock.")
                }
                if biometricOn {
                    Button("Unlock with Face ID or Touch ID") { model.unlockWithBiometric(accountId) }
                        .accessibilityIdentifier("biometric")
                }
                if pinSet && !usePassword {
                    Section {
                        LabeledField("PIN") {
                            SecureField("PIN", text: $pin, prompt: Text("Your PIN"))
                                .textContentType(.oneTimeCode)
                                .keyboardType(.numberPad)
                                .accessibilityIdentifier("pin")
                        }
                        Button("Unlock with PIN") { model.unlock(accountId, pin: pin); pin = "" }
                            .disabled(model.busy || pin.isEmpty)
                            .accessibilityIdentifier("unlockPin")
                        Button("Use your master password") { usePassword = true }
                    }
                } else {
                    Section {
                        // Keepiq's own secrets are typed as one-time codes, not passwords, so iOS
                        // never offers to save them in another password manager.
                        LabeledField("Master password") {
                            SecureField("Master password", text: $password, prompt: Text("Your master password"))
                                .textContentType(.oneTimeCode)
                                .accessibilityIdentifier("masterPassword")
                                .onSubmit { model.unlock(accountId, masterPassword: password); password = "" }
                        }
                        Button("Unlock") { model.unlock(accountId, masterPassword: password); password = "" }
                            .disabled(model.busy || password.isEmpty)
                            .accessibilityIdentifier("unlock")
                    }
                }
            } else {
                ProgressView()
            }
            if model.busy { ProgressView().accessibilityIdentifier("busy") }
            ProblemText(message: model.message)
            Section {
                ForEach(model.accounts.filter { $0.accountId != accountId }, id: \.accountId) { other in
                    Button("Switch to \(other.loginName) on \(other.server.withoutScheme)") { model.switchAccount(other.accountId) }
                }
                if model.accounts.count < 5 {
                    Button("Connect another account") { model.addAccount() }.accessibilityIdentifier("addAccount")
                }
            }
        }
        .navigationTitle("Unlock Keepiq")
        .task(id: accountId) {
            if model.gate == nil { model.refreshGate(accountId) }
        }
    }
}

/// Unlock options (2.3), auto-lock (2.5) and disconnect (2.6).
struct SettingsView: View {
    @EnvironmentObject private var model: AppModel
    let vault: UnlockedVault
    @State private var newPin = ""
    @State private var confirmUnpair = false

    var body: some View {
        let _ = model.revision
        let biometricOn = model.biometric.isEnabled(vault.accountId)
        let pinSet = model.client.pins.has(accountId: vault.accountId)
        Form {
            Section("Unlock") {
                Toggle("Face ID or Touch ID", isOn: Binding(
                    get: { biometricOn },
                    set: { on in on ? model.enableBiometric(vault) : model.disableBiometric(vault) }
                ))
                .disabled(!model.biometric.canUse())
                .accessibilityIdentifier("biometricSwitch")
                if !model.client.pins.available {
                    Text("PIN unlock is not available on this phone.")
                } else if pinSet {
                    Text("A PIN is set. Five wrong PINs delete it.")
                    Button("Remove the PIN") { model.removePin(vault) }.accessibilityIdentifier("removePin")
                } else {
                    LabeledField("New PIN") {
                        SecureField("New PIN", text: $newPin, prompt: Text("6 to 64 characters"))
                            .textContentType(.oneTimeCode)
                            .keyboardType(.numberPad)
                            .accessibilityIdentifier("newPin")
                    }
                    Button("Set PIN") { model.setPin(vault, pin: newPin); newPin = "" }
                        .disabled(model.busy || newPin.count < 6 || newPin.count > 64)
                        .accessibilityIdentifier("setPin")
                }
            }
            Section("Account") {
                if let account = model.account(vault.accountId) {
                    Text("\(account.loginName) on \(account.server.withoutScheme)")
                }
                Button("Disconnect this account", role: .destructive) { confirmUnpair = true }
                    .accessibilityIdentifier("unpair")
            }
            Section("Lock after") {
                if let max = model.maxIdle {
                    Text("Your organisation allows at most \(max) minutes.").font(.footnote)
                }
                Picker("Lock after", selection: Binding(
                    get: { model.idleChoice(vault.accountId) },
                    set: { model.setIdle(vault, minutes: $0) }
                )) {
                    ForEach(model.offeredIdleChoices(), id: \.self) { minutes in
                        Text(minutes == 1 ? "1 minute" : "\(minutes) minutes").tag(minutes)
                    }
                }
                .pickerStyle(.inline)
                .labelsHidden()
            }
            ProblemText(message: model.message)
        }
        .navigationTitle("Unlock and account")
        .toolbar {
            ToolbarItem(placement: .cancellationAction) {
                Button("Back") { model.closeSettings(vault) }.accessibilityIdentifier("back")
            }
        }
        .alert("Disconnect this account?", isPresented: $confirmUnpair) {
            Button("Disconnect", role: .destructive) { model.unpair(vault.accountId) }
            Button("Cancel", role: .cancel) {}
        } message: {
            Text("Keepiq deletes its app password in Nextcloud and removes everything it stored for this account on this phone.")
        }
    }
}
