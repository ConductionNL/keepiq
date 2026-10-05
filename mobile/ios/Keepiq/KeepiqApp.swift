// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import KeepiqShared
import SwiftUI

@main
struct KeepiqApp: App {
    @StateObject private var model = AppModel()
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView()
                .environmentObject(model)
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { model.cameToForeground() }
        }
    }
}

/// One screen at a time (tasks 2.1 to 2.6).
struct RootView: View {
    @EnvironmentObject private var model: AppModel

    var body: some View {
        switch model.screen {
        case .unlocked(let vault, let session):
            // The vault brings its own tabs and navigation stacks.
            VaultAppView(
                session: session,
                accounts: model.accounts,
                onSwitchAccount: { model.switchAccount($0.accountId) },
                onAddAccount: { model.addAccount() },
                onLock: { model.lock() },
                onSettings: { model.openSettings(vault) },
                onLocked: { model.lock(reason: $0) }
            )
            .id(ObjectIdentifier(session))
        default:
            NavigationStack {
                switch model.screen {
                case .pair: PairView()
                case .unlock(let accountId): UnlockView(accountId: accountId)
                case .settings(let vault): SettingsView(vault: vault)
                case .unlocked: EmptyView()
                }
            }
        }
    }
}

/// The last problem, read out by VoiceOver when it appears.
struct ProblemText: View {
    let message: String?

    var body: some View {
        if let message {
            Text(message)
                .foregroundStyle(.red)
                .accessibilityIdentifier("message")
                .accessibilityAddTraits(.updatesFrequently)
        }
    }
}

extension String {
    var withoutScheme: String { replacingOccurrences(of: "https://", with: "") }
}
