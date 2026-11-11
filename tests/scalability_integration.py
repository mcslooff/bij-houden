"""Local large-list verification, never a substitute for hosting load tests."""
import json
import re
import statistics
import subprocess
import time

import integration as test  # Rejects normal DBs and mismatched HTTP/media targets.


def bulk_insert(table, columns, rows):
    for start in range(0, len(rows), 250):
        batch = rows[start:start + 250]
        placeholders = '(' + ','.join('?' for _ in columns) + ')'
        test.db('INSERT INTO `' + table + '` (`' + '`,`'.join(columns) + '`) VALUES '
                + ','.join(placeholders for _ in batch), [value for row in batch for value in row])


def run():
    group = user = colony = None
    prefix = test.PREFIX + '_scale'
    metrics = {}
    try:
        group = test.insert('groups', {'name': prefix})
        hashed = subprocess.check_output(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
        user = test.insert('users', {'username': prefix, 'password_hash': hashed, 'group_id': group})
        for table in ['apiaries', 'hives', 'queens']:
            columns = ['name', 'group_id'] + (['location'] if table == 'apiaries' else [])
            rows = [[prefix + f'_{n:05}', group] + (['Scale fixture'] if table == 'apiaries' else [])
                    for n in range(2000)]
            bulk_insert(table, columns, rows)
        hive = int(test.db('SELECT id FROM hives WHERE group_id = ? ORDER BY id LIMIT 1', [group])['rows'][0]['id'])
        colony = test.insert('colonies', {'name': prefix, 'group_id': group, 'hive_id': hive, 'date_of_birth': '2026-01-01'})
        bulk_insert('tasks', ['group_id', 'title', 'assigned_to', 'priority'],
                    [[group, prefix + f'_task_{n:05}', user if n < 2000 else None, 'high' if n % 2 else 'none']
                     for n in range(4000)])
        bulk_insert('inspections', ['colony_id', 'visited_at'], [[colony, '2026-01-01 12:00:00'] for _ in range(3000)])
        browser = test.Browser()
        browser.login(prefix)
        cases = [
            ('apiaries.php', 50), ('apiaries.php?page=40', 50),
            ('hives.php', 50), ('hives.php?page=40', 50),
            ('queens.php', 50), ('queens.php?page=40', 50),
            ('tasks.php', 50), ('tasks.php?page=80', 50),
            (f'inspections.php?colony={colony}', 50),
            (f'inspections.php?colony={colony}&page=60', 50),
        ]
        for path, expected in cases:
            durations = []
            for _ in range(3):
                started = time.monotonic()
                body = browser.request(path)
                durations.append(time.monotonic() - started)
            if path.startswith('tasks'):
                assert body.count('data-task-id=') == expected, path
            elif path.startswith('inspections'):
                assert body.count('class="inspection-entry"') == expected, path
            else:
                table_body = re.search(r'<tbody>(.*?)</tbody>', body, re.S).group(1)
                assert table_body.count('<tr>') == expected, path
            metrics[path] = {'median_seconds': round(statistics.median(durations), 3),
                             'max_seconds': round(max(durations), 3), 'response_bytes': len(body.encode())}
            assert max(durations) < 5, f'Local request exceeded 5 seconds: {path}, {durations}'
        # Reconstruct the exact SQL ordering across the 40/41 assignment boundary.
        ordering = '''ORDER BY CASE WHEN assigned_to = ? THEN 0 WHEN assigned_to IS NULL THEN 1 ELSE 2 END,
            target_date IS NULL, target_date,
            CASE priority WHEN 'high' THEN 0 WHEN 'medium' THEN 1 WHEN 'low' THEN 2 ELSE 3 END, id'''
        expected = [int(row['id']) for row in test.db(
            'SELECT id FROM tasks WHERE group_id = ? ' + ordering + ' LIMIT 100 OFFSET 1950', [group, user])['rows']]
        actual = []
        for page in [40, 41]:
            body = browser.request(f'tasks.php?page={page}')
            actual.extend(int(id) for id in re.findall(r'data-task-id="([0-9]+)"', body))
        assert actual == expected, 'Task ordering changed across the assignment bucket boundary'
        print('PASS: 13,000 scoped fixtures, bounded first/last pages and task ordering across an assignment boundary')
        print(json.dumps({'environment': 'local PHP development server', 'metrics': metrics}, indent=2))
    finally:
        if colony:
            test.db('DELETE FROM inspections WHERE colony_id = ?', [colony])
        if group:
            for table in ['tasks', 'colonies', 'hives', 'queens', 'apiaries']:
                test.db(f'DELETE FROM {table} WHERE group_id = ?', [group])
        if user:
            test.db('DELETE FROM activity_log WHERE user_id = ?', [user])
            test.db('DELETE FROM users WHERE id = ?', [user])
        if group:
            test.db('DELETE FROM `groups` WHERE id = ?', [group])


if __name__ == '__main__':
    run()
