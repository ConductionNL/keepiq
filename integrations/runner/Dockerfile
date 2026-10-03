# ghcr.io/conductionnl/keepiq-runner. Build from the repository root, because
# the runner builds against sdk/go in this repository:
#   docker build -f integrations/runner/Dockerfile .
FROM golang:1.22 AS build
ARG TARGETOS
ARG TARGETARCH
ARG VERSION=dev
WORKDIR /src
COPY sdk/go ./sdk/go
COPY integrations/runner ./integrations/runner
WORKDIR /src/integrations/runner
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath -ldflags "-s -w -X main.version=${VERSION}" -o /out/keepiq-runner ./cmd/keepiq-runner

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/keepiq-runner /usr/local/bin/keepiq-runner
USER 65532:65532
ENTRYPOINT ["/usr/local/bin/keepiq-runner"]
CMD ["run", "--config", "/etc/keepiq-runner/runner.yaml"]
