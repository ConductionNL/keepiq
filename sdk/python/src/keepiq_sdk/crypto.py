"""The Keepiq field recipe, byte for byte the same as the server and the browser.

``rsa-oaep-sha256-chunked-v1``: the UTF-8 value is split into chunks of the key
size minus 66 bytes (446 for RSA-4096), each chunk is RSA-OAEP-SHA256 encrypted,
and the field is base64 of ``[4-byte big-endian chunk count][one key-size block
per chunk]``. See lib/Service/EncryptService.php and sdk/go/crypto.
"""

from __future__ import annotations

import base64
import hashlib
import struct

from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM
from cryptography.hazmat.primitives.kdf.pbkdf2 import PBKDF2HMAC

SCHEME = "rsa-oaep-sha256-chunked-v1"
_OAEP_OVERHEAD = 2 * 32 + 2


def _oaep() -> padding.OAEP:
    return padding.OAEP(mgf=padding.MGF1(algorithm=hashes.SHA256()), algorithm=hashes.SHA256(), label=None)


def load_private_key(pem: str | bytes) -> rsa.RSAPrivateKey:
    """Parse an RSA private key PEM (PKCS#8 or PKCS#1)."""
    data = pem.encode() if isinstance(pem, str) else pem
    key = serialization.load_pem_private_key(data, password=None)
    if not isinstance(key, rsa.RSAPrivateKey):
        raise ValueError("the private key is not an RSA key")
    return key


def encrypt_field(plaintext: str, public_key: rsa.RSAPublicKey) -> str:
    """Encrypt one value in the chunked format."""
    size = public_key.key_size // 8
    chunk = size - _OAEP_OVERHEAD
    data = plaintext.encode("utf-8")
    chunks = [data[i : i + chunk] for i in range(0, len(data), chunk)] or [b""]
    out = bytearray(struct.pack(">I", len(chunks)))
    for part in chunks:
        out += public_key.encrypt(part, _oaep())
    return base64.b64encode(bytes(out)).decode("ascii")


def decrypt_field(ciphertext: str, private_key: rsa.RSAPrivateKey) -> str:
    """Decrypt one value written in the chunked format."""
    raw = base64.b64decode(ciphertext, validate=True)
    if len(raw) < 4:
        raise ValueError("field ciphertext too short")
    (count,) = struct.unpack(">I", raw[:4])
    size = private_key.key_size // 8
    if count * size != len(raw) - 4:
        raise ValueError(f"field length {len(raw)} inconsistent with chunk count {count}")
    out = b"".join(private_key.decrypt(raw[4 + i * size : 4 + (i + 1) * size], _oaep()) for i in range(count))
    return out.decode("utf-8")


def certificate_fingerprint(certificate_pem: str) -> str:
    """``sha256:<hex>`` of the certificate's DER bytes, as the envelope carries it."""
    cert = x509.load_pem_x509_certificate(certificate_pem.encode())
    return "sha256:" + hashlib.sha256(cert.public_bytes(serialization.Encoding.DER)).hexdigest()


def certificate_matches_key(certificate_pem: str, private_key: rsa.RSAPrivateKey) -> bool:
    cert = x509.load_pem_x509_certificate(certificate_pem.encode())
    pub = cert.public_key()
    return isinstance(pub, rsa.RSAPublicKey) and pub.public_numbers() == private_key.public_key().public_numbers()


def sign_rs256(signing_input: str, private_key: rsa.RSAPrivateKey) -> str:
    sig = private_key.sign(signing_input.encode("ascii"), padding.PKCS1v15(), hashes.SHA256())
    return base64.urlsafe_b64encode(sig).rstrip(b"=").decode("ascii")


def unwrap_private_key(blob_b64: str, master_password: str) -> str:
    """Open the browser's private-key blob (human unlock).

    ``[4-byte version][16-byte salt][12-byte IV][AES-256-GCM ciphertext]`` with
    the key from PBKDF2-HMAC-SHA256(master password, salt, 600000). The
    libraries use application keys, not this; it is here so the library proves
    it reads the browser's own vector in sdk/testdata.
    """
    raw = base64.b64decode(blob_b64)
    (version,) = struct.unpack(">I", raw[:4])
    if version != 1:
        raise ValueError(f"unsupported envelope version {version}")
    salt, iv, ct = raw[4:20], raw[20:32], raw[32:]
    key = PBKDF2HMAC(algorithm=hashes.SHA256(), length=32, salt=salt, iterations=600000).derive(master_password.encode())
    return AESGCM(key).decrypt(iv, ct, None).decode("utf-8")
