// Tests of reply(to:pasteboard:). Build and run on macOS:
//   swiftc -o "${TMPDIR:-/tmp}/handler-tests" safari/SafariWebExtensionHandler.swift safari/tests/main.swift && "${TMPDIR:-/tmp}/handler-tests"
import AppKit

/// Counts like NSPasteboard: clearContents() and prepareForNewContents(with:) move changeCount; `countOnSet` makes setString move it too.
final class FakePasteboard: Pasteboard {
    var changeCount = 7
    var contents: [String: String] = [:]
    var refuse = false
    var options: NSPasteboard.ContentsOptions?
    var countOnSet = false

    func clearContents() -> Int {
        changeCount += 1
        contents = [:]
        return changeCount
    }

    func prepareForNewContents(with options: NSPasteboard.ContentsOptions) -> Int {
        self.options = options
        return clearContents()
    }

    func setString(_ string: String, forType dataType: NSPasteboard.PasteboardType) -> Bool {
        if refuse { return false }
        if countOnSet { changeCount += 1 }
        contents[dataType.rawValue] = string
        return true
    }
}

final class Results {
    var failed: [String] = []

    func expect(_ name: String, _ reply: [String: Any], _ expected: [String: Any]) {
        check(name, NSDictionary(dictionary: reply).isEqual(to: expected), "got \(reply)")
    }

    func check(_ name: String, _ passed: Bool, _ detail: String = "") {
        print("\(passed ? "ok  " : "FAIL") \(name)\(passed || detail.isEmpty ? "" : ": \(detail)")")
        if !passed { failed.append(name) }
    }
}

let results = Results()
let string = NSPasteboard.PasteboardType.string.rawValue
let concealed = "org.nspasteboard.ConcealedType"

do {
    let board = FakePasteboard()
    results.expect("write answers the new count", reply(to: ["kind": "clipboard.write", "text": "hunter2"], pasteboard: board), ["ok": true, "changeCount": 8])
    results.check("write puts the text on the pasteboard", board.contents[string] == "hunter2")
    results.check("write marks it concealed", board.contents[concealed] == "")
    results.check("write keeps it on this Mac", board.options == .currentHostOnly)
}

do {
    let board = FakePasteboard()
    board.countOnSet = true
    results.expect("write answers the count after its writes", reply(to: ["kind": "clipboard.write", "text": "hunter2"], pasteboard: board), ["ok": true, "changeCount": 10])
}

do {
    let board = FakePasteboard()
    board.refuse = true
    results.expect("a refused write fails", reply(to: ["kind": "clipboard.write", "text": "hunter2"], pasteboard: board), ["ok": false, "error": "write_failed"])
}

do {
    let board = FakePasteboard()
    let written = reply(to: ["kind": "clipboard.write", "text": "hunter2"], pasteboard: board)["changeCount"] as? Int ?? -1
    let clear: [String: Any] = ["kind": "clipboard.clear", "changeCount": written]
    results.expect("clear with a matching count", reply(to: clear, pasteboard: board), ["ok": true, "cleared": true])
    results.check("matching count empties the pasteboard", board.contents.isEmpty)
}

do {
    let board = FakePasteboard()
    _ = reply(to: ["kind": "clipboard.write", "text": "hunter2"], pasteboard: board)
    _ = board.clearContents()
    _ = board.setString("a sentence", forType: .string)
    results.expect("clear with a stale count", reply(to: ["kind": "clipboard.clear", "changeCount": 8] as [String: Any], pasteboard: board), ["ok": true, "cleared": false])
    results.check("stale count leaves what the user copied", board.contents[string] == "a sentence")
}

do {
    let board = FakePasteboard()
    _ = board.setString("anything", forType: .string)
    results.expect("clear without a count", reply(to: ["kind": "clipboard.clear"], pasteboard: board), ["ok": true, "cleared": true])
    results.check("clear without a count empties the pasteboard", board.contents.isEmpty)
}

let bad: [(String, Any?, String)] = [
    ("unknown kind", ["kind": "clipboard.read"] as [String: Any], "unknown_kind"),
    ("no kind", ["text": "hunter2"] as [String: Any], "unknown_kind"),
    ("missing text", ["kind": "clipboard.write"] as [String: Any], "bad_request"),
    ("non-string text", ["kind": "clipboard.write", "text": 42] as [String: Any], "bad_request"),
    ("fractional changeCount", ["kind": "clipboard.clear", "changeCount": 7.5] as [String: Any], "bad_request"),
    ("string changeCount", ["kind": "clipboard.clear", "changeCount": "7"] as [String: Any], "bad_request"),
    ("boolean changeCount", ["kind": "clipboard.clear", "changeCount": NSNumber(value: true)] as [String: Any], "bad_request"),
    ("not a dictionary", "clipboard.clear", "bad_request"),
    ("no message", nil, "bad_request"),
]
for (name, message, error) in bad {
    let board = FakePasteboard()
    _ = board.setString("kept", forType: .string)
    results.expect(name, reply(to: message, pasteboard: board), ["ok": false, "error": error])
    results.check("\(name) leaves the pasteboard", board.changeCount == 7 && board.contents[string] == "kept")
}

if results.failed.isEmpty {
    print("All handler tests passed")
} else {
    print("\(results.failed.count) handler test(s) failed")
    exit(1)
}
