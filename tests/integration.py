import http.cookiejar
import json
import os
import re
import secrets
import socket
import subprocess
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
BASE = os.environ.get('BIJHOUDEN_TEST_URL', '').rstrip('/')
CONFIG_INFO = subprocess.check_output(['php', '-r', '''require "configuration.php";
$config = app_config(); print json_encode(['environment' => $config['environment'],
    'database' => $config['db_name'], 'fingerprint' => app_database_fingerprint(),
    'image_root' => $config['image_storage_path'], 'storage_fingerprint' => app_storage_fingerprint(),
    'diagnostic_log' => $config['diagnostic_log_path'], 'session_cookie_name' => $config['session_cookie_name']]);'''], cwd=ROOT, text=True)
CONFIG_INFO = json.loads(CONFIG_INFO)
COOKIE_NAME = CONFIG_INFO['session_cookie_name']
if CONFIG_INFO['environment'] != 'test' or not re.search(r'(^|_)test(_|$)', CONFIG_INFO['database']):
    raise RuntimeError('Integration tests require BIJHOUDEN_ENV=test and a dedicated database with "test" in its name.')
if not BASE or urllib.parse.urlparse(BASE).scheme not in ['http', 'https']:
    raise RuntimeError('Set BIJHOUDEN_TEST_URL to the dedicated test server.')
connect_address = os.environ.get('BIJHOUDEN_TEST_CONNECT_ADDRESS')
if connect_address:
    if connect_address != '127.0.0.1':
        raise RuntimeError('The staging resolver override is restricted to pai01.home on loopback.')
    if urllib.parse.urlparse(BASE).hostname == 'pai01.home':
        original_resolver = socket.getaddrinfo

        def stage_resolver(host, port, *args, **kwargs):
            return original_resolver(connect_address if host == 'pai01.home' else host, port, *args, **kwargs)

        # Preserve URL/Host/SNI and certificate validation while keeping one
        # source IP for rate-limit assertions despite dual-address LAN DNS.
        # Unrelated/negative-test targets keep normal resolution and guards.
        socket.getaddrinfo = stage_resolver
TEMP_ROOT = Path(os.environ.get('BIJHOUDEN_TEST_TEMP_DIRECTORY', '/tmp/opencode'))
if not TEMP_ROOT.is_absolute() or not TEMP_ROOT.is_dir() or not os.access(TEMP_ROOT, os.W_OK):
    raise RuntimeError('Select an existing writable absolute test temporary directory.')
with urllib.request.urlopen(urllib.request.Request(BASE + '/login.php', method='HEAD'), timeout=10) as response:
    if (response.headers.get('X-Bijhouden-Environment') != 'test'
            or response.headers.get('X-Bijhouden-Test-Database') != CONFIG_INFO['fingerprint']
            or response.headers.get('X-Bijhouden-Test-Storage') != CONFIG_INFO['storage_fingerprint']):
        raise RuntimeError('The HTTP target must be a test server using the same dedicated test database as the fixtures.')
PREFIX = 'check_' + secrets.token_hex(6)
PASSWORD = secrets.token_urlsafe(20)
USER_IDS = []
GROUP_IDS = []
PHP_DB = '''require "db.php";
$request = json_decode(stream_get_contents(STDIN), true);
$stmt = db()->prepare($request['sql']);
$stmt->execute($request['params']);
print json_encode(['rows' => $stmt->columnCount() ? $stmt->fetchAll() : [], 'id' => db()->lastInsertId()]);'''


