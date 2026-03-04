"""
Security module — file validation, content screening, and API protection.
"""
from security.file_guard import FileValidationResult, validate_file, strip_exif_metadata, scan_for_malware
from security.content_scanner import ContentScanner
from security.text_scanner import TextScanner
from security.prompt_guard import sanitize_for_llm, validate_vision_output, escape_for_ffmpeg
from security.pii_redactor import redact_pii, has_pii, scan_for_pii
from security.api_auth import require_api_key
from security.publish_gate import PublishGate, compute_file_hash
from security.audit_log import audit, AuditEvent, Severity, send_critical_alert

__all__ = [
    "FileValidationResult",
    "validate_file",
    "strip_exif_metadata",
    "scan_for_malware",
    "ContentScanner",
    "TextScanner",
    "sanitize_for_llm",
    "validate_vision_output",
    "escape_for_ffmpeg",
    "redact_pii",
    "has_pii",
    "scan_for_pii",
    "require_api_key",
    "PublishGate",
    "compute_file_hash",
    "audit",
    "AuditEvent",
    "Severity",
    "send_critical_alert",
]