// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

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
        NavigationStack {
            switch model.screen {
            case .pair: PairView()
            case .unlock(let accountId): UnlockView(accountId: accountId)
            case .unlocked(let vault): UnlockedView(vault: vault)
            case .settings(let vault): SettingsView(vault: vault)
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
