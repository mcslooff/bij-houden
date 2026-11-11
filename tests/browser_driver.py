"""Minimal local Chromium DevTools client; no browser automation package required."""

import base64
import json
import os
import secrets
import signal
import socket
import struct
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
from pathlib import Path


class Chromium:
    def __enter__(self):
        self.directory = tempfile.TemporaryDirectory(dir=os.environ.get('BIJHOUDEN_TEST_TEMP_DIRECTORY', '/tmp/opencode'))
        self.profile = Path(self.directory.name)
        resolver = []
        connect_address = os.environ.get('BIJHOUDEN_TEST_CONNECT_ADDRESS')
        if connect_address:
            if connect_address != '127.0.0.1' or os.environ.get('BIJHOUDEN_ENV') != 'test':
                raise RuntimeError('Browser staging resolver override requires explicit test/loopback scope.')
            resolver = ['--host-resolver-rules=MAP pai01.home 127.0.0.1']
        self.process = subprocess.Popen(['chromium', '--headless', '--no-sandbox', '--disable-gpu',
            '--password-store=basic', '--use-mock-keychain', '--no-first-run', '--disable-dev-shm-usage',
            '--disable-background-networking', '--no-proxy-server', '--remote-debugging-address=127.0.0.1',
            '--remote-debugging-port=0', '--user-data-dir=' + str(self.profile), *resolver, 'about:blank'],
            stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
        for _ in range(200):
            port_file = self.profile / 'DevToolsActivePort'
            if port_file.exists():
                self.port = int(port_file.read_text().splitlines()[0])
                break
            if self.process.poll() is not None:
                raise RuntimeError('Chromium did not start')
            time.sleep(0.05)
        else:
            self.process.terminate()
            raise RuntimeError('Chromium debugging endpoint did not start')
        target = json.load(urllib.request.urlopen(urllib.request.Request(
            f'http://127.0.0.1:{self.port}/json/new?about:blank', method='PUT')))
        url = urllib.parse.urlparse(target['webSocketDebuggerUrl'])
        self.socket = socket.create_connection((url.hostname, url.port), timeout=20)
        key = base64.b64encode(secrets.token_bytes(16)).decode()
        self.socket.sendall((f'GET {url.path} HTTP/1.1\r\nHost: {url.netloc}\r\nUpgrade: websocket\r\n'
            f'Connection: Upgrade\r\nSec-WebSocket-Key: {key}\r\nSec-WebSocket-Version: 13\r\n\r\n').encode())
        self.buffer = b''
        while b'\r\n\r\n' not in self.buffer:
            self.buffer += self.socket.recv(4096)
        headers, self.buffer = self.buffer.split(b'\r\n\r\n', 1)
        assert headers.startswith(b'HTTP/1.1 101'), headers
        self.sequence = 0
        self.events = []
        for method in ['Page.enable', 'Runtime.enable', 'Network.enable']:
            self.call(method)
        self.call('Page.addScriptToEvaluateOnNewDocument', {'source': '''window.cspViolations = [];
            document.addEventListener('securitypolicyviolation', event => window.cspViolations.push(event.effectiveDirective));'''})
        return self

    def __exit__(self, *args):
        if hasattr(self, 'socket'):
            self.socket.close()
        if self.process.poll() is None:
            os.killpg(self.process.pid, signal.SIGTERM)
        try:
            self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            os.killpg(self.process.pid, signal.SIGKILL); self.process.wait(timeout=5)
        self.directory.cleanup()

    def read(self, size):
        while len(self.buffer) < size:
            data = self.socket.recv(max(4096, size - len(self.buffer)))
            if not data:
                raise RuntimeError('Chromium websocket closed')
            self.buffer += data
        data, self.buffer = self.buffer[:size], self.buffer[size:]
        return data

    def frame(self, payload, opcode=1):
        mask = secrets.token_bytes(4)
        size = len(payload)
        header = bytes([0x80 | opcode])
        if size < 126:
            header += bytes([0x80 | size])
        elif size < 65536:
            header += bytes([0x80 | 126]) + struct.pack('!H', size)
        else:
            header += bytes([0x80 | 127]) + struct.pack('!Q', size)
        self.socket.sendall(header + mask + bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload)))

    def message(self):
        body = b''
        while True:
            first, second = self.read(2)
            length = second & 127
            if length == 126: length = struct.unpack('!H', self.read(2))[0]
            elif length == 127: length = struct.unpack('!Q', self.read(8))[0]
            if length > 33554432: raise RuntimeError('Unexpectedly large Chromium websocket frame')
            mask = self.read(4) if second & 128 else None
            payload = self.read(length)
            if mask:
                payload = bytes(byte ^ mask[index % 4] for index, byte in enumerate(payload))
            opcode = first & 15
            if opcode == 8: raise RuntimeError('Chromium websocket closed')
            if opcode == 9:
                self.frame(payload, 10); continue
            if opcode == 10: continue
            body += payload
            if first & 128:
                return json.loads(body)

    def call(self, method, params=None):
        self.sequence += 1
        id = self.sequence
        self.frame(json.dumps({'id': id, 'method': method, 'params': params or {}}).encode())
        while True:
            message = self.message()
            if message.get('id') == id:
                if 'error' in message:
                    raise RuntimeError(str(message['error']))
                return message.get('result', {})
            self.events.append(message)

    def evaluate(self, expression, await_promise=False):
        result = self.call('Runtime.evaluate', {'expression': expression, 'returnByValue': True, 'awaitPromise': await_promise})
        if 'exceptionDetails' in result:
            raise RuntimeError(str(result['exceptionDetails']))
        return result.get('result', {}).get('value')

    def wait(self, expression, seconds=10):
        deadline = time.monotonic() + seconds
        while time.monotonic() < deadline:
            if self.evaluate(expression): return
            time.sleep(0.05)
        raise AssertionError('Browser condition timed out: ' + expression)

    def navigate(self, url):
        self.call('Page.navigate', {'url': url})
        self.wait('document.readyState === "complete" && location.href === ' + json.dumps(url))
