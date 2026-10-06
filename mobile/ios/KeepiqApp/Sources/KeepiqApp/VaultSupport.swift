// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import Foundation
import KeepiqShared
import SwiftUI
import UIKit
import UniformTypeIdentifiers

/// A localized string from this package, with positional arguments.
func L(_ key: String, _ args: CVarArg...) -> String {
    let format = NSLocalizedString(key, bundle: .module, comment: "")
    return args.isEmpty ? format : String(format: format, arguments: args)
}

/// The system pasteboard for the shared SensitiveClipboard (task 3.3): local
/// only, so it never syncs to other devices, with an expiration date, so iOS
/// clears it even when the app is gone.
final class IOSClipboard: NSObject, ClipboardPort {
    private var ourChangeCount: Int?
    private var ourToken: String?

    func writeSensitive(text: String, expiresInSeconds: Int32) {
        var options: [UIPasteboard.OptionsKey: Any] = [.localOnly: true]
        if expiresInSeconds > 0 {
            options[.expirationDate] = Date().addingTimeInterval(TimeInterval(expiresInSeconds))
        }
        UIPasteboard.general.setItems([[UTType.plainText.identifier: text]], options: options)
        ourChangeCount = UIPasteboard.general.changeCount
        ourToken = SensitiveClipboard.companion.tokenOf(text: text)
    }

    func clearIfOurs(token: String) {
        guard token == ourToken, UIPasteboard.general.changeCount == ourChangeCount else { return }
        UIPasteboard.general.setItems([], options: [:])
        ourToken = nil
        ourChangeCount = nil
    }
}

/// Main-queue timers for the shared SensitiveClipboard.
final class MainQueueScheduler: NSObject, ClearScheduler {
    func schedule(delayMillis: Int64, action: ScheduledAction) -> PendingClear {
        let work = DispatchWorkItem { action.run() }
        DispatchQueue.main.asyncAfter(deadline: .now() + .milliseconds(Int(delayMillis)), execute: work)
        return WorkCancellable(work: work)
    }
}

private final class WorkCancellable: NSObject, PendingClear {
    private let work: DispatchWorkItem

    init(work: DispatchWorkItem) {
        self.work = work
    }

    func cancel() {
        work.cancel()
    }
}

/// Reads the stored delay at each copy.
final class StoredClearDelay: NSObject, ClearDelay {
    func seconds() -> Int32 { Int32(VaultSettings.clipboardClearSeconds) }
}

/// The clipboard delay, the same choices as the extension (default 60 seconds).
enum VaultSettings {
    private static let clipboardKey = "keepiq.clipboard-clear-seconds"

    static var clipboardClearSeconds: Int {
        get {
            let stored = UserDefaults.standard.object(forKey: clipboardKey) as? Int
            return stored ?? Int(SensitiveClipboard.companion.DEFAULT_CLEAR_SECONDS)
        }
        set { UserDefaults.standard.set(newValue, forKey: clipboardKey) }
    }
}

/// One unlocked account for the screens. The unlock flow (task group 2)
/// creates the shared MobileSession; this keeps the clipboard and the policy.
/// Kotlin suspend functions are called on the main thread, so the async
/// work here is main-actor isolated.
final class VaultModel: ObservableObject {
    let session: MobileSession
    let clipboard: SensitiveClipboard
    @Published var policy: GeneratorPolicy?
    @Published var toast: String?
    /// Called with the reason when a sync locks the vault (the keys changed elsewhere).
    var onLocked: ((String) -> Void)?

    init(session: MobileSession) {
        self.session = session
        self.clipboard = SensitiveClipboard(
            port: IOSClipboard(),
            scheduler: MainQueueScheduler(),
            clearSeconds: StoredClearDelay()
        )
    }

    var repository: VaultRepository { session.repository }

    @MainActor
    func loadPolicy() async {
        policy = try? await GeneratorPolicy.companion.fetch(api: session.api)
    }

    /// Called from the screens, on the main thread.
    func copy(_ value: String) {
        let seconds = clipboard.write(text: value)
        toast = seconds > 0 ? L("copied", Int(seconds)) : L("copied_kept")
    }
}