def db(sql, params=()):
    result = subprocess.run(['php', '-r', PHP_DB], cwd=ROOT, input=json.dumps({'sql': sql, 'params': list(params)}),
                            text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def insert(table, values):
    columns = ', '.join('`' + name + '`' for name in values)
    placeholders = ', '.join('?' for _ in values)
    return int(db(f'INSERT INTO `{table}` ({columns}) VALUES ({placeholders})', values.values())['id'])


def row(table, id):
    return db(f'SELECT * FROM `{table}` WHERE id = ?', [id])['rows'][0]


def image_file(path):
    assert path.startswith('uploads/') and '..' not in path
    return Path(CONFIG_INFO['image_root']) / path[len('uploads/'):]


class Browser:
    def __init__(self):
        self.cookies = http.cookiejar.CookieJar()
        self.client = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.cookies))

    def request(self, path, values=None, expected=200):
        request = urllib.request.Request(BASE + '/' + path,
            data=None if values is None else urllib.parse.urlencode(values).encode())
        try:
            response = self.client.open(request)
        except urllib.error.HTTPError as error:
            response = error
        body = response.read().decode()
        assert response.code == expected, f'{path}: HTTP {response.code}, expected {expected}'
        return body

    def post(self, path, values, expected=200):
        # Follow the edit-form workflow, including the optimistic revision.
        form_path = path
        if (values.get('action') == 'update' and re.fullmatch(r'[1-9][0-9]{0,9}', str(values.get('id', '')))
                and 0 < int(values['id']) <= 4294967295):
            form_path = path.split('?')[0] + '?edit=' + str(values['id'])
        page = self.request(form_path)
        token = re.search(r'name="csrf" value="([a-f0-9]+)"', page)
        assert token, f'No CSRF token at {path}'
        fields = dict(values, csrf=token.group(1))
        revision = re.search(r'name="revision" value="([a-f0-9]+)"', page)
        if revision and 'revision' not in fields:
            fields['revision'] = revision.group(1)
        return self.request(path, fields, expected=expected)

    def login(self, username):
        assert f'Welkom {username} to Bij-Houden' in self.post('login.php', {'username': username, 'password': PASSWORD})


