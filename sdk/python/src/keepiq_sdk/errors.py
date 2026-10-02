"""Typed errors of the Keepiq client."""

from __future__ import annotations

from dataclasses import dataclass
from typing import List, Optional


class KeepiqError(Exception):
    """Base class of every error the client raises."""


class NotFoundError(KeepiqError):
    """No such secret in the application's vault."""


class UnauthorizedError(KeepiqError):
    """The token exchange or a call with a fresh token was refused."""


class NotModifiedError(KeepiqError):
    """The secret is unchanged since the last read of the same address (HTTP 304)."""


class KeyMismatchError(KeepiqError):
    """The envelope is encrypted to a certificate other than the configured one."""


@dataclass
class Candidate:
    id: str
    name: str
    folder_path: str
    updated_at: Optional[str] = None


class AmbiguousNameError(KeepiqError):
    """Several secrets share the name (HTTP 409). Narrow with a folder or rename one."""

    def __init__(self, name: str, candidates: List[Candidate]) -> None:
        self.name = name
        self.candidates = candidates
        listed = ", ".join(f"{c.id} ({c.folder_path or '/'})" for c in candidates)
        super().__init__(f"{len(candidates)} secrets are named {name!r}: {listed}")


class ApiError(KeepiqError):
    """Any other non-success answer."""

    def __init__(self, status: int, message: str) -> None:
        self.status = status
        self.message = message
        super().__init__(f"server answered {status}: {message}")
