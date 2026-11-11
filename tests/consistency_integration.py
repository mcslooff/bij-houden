import concurrent.futures
import contextlib
import json
import os
import re
import secrets
import signal
import socket
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

import integration as test
import group_owner_labels_integration as logos


TRIGGER = test.PREFIX + '_audit'


@contextlib.contextmanager
def parallel_server(force_cli=False):
    if not force_cli and os.environ.get('BIJHOUDEN_TEST_PARALLEL_BACKEND') == 'existing':
        # A multi-worker nginx/FPM staging target can exercise the actual stack.
        # Import-time fingerprint checks still protect the selected target.
        yield
        return
    with socket.socket() as reservation:
        reservation.bind(('127.0.0.1', 0))
        port = reservation.getsockname()[1]
    environment = dict(os.environ, PHP_CLI_SERVER_WORKERS='4')
    path = os.environ['BIJHOUDEN_TEST_SESSION_PATH']
    process = subprocess.Popen(['php', '-d', 'session.save_path=' + path, '-S', '127.0.0.1:' + str(port),
        '-t', 'public', 'public/router.php'], cwd=test.ROOT, env=environment,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, start_new_session=True)
    previous = test.BASE
    target = 'http://127.0.0.1:' + str(port)
    try:
        for _ in range(100):
            try:
                response = urllib.request.urlopen(target + '/login.php', timeout=2)
                assert response.headers.get('X-Bijhouden-Test-Database') == test.CONFIG_INFO['fingerprint']
                break
            except urllib.error.URLError:
                time.sleep(0.03)
        else:
            raise AssertionError('Parallel test server did not start')
        test.BASE = target
        yield
    finally:
        test.BASE = previous
        os.killpg(process.pid, signal.SIGTERM)
        process.wait(timeout=10)


def trigger(fail=False, delay=None):
    test.db('DROP TRIGGER IF EXISTS `' + TRIGGER + '`')
    if fail:
        test.db('CREATE TRIGGER `' + TRIGGER + "` BEFORE INSERT ON activity_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test audit failure'")
    elif delay:
        assert re.fullmatch('[a-z_]+', delay)
        test.db('CREATE TRIGGER `' + TRIGGER + "` BEFORE INSERT ON activity_log FOR EACH ROW BEGIN IF NEW.event = '" + delay + "' THEN DO SLEEP(2); END IF; END")


def wait_for_database_state(kind, future=None):
    for _ in range(100):
        if future is not None and future.done():
            raise AssertionError('Request finished before concurrency barrier: ' + str(future.result())[:200])
        if kind == 'sleep':
            rows = test.db("SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST WHERE DB = ? AND STATE = 'User sleep'", [test.CONFIG_INFO['database']])['rows']
        else:
            rows = test.db('''SELECT COUNT(*) AS n FROM information_schema.INNODB_LOCK_WAITS w
                JOIN information_schema.INNODB_TRX t ON t.trx_id = w.requesting_trx_id
                JOIN information_schema.PROCESSLIST p ON p.ID = t.trx_mysql_thread_id WHERE p.DB = ?''', [test.CONFIG_INFO['database']])['rows']
            if int(rows[0]['n']) == 0:
                # A prepared locking read may wait in optimization before it appears in INNODB_LOCK_WAITS.
                rows = test.db('''SELECT COUNT(*) AS n FROM information_schema.PROCESSLIST
                    WHERE DB = ? AND STATE = 'Statistics' AND INFO LIKE ?''',
                    [test.CONFIG_INFO['database'], 'SELECT id, username, role, group_id, auth_version FROM users WHERE id = %FOR UPDATE'])['rows']
        if int(rows[0]['n']) > 0:
            return
        time.sleep(0.01)
    state = test.db('SELECT COMMAND, STATE, LEFT(INFO, 160) AS statement FROM information_schema.PROCESSLIST WHERE DB = ?',
                    [test.CONFIG_INFO['database']])['rows']
    raise AssertionError('Concurrency barrier was not reached: ' + kind + '; state=' + str(state))


