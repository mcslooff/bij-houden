"""Historical-safe timestamp writes through the real HTTP forms."""
import subprocess

import integration as test


def run():
    group = user = None
    try:
        group = test.insert('groups', {'name': test.PREFIX + '_time'})
        hashed = subprocess.check_output(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
        username = test.PREFIX + '_time'
        user = test.insert('users', {'username': username, 'password_hash': hashed, 'group_id': group})
        hive = test.insert('hives', {'name': 'Time test', 'group_id': group})
        colony = test.insert('colonies', {'name': 'Time test', 'group_id': group, 'hive_id': hive, 'date_of_birth': '2020-01-01'})
        legacy = test.insert('inspections', {'colony_id': colony, 'visited_at': '2020-07-01 12:34:56'})
        browser = test.Browser()
        browser.login(username)
        fields = {'action': 'update', 'id': legacy, 'colony_id': colony, 'visited_at': '2020-07-01T12:34',
                  'status': 'good', 'strength': 'not_assessed', 'temperament': 'not_assessed',
                  'laying_pattern': 'not_assessed', 'population': 'not_assessed', 'notes': 'Edited note'}
        assert 'Inspection saved' in browser.post('inspections.php', fields)
        row = test.row('inspections', legacy)
        assert row['visited_at'] == '2020-07-01 12:34:56'
        assert row['visited_at_utc'] is None and row['visited_timezone'] is None
        fields.update(visited_at='2026-07-01T12:00')
        assert 'Inspection saved' in browser.post('inspections.php', fields)
        row = test.row('inspections', legacy)
        assert row['visited_at_utc'] == '2026-07-01 10:00:00'
        assert row['visited_timezone'] == 'Europe/Amsterdam' and row['visited_offset'] == '+02:00'
        fields.update(visited_at='2026-03-29T02:30')
        assert 'does not exist' in browser.post('inspections.php', fields)
        assert test.row('inspections', legacy)['visited_at'] == '2026-07-01 12:00:00'
        fields.update(visited_at='2026-10-25T02:30')
        assert 'occurs twice' in browser.post('inspections.php', fields)
        fields.update(visited_offset='+01:00')
        assert 'Inspection saved' in browser.post('inspections.php', fields)
        assert test.row('inspections', legacy)['visited_at_utc'] == '2026-10-25 01:30:00'
        task = test.insert('tasks', {'title': 'Time test', 'group_id': group})
        browser.post('tasks.php', {'action': 'complete', 'id': task})
        completed = test.row('tasks', task)
        assert completed['completed_at_utc'] and completed['completed_timezone'] == 'Europe/Amsterdam'
        browser.post('tasks.php', {'action': 'reopen', 'id': task})
        assert test.row('tasks', task)['completed_at_utc'] is None
        browser.post('colonies.php', {'action': 'archive', 'id': colony})
        assert test.row('colonies', colony)['archived_timezone'] == 'Europe/Amsterdam'
        print('PASS: legacy visit seconds/unknown zone preserved, explicit Amsterdam/DST writes, completion/reopen and archive annotations')
    finally:
        if group:
            test.db('DELETE i FROM inspections i JOIN colonies c ON c.id = i.colony_id WHERE c.group_id = ?', [group])
            for table in ['tasks', 'colonies', 'hives']:
                test.db(f'DELETE FROM {table} WHERE group_id = ?', [group])
        if user:
            test.db('DELETE FROM activity_log WHERE user_id = ?', [user])
            test.db('DELETE FROM users WHERE id = ?', [user])
        if group:
            test.db('DELETE FROM `groups` WHERE id = ?', [group])


if __name__ == '__main__':
    run()
