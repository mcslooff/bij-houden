"""Real ephemeral TLS/CA checks; no root files or system trust are changed."""
import hashlib
import importlib.util
import os
from pathlib import Path
import pwd
import socket
import ssl
import stat
import tempfile
import threading


ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('pi_tls', ROOT / 'deployment/pi/prepare_tls.py')
tls = importlib.util.module_from_spec(spec)
spec.loader.exec_module(tls)


def must_refuse(action):
    try:
        action()
    except RuntimeError:
        return
    raise AssertionError('Expected safe refusal')


def run():
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as temporary:
        directory = Path(temporary) / 'pki'
        password = b'ephemeral-test-only-passphrase'
        tls.create_pki(directory, password)
        tls.validate_pki(directory)
        assert stat.S_IMODE(directory.stat().st_mode) == 0o700
        assert all(stat.S_IMODE((directory / name).stat().st_mode) == 0o600 for name in tls.FILES)
        correct = tls.openssl(['pkey', '-in', directory / 'ca-key.pem', '-passin', 'fd:0', '-noout'], password)
        assert correct.returncode == 0
        wrong = tls.openssl(['pkey', '-in', directory / 'ca-key.pem', '-passin', 'fd:0', '-noout'], b'wrong-test-passphrase', success=False)
        assert wrong.returncode != 0
        original = hashlib.sha256((directory / 'ca.pem').read_bytes()).hexdigest()
        must_refuse(lambda: tls.create_pki(directory, password))
        assert hashlib.sha256((directory / 'ca.pem').read_bytes()).hexdigest() == original

        server_context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
        server_context.minimum_version = ssl.TLSVersion.TLSv1_2
        server_context.load_cert_chain(directory / 'server.pem', directory / 'server-key.pem')
        listener = socket.socket()
        listener.bind(('127.0.0.1', 0))
        listener.listen(3)
        listener.settimeout(10)
        port = listener.getsockname()[1]
        errors = []

        def serve():
            try:
                for _ in range(3):
                    connection, _ = listener.accept()
                    try:
                        with server_context.wrap_socket(connection, server_side=True) as secure:
                            secure.recv(1024)
                            secure.sendall(b'HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK')
                    except ssl.SSLError:
                        connection.close()  # Expected rejected hostname/issuer handshakes.
            except Exception as error:
                errors.append(error)

        thread = threading.Thread(target=serve, daemon=True)
        thread.start()
        try:
            trusted = ssl.create_default_context(cafile=str(directory / 'ca.pem'))
            with socket.create_connection(('127.0.0.1', port), timeout=5) as connection:
                with trusted.wrap_socket(connection, server_hostname='pai01.home') as secure:
                    secure.sendall(b'GET / HTTP/1.1\r\nHost: pai01.home\r\n\r\n')
                    assert b'200 OK' in secure.recv(1024)
            for context, hostname in [(trusted, 'wrong.home'), (ssl.SSLContext(ssl.PROTOCOL_TLS_CLIENT), 'pai01.home')]:
                refused = False
                with socket.create_connection(('127.0.0.1', port), timeout=5) as connection:
                    try:
                        with context.wrap_socket(connection, server_hostname=hostname):
                            pass
                    except ssl.SSLCertVerificationError:
                        refused = True
                assert refused, 'Untrusted issuer/incorrect hostname must never be accepted'
        finally:
            listener.close()
            thread.join(timeout=12)
        assert not thread.is_alive() and not errors, errors

        destination = Path(temporary) / 'public-export'
        account = pwd.getpwuid(os.getuid())
        fingerprint = tls.export_public_ca(directory, destination, account)
        assert len(list(destination.iterdir())) == 2
        der = destination / 'bijhouden-pai01-home-root-ca.cer'
        assert fingerprint == hashlib.sha256(der.read_bytes()).hexdigest()
        assert tls.export_public_ca(directory, destination, account) == fingerprint
        der.write_bytes(b'Conflicting existing public certificate')
        must_refuse(lambda: tls.export_public_ca(directory, destination, account))
        assert der.read_bytes() == b'Conflicting existing public certificate'
        assert not any('key' in path.name for path in destination.iterdir())
        print('PASS: encrypted CA key, matching hostname certificate, real trusted TLS, wrong-host/untrusted-issuer rejection, private modes and public-only non-overwriting export')


if __name__ == '__main__':
    run()
