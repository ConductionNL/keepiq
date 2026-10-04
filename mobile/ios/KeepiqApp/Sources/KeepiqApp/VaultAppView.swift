// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

/// The vault, Send and generator screens of one unlocked account (task
/// group 3). The unlock flow hands in the session; `accounts` and the
/// callbacks drive the account switcher.
public struct VaultAppView: View {
    @StateObject private var model: VaultModel
    private let accounts: [Account]
    private let onSwitchAccount: (Account) -> Void
    private let onAddAccount: (() -> Void)?
    @State private var showAccounts = false

    public init(
        session: MobileSession,
        accounts: [Account],
        onSwitchAccount: @escaping (Account) -> Void,
        onAddAccount: (() -> Void)?
    ) {
        _model = StateObject(wrappedValue: VaultModel(session: session))
        self.accounts = accounts
        self.onSwitchAccount = onSwitchAccount
        self.onAddAccount = onAddAccount
    }

    public var body: some View {
        TabView {
            NavigationStack {
                VaultListView(model: model, folderId: nil)
                    .toolbar { accountButton }
            }
            .tabItem { Label(L("tab_vault"), systemImage: "lock.rectangle.stack") }

            NavigationStack {
                GeneratorView(model: model, onUse: nil)
                    .navigationTitle(L("tab_generator"))
                    .toolbar { accountButton }
            }
            .tabItem { Label(L("tab_generator"), systemImage: "wand.and.stars") }

            NavigationStack {
                SendListView(model: model)
                    .toolbar { accountButton }
            }
            .tabItem { Label(L("tab_send"), systemImage: "paperplane") }
        }
        .task { await model.loadPolicy() }
        .overlay(alignment: .bottom) {
            if let toast = model.toast {
                Text(toast)
                    .padding(12)
                    .background(.thinMaterial, in: Capsule())
                    .padding(.bottom, 64)
                    .accessibilityAddTraits(.updatesFrequently)
                    .task(id: toast) {
                        try? await Task.sleep(nanoseconds: 3_000_000_000)
                        model.toast = nil
                    }
            }
        }
        .sheet(isPresented: $showAccounts) {
            AccountsView(
                current: model.session.account,
                accounts: accounts,
                onSwitch: { account in
                    showAccounts = false
                    onSwitchAccount(account)
                },
                onAdd: onAddAccount.map { add in { showAccounts = false; add() } }
            )
        }
    }

    private var accountButton: some ToolbarContent {
        ToolbarItem(placement: .topBarTrailing) {
            Button { showAccounts = true } label: { Image(systemName: "person.crop.circle") }
                .accessibilityLabel(L("cd_switch_account", model.session.label))
        }
    }
}

/// The account switcher and the clipboard delay.
struct AccountsView: View {
    let current: Account
    let accounts: [Account]
    let onSwitch: (Account) -> Void
    let onAdd: (() -> Void)?
    @State private var clearSeconds = VaultSettings.clipboardClearSeconds
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            List {
                Section {
                    ForEach(accounts, id: \.id) { account in
                        Button {
                            onSwitch(account)
                        } label: {
                            HStack {
                                Text(MobileSession.companion.labelOf(account: account))
                                Spacer()
                                if account.id == current.id { Image(systemName: "checkmark").accessibilityHidden(true) }
                            }
                        }
                        .accessibilityAddTraits(account.id == current.id ? .isSelected : [])
                    }
                    if let onAdd {
                        if accounts.count < 5 {
                            Button(L("accounts_add"), action: onAdd)
                        } else {
                            Text(L("accounts_limit")).font(.footnote)
                        }
                    }
                }
                Section(L("settings_clipboard")) {
                    Picker(L("settings_clipboard"), selection: $clearSeconds) {
                        ForEach(SensitiveClipboard.companion.CLEAR_CHOICES.map { Int(truncating: $0) }, id: \.self) { seconds in
                            Text(clipboardLabel(seconds)).tag(seconds)
                        }
                    }
                    .pickerStyle(.inline)
                    .labelsHidden()
                    .onChange(of: clearSeconds) { _, value in VaultSettings.clipboardClearSeconds = value }
                }
            }
            .navigationTitle(L("accounts_title"))
            .toolbar {
                ToolbarItem(placement: .confirmationAction) { Button(L("action_close")) { dismiss() } }
            }
        }
    }

    private func clipboardLabel(_ seconds: Int) -> String {
        if seconds == 0 { return L("settings_clipboard_never") }
        if seconds >= 120 { return L("settings_clipboard_minutes", seconds / 60) }
        return L("settings_clipboard_seconds", seconds)
    }
}
