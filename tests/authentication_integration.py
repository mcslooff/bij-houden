import json
import os
import re
import secrets
import socket
import subprocess
import tempfile
import time
import urllib.error
import urllib.request

import integration as test


def php(code, values=None, environment=None):
    result = subprocess.run(['php', '-r', code], cwd=test.ROOT, input=json.dumps(values), text=True,
        capture_output=True, check=True, env=environment)
    return json.loads(result.stdout)


def login(browser, username, password=test.PASSWORD):
    body = browser.post('login.php', {'username': username, 'password': password})
    assert 'Welkom ' in body, 'Login failed'
    return body


def sid(browser):
    return next(cookie.value for cookie in browser.cookies if cookie.name == test.COOKIE_NAME)


def session(browser, updates=None):
    path = os.environ.get('BIJHOUDEN_TEST_SESSION_PATH')
    assert path, 'Set BIJHOUDEN_TEST_SESSION_PATH to the isolated test server session directory.'
    return php('''require "configuration.php";
        if (app_config()['environment'] !== 'test') exit(1);
        $input = json_decode(stream_get_contents(STDIN), true);
        if (!preg_match('/^[a-zA-Z0-9,-]+$/D', $input['id'])) exit(1);
        session_save_path($input['path']); session_name(app_config()['session_cookie_name']); session_id($input['id']);
        if (!is_file($input['path'] . '/sess_' . $input['id'])) exit(1);
        session_start();
        foreach ($input['updates'] ?? [] as $key => $value) $_SESSION[$key] = $value;
        print json_encode($_SESSION); session_write_close();''', {'path': path, 'id': sid(browser), 'updates': updates})


def version(user_id):
    return int(test.row('users', user_id)['auth_version'])


