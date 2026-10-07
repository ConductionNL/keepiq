// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

import AuthenticationServices
import SwiftUI
import UIKit

/// Keepiq as the iOS AutoFill provider (mobile-system-autofill, task 4.4).
///
/// - prepareCredentialList: the logins for the asked sites, read from their
///   per-site files only, after an unlock in the sheet.
/// - provideCredentialWithoutUserInteraction: fills at once when this
///   extension opened the vault within the idle time; otherwise iOS shows
///   prepareInterfaceToProvideCredential, which unlocks and then fills.
/// - After a password fill, the site's one-time code goes on the clipboard
///   for 60 seconds (task 4.5, iOS 17 path).
/// - Passkeys (task 5.2, iOS 17): the same unlock, then the assertion or the
///   new passkey, signed with the shared core's ES256 over the clientDataHash
///   iOS built for the rpId it verified. A request that allows no ES256 is
///   declined, so iOS can ask another provider.
final class CredentialProviderViewController: ASCredentialProviderViewController {
    private var host: UIHostingController<AutofillRootView>?

    override func prepareCredentialList(for serviceIdentifiers: [ASCredentialServiceIdentifier]) {
        show(AutofillModel(serviceIdentifiers: serviceIdentifiers.map(\.identifier), onFill: complete))
    }

    override func prepareInterfaceToProvideCredential(for credentialIdentity: ASPasswordCredentialIdentity) {
        show(AutofillModel(serviceIdentifiers: [credentialIdentity.serviceIdentifier.identifier], record: credentialIdentity.recordIdentifier, onFill: complete))
    }

    override func provideCredentialWithoutUserInteraction(for credentialIdentity: ASPasswordCredentialIdentity) {
        let model = AutofillModel(serviceIdentifiers: [credentialIdentity.serviceIdentifier.identifier], onFill: { _ in })
        guard model.phase == .list, let record = credentialIdentity.recordIdentifier, let filled = model.fill(record: record) else {
            extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.userInteractionRequired.rawValue))
            return
        }
        complete(filled)
    }

    // MARK: iOS 17 requests: passwords and passkeys

    override func prepareCredentialList(for serviceIdentifiers: [ASCredentialServiceIdentifier], requestParameters: ASPasskeyCredentialRequestParameters) {
        let request = PasskeyRequest.assertion(
            rpId: requestParameters.relyingPartyIdentifier,
            clientDataHash: requestParameters.clientDataHash,
            credentialId: nil,
            allowed: requestParameters.allowedCredentials
        )
        show(AutofillModel(serviceIdentifiers: [requestParameters.relyingPartyIdentifier], passkey: request, onPasskey: completePasskey, onFill: complete))
    }

    override func prepareInterfaceToProvideCredential(for credentialRequest: ASCredentialRequest) {
        switch credentialRequest.type {
        case .passkeyAssertion:
            guard let request = credentialRequest as? ASPasskeyCredentialRequest,
                  let identity = request.credentialIdentity as? ASPasskeyCredentialIdentity else { return fail() }
            let passkey = PasskeyRequest.assertion(rpId: identity.relyingPartyIdentifier, clientDataHash: request.clientDataHash, credentialId: identity.credentialID, allowed: [])
            show(AutofillModel(serviceIdentifiers: [identity.relyingPartyIdentifier], passkey: passkey, onPasskey: completePasskey, onFill: complete))
        case .password:
            guard let identity = credentialRequest.credentialIdentity as? ASPasswordCredentialIdentity else { return fail() }
            prepareInterfaceToProvideCredential(for: identity)
        default:
            fail()
        }
    }

    override func provideCredentialWithoutUserInteraction(for credentialRequest: ASCredentialRequest) {
        if credentialRequest.type == .password, let identity = credentialRequest.credentialIdentity as? ASPasswordCredentialIdentity {
            provideCredentialWithoutUserInteraction(for: identity)
            return
        }
        // A passkey signs in the sheet: it writes a counter back, which needs the network.
        extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.userInteractionRequired.rawValue))
    }

    override func prepareInterface(forPasskeyRegistration registrationRequest: ASCredentialRequest) {
        guard let request = registrationRequest as? ASPasskeyCredentialRequest,
              let identity = request.credentialIdentity as? ASPasskeyCredentialIdentity else { return fail() }
        let algorithms = request.supportedAlgorithms.map { Int($0.rawValue) }
        // ES256 (-7) only, as the extension; an empty list means the default, which includes it.
        guard algorithms.isEmpty || algorithms.contains(-7) else { return fail() }
        let passkey = PasskeyRequest.registration(
            rpId: identity.relyingPartyIdentifier,
            userName: identity.userName,
            userHandle: identity.userHandle,
            clientDataHash: request.clientDataHash,
            algorithms: algorithms
        )
        show(AutofillModel(serviceIdentifiers: [identity.relyingPartyIdentifier], passkey: passkey, onPasskey: completePasskey, onFill: { _ in }))
    }

    private func completePasskey(_ result: PasskeyResult) {
        switch result {
        case .assertion(let signed, let rpId, let hash):
            guard let signature = Data(base64Encoded: signed.signature),
                  let authenticatorData = Data(base64Encoded: signed.authenticatorData),
                  let credentialId = Data(base64Encoded: signed.credentialId) else { return fail() }
            let credential = ASPasskeyAssertionCredential(
                userHandle: Data(base64Encoded: signed.userHandle) ?? Data(),
                relyingParty: rpId,
                signature: signature,
                clientDataHash: hash,
                authenticatorData: authenticatorData,
                credentialID: credentialId
            )
            extensionContext.completeAssertionRequest(using: credential, completionHandler: nil)
        case .registration(let made, let rpId, let hash, let userHandle):
            guard let credentialId = Data(base64Encoded: made.credentialId),
                  let attestation = Data(base64Encoded: made.attestationObject) else { return fail() }
            // Offer the new passkey in the QuickType bar before the app's next sync.
            let identity = ASPasskeyCredentialIdentity(
                relyingPartyIdentifier: rpId,
                userName: made.userName,
                credentialID: credentialId,
                userHandle: userHandle,
                recordIdentifier: nil
            )
            ASCredentialIdentityStore.shared.saveCredentialIdentities([identity]) { _, _ in }
            let credential = ASPasskeyRegistrationCredential(relyingParty: rpId, clientDataHash: hash, credentialID: credentialId, attestationObject: attestation)
            extensionContext.completeRegistrationRequest(using: credential, completionHandler: nil)
        }
    }

    /// Declines, so iOS can offer another provider.
    private func fail() {
        extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.failed.rawValue))
    }

    override func prepareInterfaceForExtensionConfiguration() {
        show(AutofillModel(serviceIdentifiers: [], configuration: true, onFill: { _ in }))
    }

    private func complete(_ filled: FilledLogin) {
        extensionContext.completeRequest(withSelectedCredential: ASPasswordCredential(user: filled.user, password: filled.password), completionHandler: nil)
    }

    private func cancel() {
        extensionContext.cancelRequest(withError: NSError(domain: ASExtensionErrorDomain, code: ASExtensionError.userCanceled.rawValue))
    }

    private func show(_ model: AutofillModel) {
        host?.willMove(toParent: nil)
        host?.view.removeFromSuperview()
        host?.removeFromParent()
        let controller = UIHostingController(rootView: AutofillRootView(model: model, onCancel: { [weak self] in self?.cancel() }))
        addChild(controller)
        controller.view.frame = view.bounds
        controller.view.autoresizingMask = [.flexibleWidth, .flexibleHeight]
        view.addSubview(controller.view)
        controller.didMove(toParent: self)
        host = controller
    }
}
