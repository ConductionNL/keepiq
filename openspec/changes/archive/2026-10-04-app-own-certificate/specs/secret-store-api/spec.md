## ADDED Requirements

### Requirement: An application reads its own certificate

The system MUST serve `GET /api/v1/app/certificate` to a Bearer-authenticated application. The response MUST contain the application id, the id of its active encryption suite, that suite's certificate in PEM and its `certificateFingerprint`, computed exactly as in the response envelope (`sha256:` over the certificate DER). The response MUST NOT contain private key material or another application's certificate. The discovery document MUST name the path under `certificate`.

#### Scenario: A client verifies envelopes without configuring the certificate

- **GIVEN** an approved application with an active encryption suite and a valid access token
- **WHEN** it calls `GET /api/v1/app/certificate`
- **THEN** the response MUST carry its certificate and `certificateFingerprint`
- **AND** that fingerprint MUST equal the `certificateFingerprint` of every envelope encrypted to that suite

#### Scenario: No token, no certificate

- **GIVEN** a request without a Bearer token
- **WHEN** it calls `GET /api/v1/app/certificate`
- **THEN** the response MUST be 401
