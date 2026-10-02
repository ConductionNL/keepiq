"""Keepiq client library for Python.

    from keepiq_sdk import Client

    client = Client("https://cloud.example.org", "billing", open("/run/secrets/keepiq.pem").read())
    secret = client.get_by_name("stripe-key")
    print(secret.key)
"""

from .client import Client, Lease, Secret
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
    "KeepiqError",
    "KeyMismatchError",
    "Lease",
    "NotFoundError",
    "NotModifiedError",
    "Secret",
    "UnauthorizedError",
]
__version__ = "0.1.0"