def run():
    a = insert('groups', {'name': PREFIX + '_A'})
    GROUP_IDS.append(a)
    b = insert('groups', {'name': PREFIX + '_B'})
    GROUP_IDS.append(b)
    hashed = subprocess.check_output(['php', '-r', 'print password_hash($argv[1], PASSWORD_DEFAULT);', PASSWORD], text=True)
    for suffix, role, group in [('admin', 'admin', None), ('member', 'user', a), ('groupless', 'user', None)]:
        USER_IDS.append(insert('users', {'username': PREFIX + '_' + suffix, 'password_hash': hashed, 'role': role, 'group_id': group}))
    apiary_a = insert('apiaries', {'name': PREFIX + '_yard_A', 'location': 'Test location', 'notes': '', 'group_id': a})
    apiary_b = insert('apiaries', {'name': PREFIX + '_yard_B', 'location': 'Other group', 'notes': '', 'group_id': b})
    hive_a = insert('hives', {'name': PREFIX + '_hive_A', 'apiary_id': apiary_a, 'notes': '', 'group_id': a})
    hive_spare = insert('hives', {'name': PREFIX + '_spare', 'notes': '', 'group_id': a})
    hive_b = insert('hives', {'name': PREFIX + '_hive_B', 'apiary_id': apiary_b, 'notes': '', 'group_id': b})
    hive_b_spare = insert('hives', {'name': PREFIX + '_spare_B', 'notes': '', 'group_id': b})
    queen_a = insert('queens', {'name': PREFIX + '_queen_A', 'state': 'mature', 'notes': '', 'group_id': a})
    queen_b = insert('queens', {'name': PREFIX + '_queen_B', 'state': 'mated', 'notes': '', 'group_id': b})
    colony_a = insert('colonies', {'name': PREFIX + '_colony_A', 'hive_id': hive_a, 'queen_id': queen_a,
        'date_of_birth': '2025-05-01', 'notes': '', 'group_id': a})
    colony_b = insert('colonies', {'name': PREFIX + '_colony_B', 'hive_id': hive_b,
        'date_of_birth': '2025-05-01', 'notes': '', 'group_id': b})
    admin, member = Browser(), Browser()
    admin.login(PREFIX + '_admin')
    member.login(PREFIX + '_member')

    for page in ['apiaries.php', 'hives.php', 'queens.php', 'colonies.php', 'inspections.php', 'profile.php', 'index.php']:
        member.request(page)
    assert 'Only administrators' in member.request('users.php')
    assert PREFIX + '_yard_B' not in member.request('apiaries.php')
    assert PREFIX + '_queen_B' not in member.request('colonies.php')
    member.request(f'record.php?type=colonies&id={colony_b}', expected=404)
    assert 'not manage' in member.request(f'colonies.php?edit={colony_b}')
    print('PASS: page rendering and group access')

    edits = [
        ('apiaries', apiary_a, {'name': PREFIX + '_yard_A', 'location': 'Updated location', 'notes': '', 'group_id': a}),
        ('hives', hive_a, {'name': PREFIX + '_hive_A', 'apiary_id': apiary_a, 'notes': 'Edited', 'group_id': a}),
        ('queens', queen_a, {'name': PREFIX + '_queen_A', 'state': 'mature', 'hatching_date': '2025-04-01', 'notes': 'Edited', 'group_id': a}),
        ('colonies', colony_a, {'name': PREFIX + '_colony_A', 'hive_id': hive_a, 'queen_id': queen_a,
            'date_of_birth': '2025-05-01', 'notes': 'Edited', 'group_id': a}),
    ]
    for table, id, values in edits:
        body = admin.request(f'{table}.php?edit={id}')
        assert f'name="id" value="{id}"' in body
        result = admin.post(f'{table}.php?edit={id}', dict(values, action='update', id=id))
        assert 'updated.' in result.lower(), f'Admin edit failed: {table}'
    print('PASS: admin editing on all four entity pages')

    colony_values = dict(edits[-1][2])
    result = admin.post('colonies.php', dict(colony_values, action='update', id=colony_a, hive_id=hive_b_spare))
    assert 'same group' in result and int(row('colonies', colony_a)['hive_id']) == hive_a
    result = admin.post('colonies.php', dict(colony_values, action='update', id=colony_a, queen_id=queen_b))
    assert 'same group' in result
    result = admin.post('queens.php', dict(edits[2][2], action='update', id=queen_a, group_id=b))
    assert 'Group change blocked' in result
    result = admin.post('apiaries.php', dict(edits[0][2], action='update', id=apiary_a, group_id=b))
    assert 'Group change blocked' in result
    result = member.post('colonies.php', dict(colony_values, action='add', name=PREFIX + '_duplicate', hive_id=hive_spare))
    assert 'already assigned' in result
    try:
        insert('colonies', {'name': PREFIX + '_duplicate_db', 'hive_id': hive_spare, 'queen_id': queen_a,
            'date_of_birth': '2025-05-01', 'group_id': a})
        raise AssertionError('Database allowed a queen in two active colonies')
    except subprocess.CalledProcessError:
        pass
    print('PASS: same-group relationships and database queen uniqueness')

    path = f'inspections.php?colony={colony_a}'
    body = member.request(path)
    for name in ['strength', 'temperament', 'supers', 'laying_pattern', 'population', 'queen_seen',
                 'queen_cells', 'swarmed', 'eggs', 'capped_brood', 'uncapped_brood', 'excessive_drones']:
        assert f'name="{name}"' in body
    observation = {'action': 'add', 'colony_id': colony_a, 'visited_at': '2025-06-01T14:30', 'status': 'good',
        'strength': 'strong', 'temperament': 'calm', 'supers': '2', 'laying_pattern': 'uniform', 'population': 'high',
        'queen_seen': '1', 'queen_cells': '1', 'swarmed': '1', 'eggs': '1', 'capped_brood': '1',
        'uncapped_brood': '1', 'excessive_drones': '1', 'todo': 'Recheck stores', 'notes': 'Integration inspection'}
    assert 'Inspection saved' in member.post(path, observation)
    inspection = db('SELECT * FROM inspections WHERE colony_id = ?', [colony_a])['rows'][0]
    iid = int(inspection['id'])
    assert int(inspection['supers']) == 2 and int(inspection['queen_seen']) == 1
    edit = member.request(f'inspections.php?edit={iid}')
    assert '2025-06-01T14:30' in edit and 'name="queen_seen" value="1" checked' in edit
    invalid = member.post(path, dict(observation, action='update', id=iid, supers='-1', visited_at='2025-02-30T12:00'))
    assert 'whole number' in invalid and 'valid visit date' in invalid and 'Recheck stores' in invalid
    cleared = {key: value for key, value in observation.items() if key not in
        ['queen_seen', 'queen_cells', 'swarmed', 'eggs', 'capped_brood', 'uncapped_brood', 'excessive_drones']}
    cleared.update(action='update', id=iid, strength='not_assessed', temperament='not_assessed',
                   supers='', laying_pattern='not_assessed', population='not_assessed')
    assert 'Inspection saved' in member.post(path, cleared)
    inspection = row('inspections', iid)
    assert inspection['supers'] is None and int(inspection['queen_seen']) == 0 and inspection['population'] == 'not_assessed'
    assert 'Not assessed' in member.request(path)
    assert 'not managed' in member.post(f'inspections.php?colony={colony_b}', dict(observation, colony_id=colony_b), expected=403)
    assert 'do not manage' in member.post(path, {'action': 'delete', 'id': 2147483647, 'colony_id': colony_a})
    print('PASS: inspection add/edit, field persistence, clearing, validation and ownership')

    assert 'Colony archived' in member.post('colonies.php', {'action': 'archive', 'id': colony_a})
    assert row('colonies', colony_a)['archived_at'] is not None
    assert row('inspections', iid)['colony_id'] == colony_a
    assert 'read-only' in member.request(path)
    assert 'active colony' in member.post(path, cleared)
    assert PREFIX + '_colony_A' in member.request('colonies.php?archived=1')
    assert 'Archived' in member.request(f'record.php?type=colonies&id={colony_a}')
    replacement = dict(colony_values, action='add', name=PREFIX + '_replacement')
    assert 'Colony added' in member.post('colonies.php', replacement)
    assert 'Queen archived' in member.post('queens.php', {'action': 'archive', 'id': queen_a})
    replacement_row = db('SELECT * FROM colonies WHERE name = ?', [replacement['name']])['rows'][0]
    assert replacement_row['queen_id'] is None
    assert int(row('colonies', colony_a)['queen_id']) == queen_a
    assert 'Archived' in member.request(f'record.php?type=queens&id={queen_a}')
    print('PASS: archiving, read-only history, hive/queen reuse and queenless active colony')

    invalid_profile = member.post('profile.php', {'action': 'identity', 'first_name': 'Retained', 'last_name': 'Name', 'email': 'bad'})
    assert 'valid e-mail' in invalid_profile and 'value="Retained"' in invalid_profile
    member.post('profile.php', {'action': 'identity', 'first_name': '<script>bad()</script>', 'last_name': 'Name', 'email': PREFIX + '@example.com'})
    assert '&lt;script&gt;bad()&lt;/script&gt;' in admin.request('users.php')
    invalid_password = member.post('profile.php', {'action': 'password', 'current_password': 'wrong',
        'new_password': PASSWORD + 'new', 'confirm_password': PASSWORD + 'new'})
    assert 'Current password is incorrect' in invalid_password
    groupless = Browser()
    groupless.login(PREFIX + '_groupless')
    assert PREFIX + '_colony_A' not in groupless.request('inspections.php')
    db('DELETE FROM users WHERE id = ?', [USER_IDS[1]])
    assert '<h1>Login</h1>' in member.request('index.php')
    print('PASS: profile validation/escaping and deleted/groupless user access')