def run():
    settings = php('''require "configuration.php"; $c = app_config(); print json_encode([
        'idle' => $c['session_idle_seconds'], 'absolute' => $c['session_absolute_seconds'], 'cost' => $c['password_cost']]);''')
    group_a = test.insert('groups', {'name': test.PREFIX + '_A', 'owner_name': 'Public beekeeper', 'owner_label_token': secrets.token_hex(16)})
    group_b = test.insert('groups', {'name': test.PREFIX + '_B'})
    test.GROUP_IDS.extend([group_a, group_b])
    password_hash = php('require "db.php"; print json_encode(hash_user_password(json_decode(stream_get_contents(STDIN), true)));', test.PASSWORD)
    admin_name, member_name = test.PREFIX + '_admin', test.PREFIX + '_member'
    admin_id = test.insert('users', {'username': admin_name, 'role': 'admin', 'password_hash': password_hash})
    member_id = test.insert('users', {'username': member_name, 'role': 'user', 'group_id': group_a, 'password_hash': password_hash})
    test.USER_IDS.extend([admin_id, member_id])
    admin, member, second = test.Browser(), test.Browser(), test.Browser()
    login(admin, admin_name)
    prelogin = member.request('login.php')
    old_sid = sid(member)
    old_csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', prelogin).group(1)
    login(member, member_name)
    assert sid(member) != old_sid
    assert session(member)['csrf'] != old_csrf
    login(second, member_name)
    cookie = next(cookie for cookie in member.cookies if cookie.name == test.COOKIE_NAME)
    assert cookie.secure == test.BASE.startswith('https://') and cookie.has_nonstandard_attr('HttpOnly')
    assert cookie.get_nonstandard_attr('SameSite') == 'Lax'
    attack = urllib.request.Request(test.BASE + '/login.php', headers={'Cookie': test.COOKIE_NAME + '=' + 'a' * 32})
    response = urllib.request.urlopen(attack)
    assert test.COOKIE_NAME + '=' + 'a' * 32 not in response.headers.get('Set-Cookie', '')
    print('PASS: strict sessions, cookie flags, session-ID and CSRF rotation on login')

    started = session(member)['auth_started_at']
    before = version(member_id)
    identity = {'action': 'identity', 'first_name': 'Member', 'last_name': 'Beekeeper', 'email': test.PREFIX + '@example.com'}
    assert 'Profile updated' in member.post('profile.php', identity)
    assert version(member_id) == before + 1
    assert '<h1>Login</h1>' in second.request('index.php')
    assert session(member)['auth_started_at'] == started
    login(second, member_name)
    before = version(member_id)
    assert 'Profile updated' in member.post('profile.php', dict(identity, first_name='Updated'))
    assert version(member_id) == before and 'Welkom ' in second.request('index.php')
    new_password = secrets.token_urlsafe(22)
    assert 'Current password is incorrect' in member.post('profile.php', {'action': 'password',
        'current_password': 'wrong', 'new_password': new_password, 'confirm_password': new_password})
    before = version(member_id)
    assert 'Password changed' in member.post('profile.php', {'action': 'password',
        'current_password': test.PASSWORD, 'new_password': new_password, 'confirm_password': new_password})
    assert version(member_id) == before + 1 and session(member)['auth_started_at'] == started
    assert 'Welkom ' in member.request('index.php')
    assert '<h1>Login</h1>' in second.request('index.php')
    reset_password = secrets.token_urlsafe(22)
    edit = {'action': 'update', 'id': member_id, 'username': member_name, 'role': 'user', 'group_id': group_a,
        'password': reset_password, 'confirm': reset_password}
    before = version(member_id)
    assert 'User updated' in admin.post('users.php', edit)
    assert version(member_id) == before + 1
    assert '<h1>Login</h1>' in member.request('index.php')
    login(member, member_name, reset_password)
    before = version(member_id)
    assert 'User updated' in admin.post('users.php', dict(edit, password='', confirm=''))
    assert version(member_id) == before and 'Welkom ' in member.request('index.php')
    assert 'User updated' in admin.post('users.php', dict(edit, password='', confirm='', role='admin'))
    assert '<h1>Login</h1>' in member.request('index.php')
    login(member, member_name, reset_password)
    assert 'User updated' in admin.post('users.php', dict(edit, password='', confirm='', group_id=group_b))
    assert '<h1>Login</h1>' in member.request('index.php')
    print('PASS: password/reset, email, role and group changes revoke other sessions; no-op/name edits preserve them')

    login(member, member_name, reset_password)
    session(member, {'auth_last_activity': int(time.time()) - settings['idle'] - 1, 'auth_started_at': int(time.time()) - settings['idle'] - 10})
    assert 'session has expired' in member.request('index.php')
    login(member, member_name, reset_password)
    session(member, {'auth_started_at': int(time.time()) - settings['absolute'] - 1, 'auth_last_activity': int(time.time())})
    assert 'session has expired' in member.request('index.php')
    login(member, member_name, reset_password)
    saved = sid(member)
    assert 'logged out' in member.post('logout.php', {})
    replay = urllib.request.Request(test.BASE + '/index.php', headers={'Cookie': test.COOKIE_NAME + '=' + saved})
    assert '<h1>Login</h1>' in urllib.request.urlopen(replay).read().decode()
    label = 'owner_label.php?token=' + test.row('groups', group_a)['owner_label_token']
    assert 'Public beekeeper' in member.request(label) and 'id="print-owner-label"' not in member.request(label)
    print('PASS: idle/absolute expiry, logout replay rejection and anonymous owner labels')

    policy = php('''require "db.php"; $input = json_decode(stream_get_contents(STDIN), true);
        print json_encode(['short' => new_password_error('short'), 'nul' => new_password_error("abcdefghijkl\\0"),
            'long' => new_password_error(str_repeat('a', 73)), 'unicode_long' => new_password_error(str_repeat('🐝', 19)),
            'unicode_ok' => new_password_error(str_repeat('🐝', 12)), 'array' => new_password_error([]),
            'dummy_cost' => password_get_info(dummy_password_hash())['options']['cost'],
            'unknown' => verify_user_password('any password', null)]);''')
    assert all(policy[key] is not None for key in ['short', 'nul', 'long', 'unicode_long', 'array'])
    assert policy['unicode_ok'] is None and policy['dummy_cost'] == settings['cost'] and policy['unknown'] is False
    test.db('DELETE FROM auth_login_limits')
    invalid = test.Browser()
    for values in [{'username': "' OR 1=1 --", 'password': 'bad'}, {'username[]': 'bad', 'password': 'bad'},
                   {'username': member_name, 'password[]': 'bad'}, {'username': member_name, 'password': 'bad\0value'},
                   {'username': member_name, 'password': 'x' * 73}]:
        assert 'Invalid username or password' in invalid.post('login.php', values)
    long_name = test.PREFIX + '_long'
    long_hash = php('require "db.php"; print json_encode(hash_user_password(str_repeat("L", 72)));')
    long_id = test.insert('users', {'username': long_name, 'password_hash': long_hash, 'group_id': group_a})
    test.USER_IDS.append(long_id)
    assert 'Invalid username or password' in invalid.post('login.php', {'username': long_name, 'password': 'L' * 73})
    login(invalid, long_name, 'L' * 72)
    legacy_name = test.PREFIX + '_legacy'
    legacy_cost = settings['cost'] + 1 if settings['cost'] < 14 else settings['cost'] - 1
    legacy_hash = php('print json_encode(password_hash("legacy123", PASSWORD_BCRYPT, ["cost" => json_decode(stream_get_contents(STDIN), true)]));', legacy_cost)
    legacy_id = test.insert('users', {'username': legacy_name, 'password_hash': legacy_hash, 'group_id': group_a})
    test.USER_IDS.append(legacy_id)
    legacy = test.Browser()
    login(legacy, legacy_name, 'legacy123')
    assert version(legacy_id) == 1 and test.row('users', legacy_id)['password_hash'].startswith(f'$2y${settings["cost"]:02d}$')
    stale_refresh = php('''require "db.php"; $id = json_decode(stream_get_contents(STDIN), true);
        $stmt = db()->prepare('SELECT id, username, auth_version FROM users WHERE id = ?'); $stmt->execute([$id]);
        $user = $stmt->fetch(); auth_establish_session($user);
        db()->prepare('UPDATE users SET auth_version = auth_version + 1 WHERE id = ?')->execute([$id]);
        auth_refresh_own_session((int)$id, (int)$user['auth_version']);
        print json_encode(current_user());''', long_id)
    assert stale_refresh is None
    for bad_password in ['short', 'x' * 73, 'long-enough-password\0']:
        rejected = admin.post('users.php', {'action': 'add', 'username': test.PREFIX + '_invalid',
            'role': 'user', 'group_id': group_a, 'password': bad_password, 'confirm': bad_password})
        assert '12 characters' in rejected
    print('PASS: password policy, malformed login input, bcrypt truncation prevention and legacy-password rehashing')

    test.db('DELETE FROM auth_login_limits')
    limited = test.Browser()
    for index in range(5):
        assert 'Invalid username or password' in limited.post('login.php', {'username': member_name.upper() if index % 2 else member_name, 'password': 'wrong'})
    assert 'Too many' in limited.post('login.php', {'username': member_name, 'password': reset_password}, expected=429)
    login(test.Browser(), admin_name)
    limits = php('''require "db.php";
        $out = [];
        for ($i = 0; $i < 6; $i++) $out[] = reserve_login_attempt('target', '198.51.100.20')['allowed'];
        $out[] = reserve_login_attempt('target', '198.51.100.21')['allowed'];
        print json_encode($out);''')
    assert limits == [True] * 5 + [False, True]
    ip_results = php('''require "db.php"; $out = [];
        for ($i = 0; $i < 55; $i++) $out[] = reserve_login_attempt('varied:' . $i, '198.51.100.22')['allowed'];
        print json_encode($out);''')
    assert ip_results == [True] * 50 + [False] * 5
    test.db('UPDATE auth_login_limits SET expires_at = 0')
    assert 'Invalid username or password' in limited.post('login.php', {'username': member_name, 'password': 'wrong'})
    code = 'require "db.php"; print json_encode(reserve_login_attempt("parallel", "198.51.100.23")["allowed"]);'
    workers = [subprocess.Popen(['php', '-r', code], cwd=test.ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(9)]
    results = [process.communicate(timeout=20) for process in workers]
    assert all(process.returncode == 0 for process in workers), results
    assert sum(json.loads(stdout) for stdout, stderr in results) == 5
    print('PASS: canonical account/IP limits, global IP cap, expiry and atomic concurrent rate reservations')

    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        environment = dict(os.environ, BIJHOUDEN_ENV='production', BIJHOUDEN_DB_PASSWORD='validation-only', BIJHOUDEN_PASSWORD_COST='12')
        for https in [False, True]:
            router = os.path.join(directory, 'router.php')
            with open(router, 'w') as file:
                file.write('<?php ' + ("$_SERVER['HTTPS'] = 'on'; " if https else '') + 'require ' + json.dumps(str(test.ROOT / 'web.php')) + ';')
            with socket.socket() as reservation:
                reservation.bind(('127.0.0.1', 0))
                port = reservation.getsockname()[1]
            process = subprocess.Popen(['php', '-d', 'session.save_path=' + directory, '-S', '127.0.0.1:' + str(port), '-t', 'public', router],
                cwd=test.ROOT, env=environment, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                for _ in range(50):
                    try:
                        response = urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:' + str(port) + '/login.php', headers={'X-Forwarded-Proto': 'https'}), timeout=2)
                        break
                    except urllib.error.HTTPError as error:
                        response = error
                        break
                    except urllib.error.URLError:
                        time.sleep(0.05)
                else:
                    raise AssertionError('HTTPS configuration test server did not start')
                if https:
                    assert response.status == 200
                    cookie = response.headers['Set-Cookie'].lower()
                    assert 'secure' in cookie and 'httponly' in cookie and 'samesite=lax' in cookie
                else:
                    assert response.status == 403 and response.read().decode() == 'HTTPS is required.'
            finally:
                process.terminate()
                process.wait(timeout=5)
    print('PASS: production requires HTTPS and Secure cookies; untrusted forwarded headers do not bypass it')


if __name__ == '__main__':
    try:
        run()
    finally:
        test.cleanup()
