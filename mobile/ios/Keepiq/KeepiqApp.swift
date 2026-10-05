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
        #if DEBUG
        .overlay(alignment: .bottom) { AutofillPreviewButton() }
        #endif
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
        if ProcessInfo.processInfo.arguments.contains("-keepiq-autofill-preview"), case .unlocked(let vault) = model.screen {
            VStack {
                if let filled { Text(filled).accessibilityIdentifier("autofillFilled") }
                Text("sites \(index.lastSiteCount), identities \(index.lastIdentityCount)")
                    .accessibilityIdentifier("autofillIndex")
                Button("AutoFill preview") {
                    let site = ProcessInfo.processInfo.environment["KEEPIQ_AUTOFILL_SITE"] ?? "example.com"
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
