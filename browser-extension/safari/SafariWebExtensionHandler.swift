// Writes and clears the clipboard for the Safari extension (spec `safari-clipboard`, design § Native clipboard).
// Stateless, and never logs the copied text. Replaces the packager's file; the class name is in its Info.plist.
import AppKit
import os.log

protocol Pasteboard: AnyObject {
    var changeCount: Int { get }
    func clearContents() -> Int
    func prepareForNewContents(with options: NSPasteboard.ContentsOptions) -> Int
    func setString(_ string: String, forType dataType: NSPasteboard.PasteboardType) -> Bool
}

extension NSPasteboard: Pasteboard {}

/// `SFExtensionMessageKey`'s value, spelled out so this file needs no SafariServices and the test binary links none.
private let messageKey = "message"

/// Integers only: JavaScript's `true` and `1.5` also arrive as `NSNumber`.
private func integer(_ value: Any?) -> Int? {
    guard let number = value as? NSNumber, CFGetTypeID(number) != CFBooleanGetTypeID() else { return nil }
    return number as? Int
}

private func failure(_ error: String) -> [String: Any] {
    ["ok": false, "error": error]
}

/// The whole protocol; `SafariWebExtensionHandler` only unwraps the request and sends this back.
func reply(to message: Any?, pasteboard: Pasteboard) -> [String: Any] {
    guard let request = message as? [String: Any] else { return failure("bad_request") }
    switch request["kind"] as? String ?? "" {
    case "clipboard.write":
        guard let text = request["text"] as? String else { return failure("bad_request") }
        // This Mac only: Universal Clipboard must not send the secret to the user's other devices.
        _ = pasteboard.prepareForNewContents(with: .currentHostOnly)
        guard pasteboard.setString(text, forType: .string) else { return failure("write_failed") }
        // Asks clipboard managers to keep the value out of their history (nspasteboard.org).
        _ = pasteboard.setString("", forType: NSPasteboard.PasteboardType("org.nspasteboard.ConcealedType"))
        // Read after the writes: if anything moved the count since, the clear fails toward clearing.
        return ["ok": true, "changeCount": pasteboard.changeCount]
    case "clipboard.clear":
        let expected = request["changeCount"]
        if expected == nil {
            _ = pasteboard.clearContents()
            return ["ok": true, "cleared": true]
        }
        guard let changeCount = integer(expected) else { return failure("bad_request") }
        // Someone copied since: leave their value.
        guard pasteboard.changeCount == changeCount else { return ["ok": true, "cleared": false] }
        _ = pasteboard.clearContents()
        return ["ok": true, "cleared": true]
    default:
        return failure("unknown_kind")
    }
}

class SafariWebExtensionHandler: NSObject, NSExtensionRequestHandling {
    func beginRequest(with context: NSExtensionContext) {
        let item = context.inputItems.first as? NSExtensionItem
        let answer = reply(to: item?.userInfo?[messageKey], pasteboard: NSPasteboard.general)
        let outcome = (answer["error"] as? String) ?? "ok"
        os_log(.default, "Keepiq native message: %{public}@", outcome)

        // Always completed, also for an error, so the extension's promise never hangs.
        let response = NSExtensionItem()
        response.userInfo = [messageKey: answer]
        context.completeRequest(returningItems: [response], completionHandler: nil)
    }
}
