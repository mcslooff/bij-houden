"""Two-browser edit conflicts, including same-second writes and missing tokens."""
import re
import subprocess

import integration as test


def form(browser, page, id):
    body = browser.request(f'{page}?edit={id}')
    return {key: re.search(fr'name="{key}" value="([a-f0-9]+)"', body).group(1)
            for key in ['csrf', 'revision']}


def run():
    group = admin_id = member_id = None
    try:
        group = test.insert('groups', {'name': test.PREFIX + '_revision'})
        hashed = subprocess.check_output(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
        admin_id = test.insert('users', {'username': test.PREFIX + '_admin', 'password_hash': hashed, 'role': 'admin'})
        member_id = test.insert('users', {'username': test.PREFIX + '_member', 'password_hash': hashed, 'group_id': group})
        apiary = test.insert('apiaries', {'name': 'Before', 'location': 'Test', 'group_id': group})
        hive = test.insert('hives', {'name': 'Before', 'group_id': group})
        queen = test.insert('queens', {'name': 'Before', 'group_id': group})
        colony = test.insert('colonies', {'name': 'Before', 'hive_id': hive, 'group_id': group, 'date_of_birth': '2026-01-01'})
        task = test.insert('tasks', {'title': 'Before', 'group_id': group})
        inspection = test.insert('inspections', {'colony_id': colony, 'visited_at': '2026-01-01 12:00:00'})
        one, two = test.Browser(), test.Browser()
        one.login(test.PREFIX + '_admin')
        two.login(test.PREFIX + '_admin')
        cases = [('apiaries', apiary, 'location'), ('hives', hive, 'notes'),
                 ('queens', queen, 'notes'), ('colonies', colony, 'notes'),
                 ('tasks', task, 'description'), ('inspections', inspection, 'notes'),
                 ('groups', group, 'owner_name'), ('users', member_id, 'first_name')]
        for table, id, changed in cases:
            page = table + '.php'
            fields = form(one, page, id)
            # Direct fixture write avoids relying on timestamp granularity.
            test.db(f'UPDATE `{table}` SET `{changed}` = ? WHERE id = ?', ['Concurrent edit', id])
            values = dict(fields, action='update', id=id)
            if table == 'inspections':
                values['colony_id'] = colony
            assert 'changed since' in one.request(page, values, expected=409)
            assert test.row(table, id)[changed] == 'Concurrent edit'
            # A new form supplies a fresh token; omitting/tampering it is refused.
            fresh = form(two, page, id)
            for revision in ['', '0' * 64]:
                assert 'changed since' in two.request(page, dict(values, **dict(fresh, revision=revision)), expected=409)
        # Real successful save followed by replay of the original form.
        fields = form(one, 'apiaries.php', apiary)
        values = dict(fields, action='update', id=apiary, name='Saved', location='Test', group_id=group, notes='')
        assert 'Apiary updated' in one.request('apiaries.php', values)
        assert 'changed since' in one.request('apiaries.php', dict(values, name='Stale'), expected=409)
        assert test.row('apiaries', apiary)['name'] == 'Saved'
        print('PASS: eight edit forms reject stale/missing/tampered snapshots; same-second replay preserves the newer save')
    finally:
        if group:
            test.db('DELETE i FROM inspections i JOIN colonies c ON c.id = i.colony_id WHERE c.group_id = ?', [group])
            for table in ['tasks', 'colonies', 'hives', 'queens', 'apiaries']:
                test.db(f'DELETE FROM {table} WHERE group_id = ?', [group])
        for user in [member_id, admin_id]:
            if user:
                test.db('DELETE FROM activity_log WHERE user_id = ?', [user])
                test.db('DELETE FROM users WHERE id = ?', [user])
        if group:
            test.db('DELETE FROM `groups` WHERE id = ?', [group])


if __name__ == '__main__':
    run()