/// The text for a refused or failed write.
func writeProblemText(_ problem: WriteProblem) -> String {
    switch problem.kind {
    case .offline: return L("write_offline")
    case .keyMigration: return L("write_key_migration")
    case .suiteBlocked: return L("write_suite_blocked")
    case .serverMessage: return L("write_server", problem.serverMessage ?? "")
    case .refused: return L("write_refused")
    case .unreachable: return L("write_unreachable")
    default: return L("write_failed")
    }
}

func draftProblemText(_ problem: DraftProblem) -> String {
    switch problem {
    case .nameMissing: return L("problem_name_missing")
    case .tooLong: return L("problem_too_long")
    case .fieldNameMissing: return L("problem_field_name_missing")
    case .fieldNameReserved: return L("problem_field_name_reserved")
    case .fieldNameTaken: return L("problem_field_name_taken")
    case .notAnAuthenticatorSecret: return L("problem_not_totp")
    default: return L("problem_required")
    }
}

func relativeTime(millis: Int64) -> String {
    let formatter = RelativeDateTimeFormatter()
    formatter.unitsStyle = .full
    return formatter.localizedString(for: Date(timeIntervalSince1970: TimeInterval(millis) / 1000), relativeTo: Date())
}

func compositeLabel(_ field: String) -> String {
    switch field {
    case "number": return L("composite_number")
    case "expiry": return L("composite_expiry")
    case "cvv": return L("composite_cvv")
    case "pin": return L("composite_pin")
    case "cardholder": return L("composite_cardholder")
    case "firstName": return L("composite_first_name")
    case "lastName": return L("composite_last_name")
    case "address": return L("composite_address")
    case "phone": return L("composite_phone")
    case "email": return L("composite_email")
    default: return L("composite_bsn")
    }
}

/// Closes the keyboard, so it does not cover the screen a save or a new Send opens.
func dismissKeyboard() {
    UIApplication.shared.sendAction(#selector(UIResponder.resignFirstResponder), to: nil, from: nil, for: nil)
}

/// An input with its name shown above it. A placeholder disappears as soon
/// as the user types, and a secure field then shows nothing at all, so the
/// name stays visible and is the field's VoiceOver label too.
struct LabeledField<Field: View>: View {
    let title: String
    let field: Field

    init(_ title: String, @ViewBuilder field: () -> Field) {
        self.title = title
        self.field = field()
    }

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title).font(.footnote).accessibilityHidden(true)
            field.accessibilityLabel(title)
        }
    }
}

/// A labelled value with Show and Copy. Buttons name the field for VoiceOver
/// and keep a 44 point target.
struct FieldRow: View {
    let label: String
    let value: String
    var masked = false
    var copy = true
    let onCopy: (String) -> Void

    init(label: String, value: String, masked: Bool = false, copy: Bool = true, onCopy: @escaping (String) -> Void) {
        self.label = label
        self.value = value
        self.masked = masked
        self.copy = copy
        self.onCopy = onCopy
    }

    @State private var shown = false

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(label).font(.caption).foregroundStyle(KeepiqPalette.secondaryText)
            HStack {
                if masked && !shown {
                    Text("••••••••").accessibilityLabel(label)
                } else {
                    Text(value.isEmpty ? "-" : value)
                        .font(masked ? .body.monospaced() : .body)
                        .textSelection(.disabled)
                        // Wraps instead of clipping at large Dynamic Type sizes (a long address has no spaces).
                        .fixedSize(horizontal: false, vertical: true)
                }
                Spacer()
                if masked {
                    Button(shown ? L("action_hide") : L("action_show")) { shown.toggle() }
                        .frame(minWidth: 44, minHeight: 44)
                        .accessibilityLabel(shown ? L("cd_hide_field", label) : L("cd_show_field", label))
                }
                if copy && !value.isEmpty {
                    Button(L("action_copy")) { onCopy(value) }
                        .frame(minWidth: 44, minHeight: 44)
                        .accessibilityLabel(L("cd_copy_field", label))
                }
            }
        }
    }
}
