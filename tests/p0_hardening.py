import os
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request
from pathlib import Path

import integration as test


def assert_traversal_denied(response, body):
    nginx_body = ('<html>\r\n<head><title>400 Bad Request</title></head>\r\n<body>\r\n'
                  '<center><h1>400 Bad Request</h1></center>\r\n<hr><center>nginx</center>\r\n'
                  '</body>\r\n</html>')
    assert ((response.status == 404 and body == 'Not found.')
            or (response.status == 400 and response.headers.get('Server', '').lower() == 'nginx'
                and body.strip() == nginx_body)), 'Unexpected traversal response or disclosure'


def run():
    anonymous = test.Browser()
    blocked = [
        'schema.sql', 'PLAN.md', 'README.md', '.htaccess', '.git/config', '.env',
        'db.php', 'configuration.php', 'web.php', 'group_helpers.php', 'task_helpers.php', 'icons.php',
        'config/database.example.json', 'migrations/001_complete_inspections_and_archiving.sql',
        'migrations/002_tasks.sql', 'migrations/003_group_owner_labels.sql',
        'tests/integration.py', 'bin/create-admin.php', 'bin/rotate-db-password.php',
        'public/router.php', 'public/index.php', 'index.php/schema.sql',
        '%252e%252e/schema.sql', 'uploads/avatars/shell.php',
        'vendor/qrcode-generator/LICENSE.txt',
    ]
    for path in blocked:
        assert anonymous.request(path, expected=404) == 'Not found.'
    # nginx rejects above-root traversal during URI parsing, before PHP.
    # Only this exact malformed path may use its strict 400 response; ordinary
    # private routes and double-encoded traversal must still return our 404.
    try:
        response = anonymous.client.open(test.BASE + '/%2e%2e/schema.sql')
    except urllib.error.HTTPError as error:
        response = error
    body = response.read().decode()
    assert_traversal_denied(response, body)
    for path, content_type in [('style.css', 'text/css'), ('sidebar.js', 'text/javascript'),
                               ('vendor/qrcode-generator/qrcode.min.js', 'text/javascript')]:
        response = anonymous.client.open(test.BASE + '/' + path)
        assert response.headers['Content-Type'].startswith(content_type)
        assert response.headers['X-Content-Type-Options'] == 'nosniff'
    before = test.db('SELECT COUNT(*) AS n FROM users')['rows'][0]['n']
    anonymous.request('register.php', expected=404)
    anonymous.request('register.php', {'username': 'unauthorized_admin', 'password': 'unused'}, expected=404)
    assert test.db('SELECT COUNT(*) AS n FROM users')['rows'][0]['n'] == before
    assert 'register.php' not in anonymous.request('login.php')
    print('PASS: private-file allowlist, static MIME types, traversal blocking, and disabled web bootstrap')

    assert test.db("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")['rows'][0]['n'] == 0, 'Use an empty dedicated test database for bootstrap tests.'
    bad = subprocess.run(['php', 'bin/create-admin.php', '--username=' + test.PREFIX, '--password-stdin'],
        input='short\n', text=True, capture_output=True, cwd=test.ROOT)
    assert bad.returncode == 1 and '12 characters' in bad.stderr
    names = [test.PREFIX + '_first', test.PREFIX + '_second']
    processes = [subprocess.Popen(['php', 'bin/create-admin.php', '--username=' + name, '--password-stdin'],
        stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True, cwd=test.ROOT) for name in names]
    for process in processes:
        process.stdin.write(test.PASSWORD + '\n')
        process.stdin.close()
        process.stdin = None
    outcomes = [process.communicate(timeout=20) for process in processes]
    admins = test.db("SELECT id, username FROM users WHERE role = 'admin'")['rows']
    for admin in admins:
        test.USER_IDS.append(int(admin['id']))
    assert len(admins) == 1 and sorted(process.returncode for process in processes) == [0, 1]
    assert test.PASSWORD not in ''.join(stdout + stderr for stdout, stderr in outcomes)
    browser = test.Browser()
    browser.login(admins[0]['username'])
    retry = subprocess.run(['php', 'bin/create-admin.php', '--username=' + test.PREFIX + '_third', '--password-stdin'],
        input=test.PASSWORD + '\n', text=True, capture_output=True, cwd=test.ROOT)
    assert retry.returncode == 1 and 'already exists' in retry.stderr
    print('PASS: CLI-only administrator bootstrap, validation and concurrent first-admin protection')

    wrong_target = dict(os.environ, BIJHOUDEN_TEST_URL='http://localhost:8080')
    result = subprocess.run(['python3', '-B', '-c', 'import integration'], cwd=test.ROOT / 'tests',
        env=wrong_target, text=True, capture_output=True)
    assert result.returncode != 0 and 'must be a test server' in result.stderr
    wrong_environment = dict(os.environ, BIJHOUDEN_ENV='production', BIJHOUDEN_DB_PASSWORD='validation-only', BIJHOUDEN_PASSWORD_COST='12')
    result = subprocess.run(['python3', '-B', '-c', 'import integration'], cwd=test.ROOT / 'tests',
        env=wrong_environment, text=True, capture_output=True)
    assert result.returncode != 0 and 'dedicated database' in result.stderr
    print('PASS: fixture suites reject the normal application server and production configuration')

    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        with socket.socket() as reservation:
            reservation.bind(('127.0.0.1', 0))
            port = reservation.getsockname()[1]
        environment = dict(os.environ, BIJHOUDEN_CONFIG=str(Path(directory) / 'missing.json'))
        process = subprocess.Popen(['php', '-S', '127.0.0.1:' + str(port), '-t', 'public', 'public/router.php'],
            cwd=test.ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            for attempt in range(50):
                try:
                    response = urllib.request.urlopen('http://127.0.0.1:' + str(port) + '/login.php', timeout=2)
                except urllib.error.HTTPError as error:
                    response = error
                    break
                except urllib.error.URLError:
                    time.sleep(0.05)
            else:
                raise AssertionError('Missing-configuration test server did not start')
            assert response.status == 503
            body = response.read().decode()
            assert body == 'The application is temporarily unavailable. Please try again later.'
            assert 'password' not in body and 'Trace' not in body and directory not in body
        finally:
            process.terminate()
            process.wait(timeout=5)
    print('PASS: missing private configuration fails closed without connection details or stack traces')


if __name__ == '__main__':
    try:
        run()
    finally:
        test.cleanup()
