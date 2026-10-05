// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import XCTest

/// Offline reading on the iOS simulator (tasks 1.6.1 and 3.4): the vault is
/// synced into the encrypted store, the replay then drops every connection
/// (POST /__e2e/offline in mobile/e2e/server.mjs), and the app, started
/// again, unlocks and reads from what it stored: the list with the
/// last-synced note, an item with its password, and every edit refused.
final class OfflineUITests: XCTestCase {
    private var app: XCUIApplication!
    private let env = ProcessInfo.processInfo.environment
    private var server: String { env["KEEPIQ_SERVER"] ?? "https://localhost:8443" }

    override func setUpWithError() throws {
        continueAfterFailure = false
        try setOffline(false)
        app = XCUIApplication()
        app.launchArguments = ["-keepiq-reset"]
        app.launchEnvironment["KEEPIQ_UITEST_NO_BROWSER"] = "1"
        app.launch()
    }

    override func tearDownWithError() throws {
        // The next test class needs the replay answering again.
        try setOffline(false)
    }

    /// Switches the replay's network off or on. The test runner trusts its
    /// certificate: ios-run.sh adds it to this simulator's roots.
    private func setOffline(_ offline: Bool) throws {
        var request = URLRequest(url: URL(string: "\(server)/__e2e/offline")!)
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: ["offline": offline])
        let done = expectation(description: "replay offline \(offline)")
        var status = 0
        var failure: Error?
        URLSession.shared.dataTask(with: request) { _, response, error in
            status = (response as? HTTPURLResponse)?.statusCode ?? 0
            failure = error
            done.fulfill()
        }.resume()
        wait(for: [done], timeout: 30)
        if let failure { throw failure }
        XCTAssertEqual(status, 200, "the replay did not switch offline to \(offline)")
    }

    private func shot(_ name: String) {
        let screenshot = XCUIScreen.main.screenshot()
        let attachment = XCTAttachment(screenshot: screenshot)
        attachment.name = name
        attachment.lifetime = .keepAlways
        add(attachment)
        if let dir = env["KEEPIQ_SHOTS_DIR"] {
            try? screenshot.pngRepresentation.write(to: URL(fileURLWithPath: dir).appendingPathComponent("\(name).png"))
        }
    }

    private func type(_ text: String, into element: XCUIElement) {
        XCTAssertTrue(element.waitForExistence(timeout: 20), "\(element) is missing")
        // A prompt sliding in or away can take the focus back: tap until the field has it.
        for _ in 0..<5 where !element.hasKeyboardFocusNow {
            dismissSavePassword()
            let deadline = Date().addingTimeInterval(3)
            while !element.isHittable && Date() < deadline { usleep(250_000) }
            element.tap()
            if !element.hasKeyboardFocusNow { usleep(500_000) }
        }
        XCTAssertTrue(element.hasKeyboardFocusNow, "\(element) does not take the keyboard focus")
        element.typeText(text)
        let tip = app.staticTexts.matching(NSPredicate(format: "label BEGINSWITH %@", "Speed up your typing")).firstMatch
        if tip.exists, app.buttons["Continue"].exists { app.buttons["Continue"].tap() }
    }

    private func tap(_ element: XCUIElement, timeout: TimeInterval = 20) {
        XCTAssertTrue(element.waitForExistence(timeout: timeout), "\(element) is missing")
        dismissSavePassword()
        let deadline = Date().addingTimeInterval(5)
        while !element.isHittable && Date() < deadline { usleep(250_000) }
        element.tap()
    }

    /// iOS offers to save the app password typed while pairing; not part of this test.
    private func dismissSavePassword() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].exists {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    /// Connecting with an app password makes iOS offer to save it a moment
    /// later, in the app or in SpringBoard. Answer it before typing: keys
    /// typed while it slides in are lost.
    private func answerSavePromptAfterConnect() {
        let springboard = XCUIApplication(bundleIdentifier: "com.apple.springboard")
        for owner in [app!, springboard] where owner.buttons["Not Now"].waitForExistence(timeout: 4) {
            owner.buttons["Not Now"].tap()
            return
        }
    }

    private func text(_ label: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label == %@", label)).firstMatch
    }

    private func textStarting(_ prefix: String) -> XCUIElement {
        app.descendants(matching: .any).matching(NSPredicate(format: "label BEGINSWITH %@", prefix)).firstMatch
    }

    private func unlock() {
        let master = app.secureTextFields["masterPassword"]
        XCTAssertTrue(master.waitForExistence(timeout: 60))
        answerSavePromptAfterConnect()
        type("Oj", into: master)
        tap(app.buttons["unlock"])
        XCTAssertTrue(app.buttons["lock"].waitForExistence(timeout: 60), "the vault opens after unlock")
    }

    func testReadsTheStoredVaultWhileTheServerIsUnreachable() throws {
        // Pair and unlock online: the first sync fills the encrypted store.
        type(server, into: app.textFields["server"])
        tap(app.buttons["useAppPassword"])
        type("admin", into: app.textFields["loginName"])
        type("manual-app-password", into: app.secureTextFields["appPassword"])
        tap(app.buttons["connect"])
        unlock()
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 60))
        XCTAssertTrue(textStarting("Last synced").waitForExistence(timeout: 20), "the online list shows when it synced")
        shot("50-online-synced")

        // The network goes; the app starts again from cold, so only the
        // store on disk and the Keychain hold the vault.
        try setOffline(true)
        app.terminate()
        app.launchArguments = []
        app.launch()
        unlock()

        // The list from the store, with the last-synced note.
        XCTAssertTrue(text("Webmail (demo)").waitForExistence(timeout: 60), "the stored vault is listed offline")
        XCTAssertTrue(text("Authenticator (demo)").exists)
        XCTAssertTrue(textStarting("You are offline. You see the copy from").waitForExistence(timeout: 20), "the last-synced note")
        XCTAssertFalse(app.buttons["Add an item"].exists, "adding an item is offered offline")
        shot("51-offline-list")

        // An item opens from the store, and its password still shows.
        tap(text("Webmail (demo)"))
        XCTAssertTrue(text("anna.demo@example.com").waitForExistence(timeout: 60))
        XCTAssertTrue(text("You are offline. This is the copy from your last sync.").exists)
        tap(app.buttons["Show Password"])
        XCTAssertTrue(text("Lantern-Orbit-42!").waitForExistence(timeout: 10))

        // Every edit is refused before anything is sent.
        let edit = app.buttons["Edit"]
        XCTAssertTrue(edit.waitForExistence(timeout: 10))
        XCTAssertFalse(edit.isEnabled, "Edit is offered offline")
        XCTAssertFalse(app.buttons["Move"].isEnabled, "Move is offered offline")
        XCTAssertTrue(text("Edits need a connection. Nothing was changed.").exists)
        shot("52-offline-item")
    }
}

private extension XCUIElement {
    /// Whether this field has the keyboard focus right now.
    var hasKeyboardFocusNow: Bool { (value(forKey: "hasKeyboardFocus") as? Bool) ?? false }
}
