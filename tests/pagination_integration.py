"""Entity pagination regression tests; inherits the isolated-target guards."""
import re
import subprocess

import integration as test


def run():
    groups = []
    user = None
    extra_users = []
    try:
        group = test.insert('groups', {'name': test.PREFIX + '_pages'})
        other = test.insert('groups', {'name': test.PREFIX + '_other'})
        groups.extend([group, other])
        hashed = subprocess.check_output(
            ['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
        username = test.PREFIX + '_pager'
        user = test.insert('users', {'username': username, 'password_hash': hashed, 'group_id': group})
        for number in range(51):
            name = test.PREFIX + f'_item_{number:03}'
            test.insert('apiaries', {'name': name, 'location': 'Pagination test', 'group_id': group})
            hive = test.insert('hives', {'name': name, 'group_id': group})
            test.insert('queens', {'name': name, 'group_id': group})
            colony = test.insert('colonies', {'name': name, 'hive_id': hive, 'group_id': group,
                                              'date_of_birth': '2026-01-01'})
            if number == 0:
                history_colony = colony
            test.insert('tasks', {'title': name, 'group_id': group,
                                  'assigned_to': user if number < 50 else None})
            test.insert('inspections', {'colony_id': history_colony, 'visited_at': '2026-01-01 12:00:00'})
        hidden = test.PREFIX + '_hidden'
        test.insert('apiaries', {'name': hidden, 'location': 'Other group', 'group_id': other})
        browser = test.Browser()
        browser.login(username)
        for entity in ['apiaries', 'hives', 'queens', 'colonies']:
            first = browser.request(entity + '.php')
            second = browser.request(entity + '.php?page=2')
            # Inspect table rows only: relationship dropdowns intentionally remain complete.
            rows = lambda html: re.search(r'<tbody>(.*?)</tbody>', html, re.S).group(1)
            assert rows(first).count('<tr>') == 50, entity
            assert rows(second).count('<tr>') == 1, entity
            assert '_item_050' not in rows(first) and '_item_050' in rows(second), entity
            assert hidden not in first + second
            assert 'rel="next"' in first and 'rel="prev"' in second
            assert rows(browser.request(entity + '.php?page=999999')) == rows(second)
            for bad in ['0', '-1', '1.5', 'abc', '4294967296']:
                browser.request(entity + '.php?page=' + bad, expected=400)
        test.db('UPDATE queens SET archived_at = NOW() WHERE group_id = ?', [group])
        archived = browser.request('queens.php?archived=1')
        assert 'archived=1&amp;page=2' in archived
        assert '_item_050' in browser.request('queens.php?archived=1&page=2')
        first_tasks = browser.request('tasks.php')
        second_tasks = browser.request('tasks.php?page=2')
        assert first_tasks.count('data-task-id=') == 50
        assert second_tasks.count('data-task-id=') == 1
        assert '_item_050' not in first_tasks and '_item_050' in second_tasks
        history_url = f'inspections.php?colony={history_colony}'
        history = browser.request(history_url)
        assert history.count('class="inspection-entry"') == 50
        assert f'colony={history_colony}&amp;page=2' in history
        assert browser.request(history_url + '&page=2').count('class="inspection-entry"') == 1
        assert browser.request('inspections.php').count('class="inspection-entry"') == 12
        for number in range(51):
            extra_group = test.insert('groups', {'name': test.PREFIX + f'_admin_group_{number:03}'})
            groups.append(extra_group)
            extra_users.append(test.insert('users', {'username': test.PREFIX + f'_u{number:03}', 'password_hash': hashed}))
        admin_name = test.PREFIX + '_page_admin'
        extra_users.append(test.insert('users', {'username': admin_name, 'password_hash': hashed, 'role': 'admin'}))
        admin = test.Browser()
        admin.login(admin_name)
        for entity in ['users', 'groups']:
            first = admin.request(entity + '.php')
            assert rows(first).count('<tr>') == 50
            assert 'rel="next"' in first
            second = admin.request(entity + '.php?page=2')
            assert 0 < rows(second).count('<tr>') <= 50
        print('PASS: 50-row bounds, deterministic page boundaries, clamping, scope, archive filters and malformed pages')
        print('PASS: task assignment ordering survives page boundaries; colony history filters and 12-row recent pages')
        print('PASS: administrator user/group overviews are also bounded to 50 rows with working second pages')
    finally:
        for group in groups:
            test.db('DELETE i FROM inspections i JOIN colonies c ON c.id = i.colony_id WHERE c.group_id = ?', [group])
            test.db('DELETE FROM tasks WHERE group_id = ?', [group])
            for table in ['colonies', 'hives', 'queens', 'apiaries']:
                test.db(f'DELETE FROM {table} WHERE group_id = ?', [group])
        if user is not None:
            test.db('DELETE FROM activity_log WHERE user_id = ?', [user])
            test.db('DELETE FROM users WHERE id = ?', [user])
        for id in extra_users:
            test.db('DELETE FROM activity_log WHERE user_id = ?', [id])
            test.db('DELETE FROM users WHERE id = ?', [id])
        for group in groups:
            test.db('DELETE FROM `groups` WHERE id = ?', [group])


if __name__ == '__main__':
    run()
