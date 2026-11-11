import binascii
import re
import secrets
import struct
import subprocess
import urllib.error
import urllib.request
import zlib

import integration as test


def sample_png():
    def chunk(kind, data):
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', binascii.crc32(kind + data) & 0xffffffff)
    pixels = b''.join(b'\x00' + bytes([240, 170, 12]) * 32 for _ in range(32))
    return b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!IIBBBBB', 32, 32, 8, 2, 0, 0, 0)) + chunk(b'IDAT', zlib.compress(pixels)) + chunk(b'IEND', b'')


def upload(browser, values, data=None, filename='logo.png', mime='image/png'):
    form = browser.request('groups.php' + ('?edit=' + str(values['id']) if values.get('action') == 'update' else ''))
    token = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
    values = dict(values)
    revision = re.search(r'name="revision" value="([a-f0-9]+)"', form)
    if revision:
        values['revision'] = revision.group(1)
    boundary = 'bijhouden-' + secrets.token_hex(12)
    parts = []
    for name, value in dict(values, csrf=token).items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    if data is not None:
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="logo"; filename="{filename}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + data + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    request = urllib.request.Request(test.BASE + '/groups.php', data=b''.join(parts),
        headers={'Content-Type': 'multipart/form-data; boundary=' + boundary})
    response = browser.client.open(request)
    assert response.code == 200
    return response.read().decode()


def run():
    hashed = subprocess.check_output(['php', '-r', 'print password_hash($argv[1], PASSWORD_DEFAULT);', test.PASSWORD], text=True)
    admin_id = test.insert('users', {'username': test.PREFIX + '_admin', 'role': 'admin', 'password_hash': hashed})
    test.USER_IDS.append(admin_id)
    admin = test.Browser()
    admin.login(test.PREFIX + '_admin')
    details = {'name': test.PREFIX + '_brand', 'owner_name': 'Jan & Léonie <Beekeeper>',
        'owner_address': 'Bee Lane 12\n1234 AB Apiary Town', 'owner_phone': '+31 (0)6 12345678', 'owner_email': 'beekeeper@example.com'}
    result = upload(admin, dict(details, action='add'), sample_png())
    group = test.db('SELECT * FROM `groups` WHERE name = ?', [details['name']])['rows'][0]
    group_id = int(group['id'])
    test.GROUP_IDS.append(group_id)
    assert 'Group added' in result
    assert group['owner_name'] == details['owner_name'] and group['owner_phone'] == details['owner_phone']
    assert group['owner_email'] == details['owner_email'] and group['owner_address'] == details['owner_address']
    assert re.fullmatch(r'[a-f0-9]{32}', group['owner_label_token'])
    assert test.image_file(group['logo_path']).is_file()
    original_path = group['logo_path']
    token = group['owner_label_token']

    other_group = test.insert('groups', {'name': test.PREFIX + '_other'})
    test.GROUP_IDS.append(other_group)
    for name, gid in [('member', group_id), ('foreign', other_group)]:
        test.USER_IDS.append(test.insert('users', {'username': test.PREFIX + '_' + name, 'group_id': gid, 'password_hash': hashed}))
    member = test.Browser()
    member.login(test.PREFIX + '_member')
    foreign = test.Browser()
    foreign.login(test.PREFIX + '_foreign')
    hive_id = test.insert('hives', {'name': test.PREFIX + '_hive', 'group_id': group_id, 'notes': 'Internal hive notes'})
    assert 'Print owner label' in member.request('hives.php')
    assert token in member.request('hives.php')
    assert token not in foreign.request('hives.php')
    assert 'Only administrators' in member.request('groups.php')

    anonymous = test.Browser()
    path = 'owner_label.php?token=' + token
    label = anonymous.request(path)
    assert 'Jan &amp; Léonie &lt;Beekeeper&gt;' in label and details['owner_email'] in label
    assert details['owner_address'].split('\n')[0] in label and '<br' in label
    assert group['logo_path'] in label and 'id="owner-qr"' in label
    assert test.BASE + '/' + path in label
    assert 'Internal hive notes' not in label and test.PREFIX + '_member' not in label
    assert '<form' not in label and 'sidebar' not in label
    anonymous.request(path, {'owner_name': 'Changed anonymously'}, expected=405)
    assert test.row('groups', group_id)['owner_name'] == details['owner_name']
    for invalid in ['', 'not-a-token', '0' * 32, '../groups.php']:
        anonymous.request('owner_label.php?token=' + invalid, expected=404)
    anonymous.request('owner_label.php?group_id=' + str(group_id), expected=404)
    print('PASS: group contact/logo saving, hive label links, anonymous read-only access and scoped management')

    invalid = upload(admin, dict(details, action='update', id=group_id), b'not an image')
    assert 'valid JPEG' in invalid and test.row('groups', group_id)['logo_path'] == original_path
    oversized = upload(admin, dict(details, action='update', id=group_id), b'x' * (3 * 1024 * 1024))
    assert 'Maximum size is 2 MB' in oversized and test.row('groups', group_id)['logo_path'] == original_path
    invalid_email = admin.post('groups.php', dict(details, action='update', id=group_id, owner_email='bad email'))
    assert 'valid owner/contact e-mail' in invalid_email
    assert 'value="Jan &amp; Léonie &lt;Beekeeper&gt;"' in invalid_email
    assert 'Group updated' in upload(admin, dict(details, action='update', id=group_id), sample_png())
    replaced = test.row('groups', group_id)
    assert replaced['logo_path'] != original_path and not test.image_file(original_path).exists()
    assert replaced['owner_label_token'] == token
    assert 'Group updated' in admin.post('groups.php', dict(details, action='update', id=group_id, remove_logo=1))
    assert test.row('groups', group_id)['logo_path'] is None and not test.image_file(replaced['logo_path']).exists()
    assert 'Group updated' in admin.post('groups.php', {'action': 'update', 'id': group_id, 'name': details['name']})
    cleared = test.row('groups', group_id)
    assert all(cleared[key] is None for key in ['owner_name', 'owner_address', 'owner_phone', 'owner_email'])
    assert 'Not provided' in anonymous.request(path)
    print('PASS: 2 MB/image validation, safe replacement/removal, optional contacts and stable public links')

    assert 'Group added' in upload(admin, {'action': 'add', 'name': test.PREFIX + '_empty'}, sample_png())
    empty = test.db('SELECT * FROM `groups` WHERE name = ?', [test.PREFIX + '_empty'])['rows'][0]
    test.GROUP_IDS.append(int(empty['id']))
    assert test.image_file(empty['logo_path']).exists()
    assert 'Empty group removed' in admin.post('groups.php', {'action': 'delete', 'id': empty['id']})
    assert not test.image_file(empty['logo_path']).exists()
    anonymous.request('owner_label.php?token=' + empty['owner_label_token'], expected=404)
    configured = subprocess.check_output(['php', '-r', '''require "group_helpers.php";
        putenv("BIJHOUDEN_PUBLIC_URL=https://bees.example.com/app");
        print owner_label_absolute_url($argv[1]);''', token], cwd=test.ROOT, text=True)
    assert configured == 'https://bees.example.com/app/' + path
    print('PASS: deleted-group logo cleanup and configured public URL generation')


def cleanup():
    test.cleanup()


if __name__ == '__main__':
    try:
        run()
    finally:
        cleanup()
