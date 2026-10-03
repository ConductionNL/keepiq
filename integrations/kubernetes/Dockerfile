# ghcr.io/conductionnl/keepiq-operator. Build from the repository root, because
# the operator builds against sdk/go in this repository:
#   docker build -f integrations/kubernetes/Dockerfile .
FROM golang:1.22 AS build
ARG TARGETOS
ARG TARGETARCH
ARG VERSION=dev
WORKDIR /src
COPY sdk/go ./sdk/go
COPY integrations/kubernetes ./integrations/kubernetes
WORKDIR /src/integrations/kubernetes
RUN CGO_ENABLED=0 GOOS=${TARGETOS} GOARCH=${TARGETARCH} go build -trimpath -ldflags "-s -w -X main.version=${VERSION}" -o /out/keepiq-operator ./cmd

FROM gcr.io/distroless/static-debian12:nonroot
COPY --from=build /out/keepiq-operator /keepiq-operator
USER 65532:65532
ENTRYPOINT ["/keepiq-operator"]