def run():
    a = test.insert('groups', {'name': test.PREFIX + '_A', 'owner_label_token': secrets.token_hex(16)})
    b = test.insert('groups', {'name': test.PREFIX + '_B_secret', 'owner_label_token': secrets.token_hex(16)})
    test.GROUP_IDS.extend([a, b])
    hashed = subprocess.check_output(['php', '-r', 'require "db.php"; print hash_user_password($argv[1]);', test.PASSWORD], cwd=test.ROOT, text=True)
    ids, browsers = {}, {}
    for name, role, group in [('admin', 'admin', None), ('one', 'user', a), ('two', 'user', a), ('foreign', 'user', b), ('moving', 'user', a)]:
        ids[name] = test.insert('users', {'username': test.PREFIX + '_' + name, 'password_hash': hashed, 'role': role, 'group_id': group})
        test.USER_IDS.append(ids[name])
        browser = test.Browser()
        browser.login(test.PREFIX + '_' + name)
        browsers[name] = browser
    admin, one, two, foreign = [browsers[name] for name in ['admin', 'one', 'two', 'foreign']]

    def yard(name, group=a):
        return test.insert('apiaries', {'name': test.PREFIX + '_' + name, 'location': 'Field', 'notes': '', 'group_id': group})

    def hive(name, apiary=None, group=a):
        return test.insert('hives', {'name': test.PREFIX + '_' + name, 'apiary_id': apiary, 'group_id': group, 'notes': ''})

    def queen(name, group=a):
        return test.insert('queens', {'name': test.PREFIX + '_' + name, 'state': 'mature', 'group_id': group, 'notes': ''})

    def colony(name, hive_id, queen_id=None, group=a):
        return test.insert('colonies', {'name': test.PREFIX + '_' + name, 'hive_id': hive_id, 'queen_id': queen_id,
            'group_id': group, 'date_of_birth': '2025-01-01', 'notes': ''})

    apiary = yard('yard')
    apiary_b = yard('secret_yard', b)
    hive_id = hive('hive', apiary)
    queen_id = queen('queen')
    colony_id = colony('colony', hive_id, queen_id)
    hive_values = {'action': 'update', 'id': hive_id, 'name': test.PREFIX + '_hive', 'apiary_id': apiary, 'notes': '', 'group_id': a}
    colony_values = {'action': 'update', 'id': colony_id, 'name': test.PREFIX + '_colony', 'hive_id': hive_id,
        'queen_id': queen_id, 'date_of_birth': '2025-01-01', 'notes': '', 'group_id': a}
    observation = {'action': 'add', 'colony_id': colony_id, 'visited_at': '2025-06-01T12:00', 'status': 'good',
        'strength': 'strong', 'temperament': 'calm', 'supers': '2', 'laying_pattern': 'uniform', 'population': 'high', 'notes': ''}

    for bad in ['0', '-1', '+1', '01', '1e0', '1 OR 1=1', '4294967296', '1\0', '1 ']:
        one.post('hives.php', dict(hive_values, id=bad), expected=400)
    for values in [{'action[]': 'update', 'id': hive_id}, dict(hive_values, **{'name[]': 'bad'}),
                   dict(hive_values, group_id='1junk'), dict(hive_values, notes='bad\0notes')]:
        if 'name[]' in values:
            values.pop('name', None)
        one.post('hives.php', values, expected=400)
    one.post('colonies.php', dict(colony_values, date_of_birth='0001-01-01'), expected=400)
    one.request('colonies.php?edit[]=1', expected=400)
    one.request('record.php?type[]=colonies&id=1', expected=400)
    one.request('hives.php?edit=1%20OR%201=1', expected=400)
    csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', one.request('hives.php')).group(1)
    malformed = urllib.request.Request(test.BASE + '/hives.php', data=('action=add&csrf=' + csrf + '&name=%FF').encode())
    try:
        response = one.client.open(malformed)
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == 400
    assert 'do not manage' in foreign.post('hives.php', hive_values, expected=403)
    literal = test.PREFIX + "_'); DROP TABLE colonies; -- <tag>"
    assert 'Hive updated' in one.post('hives.php', dict(hive_values, name=literal, notes=literal))
    assert test.row('hives', hive_id)['name'] == literal
    assert int(test.db('SELECT COUNT(*) AS n FROM colonies')['rows'][0]['n']) > 0
    assert '&lt;tag&gt;' in one.request('hives.php')
    assert 'Hive updated' in one.post('hives.php', hive_values)
    assert 'Inspection saved' in one.post('inspections.php', dict(observation, queen_seen='0'))
    inspection_id = int(test.db('SELECT id FROM inspections WHERE colony_id = ?', [colony_id])['rows'][0]['id'])
    assert int(test.row('inspections', inspection_id)['queen_seen']) == 0
    probe = '''require "db.php"; $p = json_decode(stream_get_contents(STDIN), true);
        $s = db()->prepare('SELECT id, username, auth_version FROM users WHERE id = ?'); $s->execute([$p['actor']]);
        auth_establish_session($s->fetch()); $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = ['action' => 'update', 'id' => $p['id'], 'group_id' => $p['group'], 'csrf' => csrf_token()];
        $s = db()->prepare('SELECT * FROM apiaries WHERE id = ?'); $s->execute([$p['id']]);
        $_POST['revision'] = edit_revision('apiaries', $s->fetch());
        begin_request_mutation('apiaries.php');
        db()->prepare('UPDATE apiaries SET notes = ? WHERE id = ? AND group_id <=> ?')
            ->execute([$p['note'], $p['id'], mutation_original_group('apiaries', $p['id'])]);
        if ($p['status'] === 303) log_event($p['actor'], 'guard_commit_probe', 'fixture');
        http_response_code($p['status']); exit;'''
    for status in [200, 303]:
        result = subprocess.run(['php', '-r', probe], cwd=test.ROOT, text=True, capture_output=True,
            input=json.dumps({'actor': ids['one'], 'id': apiary, 'group': a, 'note': 'commit-probe', 'status': status}))
        assert result.returncode == 0, result.stderr
        assert test.row('apiaries', apiary)['notes'] == ('' if status == 200 else 'commit-probe')
    test.db('UPDATE apiaries SET notes = ? WHERE id = ?', ['', apiary])
    print('PASS: strict malformed request handling, scoped denials, literal SQL payloads and checkbox semantics')

    broken = hive('broken', apiary_b)
    broken_child = colony('broken_child', broken, group=b)
    page = one.request('hives.php')
    assert test.PREFIX + '_secret_yard' not in page and 'Unavailable apiary' in page
    assert 'inconsistent ownership' in one.post('hives.php', dict(hive_values, id=broken), expected=409)
    test.db('DELETE FROM colonies WHERE id = ?', [broken_child])
    test.db('DELETE FROM hives WHERE id = ?', [broken])

    logos.upload(admin, {'action': 'add', 'name': test.PREFIX + '_logo_rollback'}, logos.sample_png())
    logo_group = test.db('SELECT * FROM `groups` WHERE name = ?', [test.PREFIX + '_logo_rollback'])['rows'][0]
    test.GROUP_IDS.append(int(logo_group['id']))
    cascade_task = test.insert('tasks', {'group_id': a, 'title': test.PREFIX + '_cascade', 'assigned_to': ids['moving'],
        'completed_by': ids['moving'], 'completed_by_name': 'Recorded completer', 'completed_at': '2025-06-01 12:00:00'})
    trigger(fail=True)
    try:
        failed_name = test.PREFIX + '_audit_fail'
        body = one.post('apiaries.php', {'action': 'add', 'name': failed_name, 'location': 'Test', 'notes': ''}, expected=500)
        assert 'SQLSTATE' not in body and not test.db('SELECT id FROM apiaries WHERE name = ?', [failed_name])['rows']
        one.post('hives.php', dict(hive_values, name=failed_name), expected=500)
        assert test.row('hives', hive_id)['name'] == hive_values['name']
        before = test.db('SELECT COUNT(*) AS n FROM inspections WHERE colony_id = ?', [colony_id])['rows'][0]['n']
        one.post('inspections.php', observation, expected=500)
        assert test.db('SELECT COUNT(*) AS n FROM inspections WHERE colony_id = ?', [colony_id])['rows'][0]['n'] == before
        one.post('queens.php', {'action': 'archive', 'id': queen_id}, expected=500)
        assert test.row('queens', queen_id)['archived_at'] is None
        assert int(test.row('colonies', colony_id)['queen_id']) == queen_id
        admin.post('users.php', {'action': 'delete', 'id': ids['moving']}, expected=500)
        assert test.row('users', ids['moving'])
        assert int(test.row('tasks', cascade_task)['assigned_to']) == ids['moving']
        assert int(test.row('tasks', cascade_task)['completed_by']) == ids['moving']
        admin.post('groups.php', {'action': 'delete', 'id': logo_group['id']}, expected=500)
        assert test.row('groups', int(logo_group['id']))['logo_path'] == logo_group['logo_path']
        assert test.image_file(logo_group['logo_path']).is_file()
    finally:
        trigger()
    print('PASS: failed audit insertion rolls back entity, inspection, archive side effects and user deletion')

    with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
        transferring = yard('transferring')
        transfer = {'action': 'update', 'id': transferring, 'name': test.PREFIX + '_transferring', 'location': 'Field', 'notes': '', 'group_id': b}
        trigger(delay='apiary_updated')
        future = pool.submit(admin.post, 'apiaries.php', transfer)
        wait_for_database_state('sleep')
        child = pool.submit(one.post, 'hives.php', {'action': 'add', 'name': test.PREFIX + '_race_hive', 'apiary_id': transferring, 'notes': ''})
        assert 'Apiary updated' in future.result(timeout=15)
        assert 'does not exist' in child.result(timeout=15)
        assert not test.db('SELECT id FROM hives WHERE apiary_id = ?', [transferring])['rows']
        trigger()

        retained = yard('retained')
        trigger(delay='hive_added')
        child = pool.submit(one.post, 'hives.php', {'action': 'add', 'name': test.PREFIX + '_retained_child', 'apiary_id': retained, 'notes': ''})
        wait_for_database_state('sleep')
        transfer = pool.submit(admin.post, 'apiaries.php', {'action': 'update', 'id': retained,
            'name': test.PREFIX + '_retained', 'location': 'Field', 'notes': '', 'group_id': b})
        assert 'Hive added' in child.result(timeout=15)
        assert 'Group change blocked' in transfer.result(timeout=15)
        assert int(test.row('apiaries', retained)['group_id']) == a
        trigger()

        race_queen = queen('race_queen')
        first_hive, second_hive = hive('first'), hive('second')
        make = dict(colony_values, action='add', name=test.PREFIX + '_first', hive_id=first_hive, queen_id=race_queen)
        trigger(delay='colony_added')
        first = pool.submit(one.post, 'colonies.php', make)
        wait_for_database_state('sleep')
        second = pool.submit(two.post, 'colonies.php', dict(make, name=test.PREFIX + '_second', hive_id=second_hive))
        assert 'Colony added' in first.result(timeout=15)
        assert 'already assigned' in second.result(timeout=15)
        assert len(test.db('SELECT id FROM colonies WHERE queen_id = ? AND archived_at IS NULL', [race_queen])['rows']) == 1
        trigger()

        archive_queen = queen('archive_race')
        trigger(delay='queen_archived')
        archive = pool.submit(one.post, 'queens.php', {'action': 'archive', 'id': archive_queen})
        wait_for_database_state('sleep')
        assignment = pool.submit(two.post, 'colonies.php', dict(make, name=test.PREFIX + '_archived_assignment', hive_id=second_hive, queen_id=archive_queen))
        assert 'Queen archived' in archive.result(timeout=15)
        assert 'archived queen' in assignment.result(timeout=15)
        assert not test.db('SELECT id FROM colonies WHERE queen_id = ? AND archived_at IS NULL', [archive_queen])['rows']
        trigger()

        inspection_colony = colony('inspection_race', hive('inspection_hive'))
        trigger(delay='inspection_added')
        inspection = pool.submit(one.post, 'inspections.php', dict(observation, colony_id=inspection_colony))
        wait_for_database_state('sleep')
        archive = pool.submit(two.post, 'colonies.php', {'action': 'archive', 'id': inspection_colony})
        assert 'Inspection saved' in inspection.result(timeout=15)
        assert 'Colony archived' in archive.result(timeout=15)
        assert test.row('colonies', inspection_colony)['archived_at'] is not None
        assert len(test.db('SELECT id FROM inspections WHERE colony_id = ?', [inspection_colony])['rows']) == 1
        assert 'active colony' in one.post('inspections.php', dict(observation, colony_id=inspection_colony))
        trigger()

        already_archived = colony('archive_first', hive('archive_first_hive'))
        trigger(delay='colony_archived')
        archive = pool.submit(one.post, 'colonies.php', {'action': 'archive', 'id': already_archived})
        wait_for_database_state('sleep')
        inspection = pool.submit(two.post, 'inspections.php', dict(observation, colony_id=already_archived))
        assert 'Colony archived' in archive.result(timeout=15)
        assert 'active colony' in inspection.result(timeout=15)
        assert not test.db('SELECT id FROM inspections WHERE colony_id = ?', [already_archived])['rows']
        trigger()
    print('PASS: concurrent parent transfer, queen allocation/archive and inspection/archive operations preserve ownership/history')

    target = yard('revocation')
    mutation = {'action': 'update', 'id': target, 'name': test.PREFIX + '_unauthorized', 'location': 'Field', 'notes': ''}
    locker_code = '''require "db.php"; $ids = json_decode(fgets(STDIN), true); db()->beginTransaction();
        $s = db()->prepare('SELECT id FROM users WHERE id = ? FOR UPDATE'); $s->execute([$ids[0]]);
        db()->prepare('UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?')->execute([$ids[0]]);
        db()->prepare('UPDATE users SET group_id = ?, auth_version = auth_version + 1 WHERE id = ?')->execute([$ids[1], $ids[0]]);
        print 'locked' . PHP_EOL; fflush(STDOUT); fgets(STDIN); db()->commit();'''
    locker = subprocess.Popen(['php', '-r', locker_code], cwd=test.ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        locker.stdin.write(json.dumps([ids['moving'], b]) + '\n'); locker.stdin.flush()
        assert locker.stdout.readline().strip() == 'locked'
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            # Fetch the CSRF token before blocking, using the already authenticated actor.
            moving = browsers['moving']
            csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', moving.request('hives.php')).group(1)
            future = pool.submit(moving.request, 'apiaries.php', dict(mutation, csrf=csrf), 403)
            wait_for_database_state('lock', future)
            locker.stdin.write('commit\n'); locker.stdin.flush()
            locker.wait(timeout=10)
            assert 'account changed' in future.result(timeout=10)
        assert test.row('apiaries', target)['name'] != mutation['name']
    finally:
        if locker.poll() is None:
            locker.terminate(); locker.wait(timeout=5)
    print('PASS: revocation committed during an in-flight request prevents the stale authorized write')

    assignment_target = test.insert('users', {'username': test.PREFIX + '_assignment_target', 'password_hash': hashed, 'group_id': a})
    test.USER_IDS.append(assignment_target)
    locker = subprocess.Popen(['php', '-r', locker_code], cwd=test.ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        locker.stdin.write(json.dumps([assignment_target, b]) + '\n'); locker.stdin.flush()
        assert locker.stdout.readline().strip() == 'locked'
        with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
            future = pool.submit(one.post, 'tasks.php', {'action': 'add', 'title': test.PREFIX + '_assignment_race', 'assigned_to': assignment_target})
            wait_for_database_state('lock', future)
            locker.stdin.write('commit\n'); locker.stdin.flush()
            locker.wait(timeout=10)
            assert 'must be a member' in future.result(timeout=10)
        assert not test.db('SELECT id FROM tasks WHERE title = ?', [test.PREFIX + '_assignment_race'])['rows']
    finally:
        if locker.poll() is None:
            locker.terminate(); locker.wait(timeout=5)
    print('PASS: concurrent member transfer cannot create a cross-group task assignment')

    admin_browsers = []
    admin_ids = []
    for name in ['last_a', 'last_b']:
        username = test.PREFIX + '_' + name
        uid = test.insert('users', {'username': username, 'role': 'admin', 'password_hash': hashed})
        test.USER_IDS.append(uid); admin_ids.append(uid)
        browser = test.Browser(); browser.login(username); admin_browsers.append(browser)
    test.db("UPDATE users SET role = 'user', group_id = ?, auth_version = auth_version + 1 WHERE id = ?", [a, ids['admin']])
    trigger(delay='user_deleted')
    try:
        # Use raw requests so the loser can receive the expected 403 after revocation.
        payloads = []
        for browser, target in zip(admin_browsers, reversed(admin_ids)):
            csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', browser.request('users.php')).group(1)
            payloads.append({'action': 'delete', 'id': target, 'csrf': csrf})
        def send(browser, payload):
            request = urllib.request.Request(test.BASE + '/users.php', data=urllib.parse.urlencode(payload).encode())
            try:
                response = browser.client.open(request)
            except urllib.error.HTTPError as error:
                response = error
            return response.status, response.read().decode()
        with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
            first = pool.submit(send, admin_browsers[0], payloads[0])
            wait_for_database_state('sleep')
            second = pool.submit(send, admin_browsers[1], payloads[1])
            responses = [first.result(timeout=15), second.result(timeout=15)]
        assert sorted(code for code, body in responses) == [200, 403], responses
        assert int(test.db("SELECT COUNT(*) AS n FROM users WHERE role = 'admin'")['rows'][0]['n']) == 1
    finally:
        trigger()
    inspection = subprocess.run(['php', 'bin/check-integrity.php'], cwd=test.ROOT, text=True, capture_output=True)
    assert inspection.returncode == 0, inspection.stdout + inspection.stderr
    print('PASS: concurrent administrator deletion retains an administrator; final ownership/uniqueness integrity check is clean')


if __name__ == '__main__':
    try:
        with parallel_server():
            run()
    finally:
        trigger()
        logos.cleanup()
