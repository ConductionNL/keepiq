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
        screens
        #if DEBUG
        .overlay(alignment: .bottom) { AutofillPreviewButton() }
        #endif
    }

    @ViewBuilder private var screens: some View {
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

#if DEBUG
/// UI tests only (-keepiq-autofill-preview): the AutoFill extension's screens
/// inside the app, since a simulator test cannot pick Keepiq as the
/// provider in Settings. The model, files and Keychain are the extension's.
struct AutofillPreviewButton: View {
    @EnvironmentObject private var model: AppModel
    @ObservedObject private var index = IOSAutofillIndex.shared
    @State private var preview: AutofillModel?
    @State private var filled: String?

    var body: some View {
        if ProcessInfo.processInfo.arguments.contains("-keepiq-autofill-preview"), case .unlocked(let vault, _) = model.screen {
            VStack {
                if let filled { Text(filled).accessibilityIdentifier("autofillFilled") }
                Text(indexLine(vault.accountId))
                    .accessibilityIdentifier("autofillIndex")
                Button("AutoFill preview") {
                    preview = AutofillModel(serviceIdentifiers: [site]) { login in
                        filled = "filled: \(login.user) / \(login.password.count)"
                        preview = nil
                        self.model.refreshAutofill(vault)
                    }
                }
                .accessibilityIdentifier("autofillPreview")
            }
            .padding()
            .sheet(item: Binding(get: { preview.map(PreviewBox.init) }, set: { if $0 == nil { preview = nil } })) { box in
                AutofillRootView(model: box.model, onCancel: { preview = nil })
            }
        }
    }

    private var site: String { ProcessInfo.processInfo.environment["KEEPIQ_AUTOFILL_SITE"] ?? "example.com" }

    /// What is on disk for the asked site, read back from its file, not counted on the way in.
    private func indexLine(_ accountId: String) -> String {
        let key = AutofillSites.shared.siteKey(serviceIdentifier: site)
        let logins = AutofillFiles.shared.read(accountId: accountId, site: key)
            .map { AutofillIndex.companion.fromJson(text: $0).entries.count } ?? 0
        let where_ = AutofillFiles.shared.isShared ? "shared" : "app only"
        return "\(key): \(logins) logins on disk (\(where_)), sites \(index.lastSiteCount), identities \(index.lastIdentityCount)"
    }

    private struct PreviewBox: Identifiable {
        let model: AutofillModel
        var id: ObjectIdentifier { ObjectIdentifier(model) }
    }
}
#endif

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
