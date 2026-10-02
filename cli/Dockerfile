# ghcr.io/conductionnl/keepiq-cli: the static keepiq binary on a distroless base.
# Built by .github/workflows/cli-release.yml from the release binaries in
# cli/dist/ (keepiq-linux-amd64, keepiq-linux-arm64), so the image runs exactly
# the bytes listed in the release's SHA256SUMS.
FROM gcr.io/distroless/static-debian12:nonroot
ARG TARGETARCH
COPY --chmod=0755 dist/keepiq-linux-${TARGETARCH} /usr/local/bin/keepiq
ENTRYPOINT ["/usr/local/bin/keepiq"]
