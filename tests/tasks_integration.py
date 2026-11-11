import re
import subprocess

import integration as test


def task_ids(page):
    return [int(id) for id in re.findall(r'data-task-id="(\d+)"', page)]


def run():
    group_a = test.insert('groups', {'name': test.PREFIX + '_A'})
    group_b = test.insert('groups', {'name': test.PREFIX + '_B'})
    group_empty = test.insert('groups', {'name': test.PREFIX + '_tasks_only'})
    test.GROUP_IDS.extend([group_a, group_b, group_empty])
    hashed = subprocess.check_output(['php', '-r', 'print password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
    ids = {}
    browsers = {}
    for name, role, group in [('one', 'user', group_a), ('two', 'user', group_a), ('foreign', 'user', group_b), ('admin', 'admin', None)]:
        ids[name] = test.insert('users', {'username': test.PREFIX + '_' + name, 'password_hash': hashed,
            'first_name': name.title(), 'last_name': 'Beekeeper', 'role': role, 'group_id': group})
        test.USER_IDS.append(ids[name])
        browser = test.Browser()
        body = browser.post('login.php', {'username': test.PREFIX + '_' + name, 'password': test.PASSWORD})
        assert 'Welkom ' + name.title() + ' to Bij-Houden' in body
        browsers[name] = browser
    one, two, foreign, admin = [browsers[name] for name in ['one', 'two', 'foreign', 'admin']]

    assert 'No inspections recorded yet.' in one.request('index.php')
    result = one.post('tasks.php', {'action': 'add', 'title': 'Check stores', 'description': 'Look at food reserves',
        'group_id': group_b})
    assert 'Task saved' in result
    saved = test.db('SELECT * FROM tasks WHERE group_id = ?', [group_a])['rows'][0]
    task = int(saved['id'])
    assert saved['priority'] == 'none' and saved['target_date'] is None and saved['assigned_to'] is None
    assert int(saved['group_id']) == group_a and int(saved['created_by']) == ids['one']
    values = {'action': 'update', 'id': task, 'title': 'Plan feeding', 'description': 'Prepare syrup',
        'priority': 'high', 'target_date': '2030-09-15', 'assigned_to': ids['one']}
    assert 'Task saved' in two.post('tasks.php', values)
    assert int(test.row('tasks', task)['assigned_to']) == ids['one']
    assert 'Task saved' in one.post('tasks.php', dict(values, assigned_to=ids['two']))
    assert int(test.row('tasks', task)['assigned_to']) == ids['two']
    assert 'Task saved' in two.post('tasks.php', dict(values, assigned_to=''))
    assert test.row('tasks', task)['assigned_to'] is None
    assert 'task’s group' in one.post('tasks.php', dict(values, assigned_to=ids['foreign']))
    assert 'valid target completion date' in one.post('tasks.php', dict(values, target_date='2030-02-30'))
    assert 'valid priority' in one.post('tasks.php', dict(values, priority='urgent'))
    assert 'must belong to a group' in admin.post('tasks.php', {'action': 'add', 'title': 'No group'})
    assert 'task’s group' in admin.post('tasks.php', dict(values, group_id=group_a, assigned_to=ids['foreign']))
    assert 'do not manage' in foreign.request(f'tasks.php?edit={task}')
    for action in ['update', 'complete', 'reopen', 'delete']:
        assert 'do not manage' in foreign.post('tasks.php', dict(values, action=action), expected=403)
    before = test.row('tasks', task)
    one.request('tasks.php', {'action': 'complete', 'id': task, 'csrf': 'bad'}, expected=400)
    assert test.row('tasks', task) == before
    assert 'Task removed' in one.post('tasks.php', {'action': 'delete', 'id': task})
    assert not test.db('SELECT id FROM tasks WHERE id = ?', [task])['rows']
    print('PASS: task add/edit/delete, optional defaults, future planning, any-member reassignment, validation and permissions')

    expected = []
    def add(title, assignee=None, target=None, priority='none', completed=None, group=group_a):
        return test.insert('tasks', {'group_id': group, 'title': test.PREFIX + '_' + title,
            'assigned_to': assignee, 'target_date': target, 'priority': priority, 'completed_at': completed,
            'completed_by': ids['two'] if completed else None,
            'completed_by_name': 'Two Beekeeper' if completed else None})

    for label, assignee in [('mine', ids['one']), ('unassigned', None), ('other', ids['two'])]:
        undated_none = add(label + '_none', assignee)
        dated_late = add(label + '_dated_late', assignee, '2031-05-20', 'high')
        undated_low = add(label + '_low', assignee, priority='low')
        dated_early = add(label + '_dated_early', assignee, '2031-05-01', 'low')
        undated_high = add(label + '_high', assignee, priority='high')
        undated_medium = add(label + '_medium', assignee, priority='medium')
        expected.extend([dated_early, dated_late, undated_high, undated_medium, undated_low, undated_none])
    done_old = add('done_old', ids['one'], '2020-01-01', 'high', '2025-01-01 10:00:00')
    done_new = add('done_new', None, completed='2025-02-01 10:00:00')
    expected.extend([done_new, done_old])
    hidden = add('foreign', ids['foreign'], group=group_b)
    page = one.request('tasks.php')
    assert task_ids(page) == expected, (task_ids(page), expected)
    assert hidden not in task_ids(page)
    assert 'Tasks' in one.request('index.php')
    print('PASS: exact assignment/date/priority ordering and newest-first completed tasks')

    completion = add('completion', ids['one'])
    assert 'Task completed' in two.post('tasks.php', {'action': 'complete', 'id': completion})
    completed = test.row('tasks', completion)
    assert int(completed['completed_by']) == ids['two'] and completed['completed_at'] is not None
    assert 'Two Beekeeper' in completed['completed_by_name']
    assert 'already completed' in one.post('tasks.php', {'action': 'complete', 'id': completion})
    assert test.row('tasks', completion)['completed_by'] == completed['completed_by']
    assert test.row('tasks', completion)['completed_at'] == completed['completed_at']
    assert 'Task saved' in one.post('tasks.php', {'action': 'update', 'id': completion, 'title': 'Edited after completion', 'priority': 'low'})
    assert test.row('tasks', completion)['completed_by_name'] == completed['completed_by_name']
    assert 'Task reopened' in one.post('tasks.php', {'action': 'reopen', 'id': completion})
    reopened = test.row('tasks', completion)
    assert reopened['completed_at'] is None and reopened['completed_by'] is None and reopened['completed_by_name'] is None
    assert 'Task completed' in two.post('tasks.php', {'action': 'complete', 'id': completion})

    assigned = add('membership_move', ids['two'])
    moved = admin.post('users.php', {'action': 'update', 'id': ids['two'], 'username': test.PREFIX + '_two',
        'role': 'user', 'group_id': group_b, 'password': '', 'confirm': ''})
    assert 'User updated' in moved
    assert test.row('tasks', assigned)['assigned_to'] is None
    assert 'Two Beekeeper' in test.row('tasks', completion)['completed_by_name']
    assert assigned not in task_ids(two.request('tasks.php'))
    assert 'User removed' in admin.post('users.php', {'action': 'delete', 'id': ids['two']})
    assert test.row('tasks', completion)['completed_by'] is None
    assert 'Two Beekeeper' in test.row('tasks', completion)['completed_by_name']
    print('PASS: actual completer attribution, idempotent completion, reopening and retained completion history')

    add('group_retained', group=group_empty)
    assert 'Group not removed' in admin.post('groups.php', {'action': 'delete', 'id': group_empty})
    assert test.row('groups', group_empty)

    apiary = test.insert('apiaries', {'name': test.PREFIX + '_yard', 'location': 'Test', 'group_id': group_a})
    hive = test.insert('hives', {'name': test.PREFIX + '_hive', 'apiary_id': apiary, 'group_id': group_a})
    colony = test.insert('colonies', {'name': test.PREFIX + '_colony', 'hive_id': hive, 'date_of_birth': '2025-01-01', 'group_id': group_a})
    hive_b = test.insert('hives', {'name': test.PREFIX + '_foreign_hive', 'group_id': group_b})
    colony_b = test.insert('colonies', {'name': test.PREFIX + '_foreign_colony', 'hive_id': hive_b, 'date_of_birth': '2025-01-01', 'group_id': group_b})
    inspection = test.insert('inspections', {'colony_id': colony, 'user_id': ids['one'], 'visited_at': '2030-06-01 10:00:00',
        'status': 'watch', 'strength': 'strong', 'temperament': 'calm', 'supers': 2, 'population': 'high',
        'laying_pattern': 'uniform', 'queen_seen': 1, 'eggs': 1, 'action': 'Recheck food', 'notes': 'Latest visible observation'})
    test.insert('inspections', {'colony_id': colony, 'visited_at': '2029-06-01 10:00:00', 'notes': 'Older visit entered later'})
    test.insert('inspections', {'colony_id': colony_b, 'visited_at': '2040-06-01 10:00:00', 'notes': 'Foreign observation'})
    dashboard = one.request('index.php')
    summary = re.search(r'<div class="card" id="latest-inspection">(.*?)</div>\s*<div class="card">', dashboard, re.S).group(1)
    for text in [test.PREFIX + '_colony', 'Latest visible observation', 'Strong', 'Calm', 'Queen seen', 'Recheck food', '#inspection-' + str(inspection)]:
        assert text in summary
    assert 'Foreign observation' not in summary and 'Older visit entered later' not in summary
    assert f'id="inspection-{inspection}"' in one.request(f'inspections.php?colony={colony}')
    counts = test.db('''SELECT COUNT(CASE WHEN completed_at IS NULL THEN 1 END) AS open_count,
        COUNT(CASE WHEN completed_at IS NOT NULL THEN 1 END) AS completed_count FROM tasks WHERE group_id = ?''', [group_a])['rows'][0]
    task_panel = dashboard.split('id="dashboard-tasks"', 1)[1].split('</div>', 1)[0]
    assert f'<strong>{counts["open_count"]}</strong> open' in task_panel
    assert f'<strong>{counts["completed_count"]}</strong> completed' in task_panel
    print('PASS: latest visible inspection by visit date, direct history link, live task counts and protected group deletion')


if __name__ == '__main__':
    try:
        run()
    finally:
        test.cleanup()
