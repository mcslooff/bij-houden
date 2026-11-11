"""Readiness retries must never turn certificate/security failures into success."""
from pathlib import Path
import ssl
import sys
import urllib.error
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/pi'))
import prepare_stage as stage


def run():
    refusal = urllib.error.URLError(ConnectionRefusedError(111, 'not listening yet'))
    gateway = urllib.error.HTTPError(stage.URL, 502, 'upstream not ready', {}, None)
    with patch.object(stage, 'smoke', side_effect=[refusal, gateway, None]) as probe, patch.object(stage.time, 'sleep') as pause:
        stage.wait_ready({})
        assert probe.call_count == 3 and pause.call_count == 2
    for failure in [urllib.error.URLError(ssl.SSLCertVerificationError('untrusted CA')),
                    urllib.error.HTTPError(stage.URL, 403, 'forbidden', {}, None),
                    AssertionError('wrong fingerprint/cookie/Host behavior')]:
        with patch.object(stage, 'smoke', side_effect=failure) as probe, patch.object(stage.time, 'sleep') as pause:
            refused = False
            try:
                stage.wait_ready({})
            except type(failure):
                refused = True
            assert refused and probe.call_count == 1 and pause.call_count == 0
    with patch.object(stage, 'smoke', side_effect=refusal), patch.object(stage.time, 'monotonic', side_effect=[0, 1]):
        refused = False
        try:
            stage.wait_ready({}, timeout=0)
        except RuntimeError:
            refused = True
        assert refused
    print('PASS: bounded HTTPS/FastCGI readiness retries; TLS/host/security failures are never bypassed (no services changed)')


if __name__ == '__main__':
    run()
