"""Keepiq client library for Python.

    from keepiq_sdk import Client, ClientConfig, Credentials

    key = open("/run/secrets/keepiq.pem").read()
    client = Client(ClientConfig("https://cloud.example.org", Credentials("billing", key)))
    secret = client.get_by_name("stripe-key")
    print(secret.key)
"""

from .client import Client, ClientConfig, Credentials, Lease, Secret
from .errors import (
    AmbiguousNameError,
    ApiError,
    Candidate,
    KeepiqError,
    KeyMismatchError,
    NotFoundError,
    NotModifiedError,
    UnauthorizedError,
)

__all__ = [
    "AmbiguousNameError",
    "ApiError",
    "Candidate",
    "Client",
    "ClientConfig",
    "Credentials",
    "KeepiqError",
    "KeyMismatchError",
    "Lease",
    "NotFoundError",
    "NotModifiedError",
    "Secret",
    "UnauthorizedError",
]
__version__ = "1.0.0"