def cleanup():
    images = []
    if USER_IDS:
        ids = ','.join('?' for _ in USER_IDS)
        images.extend(row['path'] for row in db(f'SELECT photo_path AS path FROM users WHERE id IN ({ids}) AND photo_path IS NOT NULL', USER_IDS)['rows'])
    if GROUP_IDS:
        ids = ','.join('?' for _ in GROUP_IDS)
        images.extend(row['path'] for row in db(f'SELECT logo_path AS path FROM `groups` WHERE id IN ({ids}) AND logo_path IS NOT NULL', GROUP_IDS)['rows'])
    db('DELETE FROM auth_login_limits')
    if not GROUP_IDS:
        if USER_IDS:
            ids = ','.join('?' for _ in USER_IDS)
            db(f'DELETE FROM activity_log WHERE user_id IN ({ids})', USER_IDS)
            db(f'DELETE FROM users WHERE id IN ({ids})', USER_IDS)
        cleanup_images(images)
        return
    placeholders = ','.join('?' for _ in GROUP_IDS)
    for sql in [
        f'DELETE FROM tasks WHERE group_id IN ({placeholders})',
        f'DELETE i FROM inspections i JOIN colonies c ON c.id=i.colony_id WHERE c.group_id IN ({placeholders})',
        f'DELETE FROM colonies WHERE group_id IN ({placeholders})',
        f'DELETE FROM hives WHERE group_id IN ({placeholders})',
        f'DELETE FROM queens WHERE group_id IN ({placeholders})',
        f'DELETE FROM apiaries WHERE group_id IN ({placeholders})',
    ]:
        db(sql, GROUP_IDS)
    if USER_IDS:
        ids = ','.join('?' for _ in USER_IDS)
        db(f'DELETE FROM activity_log WHERE user_id IN ({ids})', USER_IDS)
        db(f'DELETE FROM users WHERE id IN ({ids})', USER_IDS)
    db(f'DELETE FROM `groups` WHERE id IN ({placeholders})', GROUP_IDS)
    cleanup_images(images)


def cleanup_images(paths):
    if not paths:
        return
    subprocess.run(['php', '-r', 'require "db.php"; foreach (json_decode(stream_get_contents(STDIN), true) as $path) cleanup_image($path);'],
        cwd=ROOT, input=json.dumps(paths), text=True, capture_output=True, check=True)


if __name__ == '__main__':
    try:
        run()
    finally:
        cleanup()
