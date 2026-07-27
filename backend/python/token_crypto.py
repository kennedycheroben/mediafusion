"""
token_crypto.py

Python decryption helper for AES-256-GCM encrypted OAuth tokens.
Mirrors the PHP TokenCrypto implementation in backend/token_crypto.php.

Format: enc:<version>:<base64(iv + tag + ciphertext)>
Key: 32-byte key from ENCRYPTION_KEY env var (64 hex chars)
"""

import os
import base64
from typing import Optional

_PREFIX = "enc:"
_VERSION = 1


def _get_key() -> bytes:
    """Load the 32-byte encryption key from ENCRYPTION_KEY env var."""
    raw = os.environ.get("ENCRYPTION_KEY", "")
    if not raw or raw == "CHANGE_ME":
        raise RuntimeError("ENCRYPTION_KEY is not configured.")
    key = bytes.fromhex(raw)
    if len(key) != 32:
        raise RuntimeError("ENCRYPTION_KEY must be a 64-character hex string (32 bytes).")
    return key


def is_encrypted(value: str) -> bool:
    """Check if a value has the encrypted prefix."""
    return value.startswith(_PREFIX)


def decrypt(value: str) -> str:
    """Decrypt an AES-256-GCM encrypted string."""
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    if not value.startswith(_PREFIX):
        raise ValueError("Value is not encrypted (missing prefix).")

    body = value[len(_PREFIX):]
    colon_idx = body.index(":")
    version = int(body[:colon_idx])
    if version != _VERSION:
        raise ValueError(f"Unsupported encryption version: {version}")

    packed = base64.b64decode(body[colon_idx + 1:])
    if len(packed) < 12 + 16:  # IV + tag minimum
        raise ValueError("Invalid encrypted payload.")

    iv = packed[:12]
    tag = packed[12:28]
    ciphertext = packed[28:]

    aesgcm = AESGCM(_get_key())
    # AESGCM.decrypt expects tag appended to ciphertext
    plaintext = aesgcm.decrypt(iv, ciphertext + tag, None)
    return plaintext.decode("utf-8")


def decrypt_if_needed(value: Optional[str]) -> Optional[str]:
    """Decrypt only if encrypted, return None/empty as-is."""
    if not value:
        return value
    if is_encrypted(value):
        return decrypt(value)
    return value


def encrypt(plaintext: str) -> str:
    """Encrypt a plaintext string. Returns prefixed ciphertext."""
    from cryptography.hazmat.primitives.ciphers.aead import AESGCM

    iv = os.urandom(12)
    aesgcm = AESGCM(_get_key())
    ct_with_tag = aesgcm.encrypt(iv, plaintext.encode("utf-8"), None)
    # AESGCM.encrypt returns ciphertext + tag (16 bytes) appended
    packed = iv + ct_with_tag
    return f"{_PREFIX}{_VERSION}:{base64.b64encode(packed).decode()}"


def encrypt_if_needed(value: str) -> str:
    """Encrypt only if not already encrypted."""
    if not value or is_encrypted(value):
        return value
    return encrypt(value)
