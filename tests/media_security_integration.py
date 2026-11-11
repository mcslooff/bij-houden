import concurrent.futures
import fcntl
import io
import json
import os
import re
import secrets
import struct
import subprocess
import tempfile
import time
from pathlib import Path
import urllib.error
import urllib.request
import zlib
from unittest.mock import patch

from PIL import Image, ImageDraw

import integration as test
import group_owner_labels_integration as logos
import consistency_integration as parallel


def multipart(browser, page, field, values, data, filename='upload.png', expected=200):
    form_path = page.split('?')[0] + '?edit=' + str(values['id']) if values.get('action') == 'update' else page
    form = browser.request(form_path)
    csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', form).group(1)
    values = dict(values)
    revision = re.search(r'name="revision" value="([a-f0-9]+)"', form)
    if revision:
        values['revision'] = revision.group(1)
    boundary = 'media-' + secrets.token_hex(12)
    parts = []
    for name, value in dict(values, csrf=csrf).items():
        parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{name}"\r\n\r\n{value}\r\n'.encode())
    parts.append(f'--{boundary}\r\nContent-Disposition: form-data; name="{field}"; filename="{filename}"\r\nContent-Type: image/png\r\n\r\n'.encode() + data + b'\r\n')
    parts.append(f'--{boundary}--\r\n'.encode())
    response = request(browser, page, b''.join(parts), {'Content-Type': 'multipart/form-data; boundary=' + boundary}, expected)
    return response, response.read().decode()


def request(browser, path, data=None, headers=None, expected=200):
    try:
        response = browser.client.open(urllib.request.Request(test.BASE + '/' + path, data=data, headers=headers or {}))
    except urllib.error.HTTPError as error:
        response = error
    assert response.status == expected, (path, response.status, expected)
    return response


def png(width=32, height=32, marker=None, header_only=False):
    def chunk(kind, data):
        import binascii
        return struct.pack('!I', len(data)) + kind + data + struct.pack('!I', binascii.crc32(kind + data) & 0xffffffff)
    raw = b'\x00' if header_only else b''.join(b'\x00' + bytes([240, 170, 12]) * width for _ in range(height))
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('!IIBBBBB', width, height, 8, 2, 0, 0, 0))
        + (chunk(b'tEXt', b'Comment\x00' + marker.encode()) if marker else b'')
        + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


def maintenance(*args):
    result = subprocess.run(['php', *args], cwd=test.ROOT, text=True, capture_output=True)
    return result, json.loads(result.stdout)


def run():
    hashed = subprocess.check_output(['php', '-r', 'require "db.php"; print hash_user_password($argv[1]);', test.PASSWORD], cwd=test.ROOT, text=True)
    group = test.insert('groups', {'name': test.PREFIX + '_brand', 'owner_label_token': secrets.token_hex(16)})
    other_group = test.insert('groups', {'name': test.PREFIX + '_other', 'owner_label_token': secrets.token_hex(16)})
    test.GROUP_IDS.extend([group, other_group])
    ids, browsers = {}, {}
    for name, role, gid in [('admin', 'admin', None), ('owner', 'user', group), ('peer', 'user', group), ('foreign', 'user', other_group), ('admin2', 'admin', None)]:
        ids[name] = test.insert('users', {'username': test.PREFIX + '_' + name, 'password_hash': hashed, 'role': role, 'group_id': gid})
        test.USER_IDS.append(ids[name])
        browser = test.Browser(); browser.login(test.PREFIX + '_' + name); browsers[name] = browser
    owner, admin = browsers['owner'], browsers['admin']
    anonymous = test.Browser()
    marker = 'private-metadata-' + secrets.token_hex(8)
    payload = png(1400, 700, marker) + b'<?php echoed_secret_payload(); ?>'
    _, body = multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, payload)
    assert 'Photo updated' in body
    avatar = test.row('users', ids['owner'])['photo_path']
    assert '-v1-' in avatar
    data = request(owner, avatar).read()
    picture = Image.open(io.BytesIO(data)); assert picture.size == (512, 256)
    assert marker.encode() not in data and b'<?php' not in data
    assert request(owner, avatar).headers['Cache-Control'] == 'private, no-store'
    request(admin, avatar)
    for viewer in [anonymous, browsers['peer'], browsers['foreign']]: request(viewer, avatar, expected=404)
    print('PASS: avatar resizing/re-encoding, metadata/payload stripping and owner/admin-only access')

    group_values = {'action': 'update', 'id': group, 'name': test.PREFIX + '_brand', 'owner_name': 'Public beekeeper'}
    image = Image.new('RGBA', (64, 64), (0, 0, 0, 0)); ImageDraw.Draw(image).rectangle((16, 16, 48, 48), fill=(240, 170, 12, 255))
    encoded = io.BytesIO(); image.save(encoded, 'PNG')
    _, body = multipart(admin, 'groups.php', 'logo', group_values, encoded.getvalue())
    assert 'Group updated' in body
    logo = test.row('groups', group)['logo_path']
    response = request(anonymous, logo)
    assert response.headers['Cache-Control'] == 'no-cache' and response.headers.get('Set-Cookie') is None
    pixels = Image.open(io.BytesIO(response.read())).convert('RGBA')
    assert pixels.getpixel((0, 0))[3] == 0 and pixels.getpixel((32, 32))[3] == 255
    jpeg = Image.new('RGB', (20, 40), 'gold'); exif = Image.Exif(); exif[274] = 6; exif[270] = marker
    encoded = io.BytesIO(); jpeg.save(encoded, 'JPEG', exif=exif)
    multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, encoded.getvalue(), 'camera.jpg')
    avatar = test.row('users', ids['owner'])['photo_path']
    data = request(owner, avatar).read(); normalized = Image.open(io.BytesIO(data))
    assert normalized.size == (40, 20) and not normalized.getexif() and marker.encode() not in data and b'CREATOR:' not in data
    for format in ['GIF', 'WEBP']:
        encoded = io.BytesIO()
        if format == 'GIF':
            Image.new('RGB', (32, 32), 'gold').save(encoded, 'GIF', save_all=True, append_images=[Image.new('RGB', (32, 32), 'green')], duration=100, loop=0)
        else:
            Image.new('RGB', (32, 32), 'gold').save(encoded, 'WEBP')
        multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, encoded.getvalue(), 'image.' + format.lower())
        avatar = test.row('users', ids['owner'])['photo_path']
        assert avatar.endswith('.png') and Image.open(io.BytesIO(request(owner, avatar).read())).format == 'PNG'
    print('PASS: public transparent logos, JPEG orientation/EXIF removal and static GIF/WebP normalization')

    old = test.row('users', ids['owner'])['photo_path']
    for invalid, text in [(b'not an image', 'valid JPEG'), (png(5000, 1, header_only=True), 'dimensions are too large'),
                          (png(2500, 2500, header_only=True), 'dimensions are too large'), (png(32, 32, header_only=True), 'valid JPEG'),
                          (b'x' * (3 * 1024 * 1024), 'Maximum size is 2 MB')]:
        _, body = multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, invalid)
        assert text in body and test.row('users', ids['owner'])['photo_path'] == old
    response, body = multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, b'x' * (9 * 1024 * 1024), expected=413)
    # nginx can enforce the same 8 MiB cap before PHP renders its own message.
    nginx_rejection = (response.headers.get('Server', '').lower() == 'nginx'
                       and '413 Request Entity Too Large' in body)
    assert '8 MB' in body or nginx_rejection, 'Expected PHP or nginx request-size rejection'
    assert test.row('users', ids['owner'])['photo_path'] == old, 'Oversized upload changed the current image'
    before = sorted(str(path) for path in test.image_file(old).parent.iterdir())
    with patch.dict(os.environ, {'BIJHOUDEN_IMAGE_STORAGE_QUOTA_BYTES': '1'}):
        with parallel.parallel_server(force_cli=True):
            quota_owner = test.Browser()
            quota_owner.login(test.PREFIX + '_owner')
            _, body = multipart(quota_owner, 'profile.php', 'photo', {'action': 'photo'}, png())
            assert 'Image storage is full' in body
    assert before == sorted(str(path) for path in test.image_file(old).parent.iterdir())
    with patch.dict(os.environ, {'BIJHOUDEN_IMAGE_STORAGE_MAX_FILES': '1'}):
        with parallel.parallel_server(force_cli=True):
            quota_owner = test.Browser()
            quota_owner.login(test.PREFIX + '_owner')
            _, body = multipart(quota_owner, 'profile.php', 'photo', {'action': 'photo'}, png())
            assert 'Image storage is full' in body
    assert test.row('users', ids['owner'])['photo_path'] == old
    print('PASS: corrupt/oversized/pixel-bomb rejection, request limits, and byte/file quotas preserve the current image')

    lease = open(test.image_file(old), 'rb'); fcntl.flock(lease, fcntl.LOCK_EX)
    try:
        multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, png())
        assert test.row('users', ids['owner'])['photo_path'] != old
        assert test.image_file(old).exists() and test.db('SELECT path FROM image_cleanup_queue WHERE path = ?', [old])['rows']
        request(owner, old, expected=404)
        result, counts = maintenance('bin/cleanup-images.php')
        assert result.returncode == 1 and counts['deferred'] >= 1
    finally:
        lease.close()
    result, _ = maintenance('bin/cleanup-images.php'); assert result.returncode == 0 and not test.image_file(old).exists()
    current = test.row('users', ids['owner'])['photo_path']
    original_bytes = test.image_file(current).read_bytes()
    parallel.trigger(fail=True)
    try:
        response, body = multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, png(50, 50), expected=500)
        request_id = response.headers['X-Request-ID']
        assert 'SQLSTATE' not in body and test.row('users', ids['owner'])['photo_path'] == current
        assert test.image_file(current).read_bytes() == original_bytes
        logs = Path(test.CONFIG_INFO['diagnostic_log']).read_text()
        assert request_id in logs and test.PASSWORD not in logs and marker not in logs and 'test audit failure' not in logs
        assert all('message' not in json.loads(line) for line in logs.splitlines())
        assert Path(test.CONFIG_INFO['diagnostic_log']).stat().st_mode & 0o777 == 0o600
        assert Path(test.CONFIG_INFO['diagnostic_log']).parent.stat().st_mode & 0o777 == 0o700
    finally:
        parallel.trigger()
    mutex = open(Path(test.CONFIG_INFO['image_root']) / '.image-storage.lock', 'r+b')
    fcntl.flock(mutex, fcntl.LOCK_EX)
    try:
        started = time.monotonic()
        assert 'Photo removed' in owner.post('profile.php', {'action': 'photo', 'remove_photo': 1})
        assert time.monotonic() - started < 1.5
        assert test.row('users', ids['owner'])['photo_path'] is None and test.image_file(current).exists()
        assert test.db('SELECT path FROM image_cleanup_queue WHERE path = ?', [current])['rows']
    finally:
        mutex.close()
    maintenance('bin/cleanup-images.php')
    assert not test.image_file(current).exists()
    multipart(owner, 'profile.php', 'photo', {'action': 'photo'}, png())
    current = test.row('users', ids['owner'])['photo_path']
    print('PASS: durable deferred cleanup and audit rollback retain active files; private diagnostics redact sensitive values')

    legacy = 'uploads/group-logos/' + secrets.token_hex(16) + '.png'
    test.image_file(legacy).write_bytes(png(marker=marker) + b'<?php secret(); ?>')
    test.db('UPDATE `groups` SET logo_path = ? WHERE id = ?', [legacy, other_group])
    sanitized = request(anonymous, legacy).read(); assert marker.encode() not in sanitized and b'<?php' not in sanitized
    result, counts = maintenance('bin/normalize-images.php', '--apply')
    assert result.returncode == 0 and counts['legacy'] == 1
    fresh = test.row('groups', other_group)['logo_path']
    assert '/v1-' in fresh and fresh != legacy and not test.image_file(legacy).exists()
    orphan = 'uploads/avatars/u' + str(ids['owner']) + '-v1-' + secrets.token_hex(16) + '.png'
    test.image_file(orphan).write_bytes(png()); os.utime(test.image_file(orphan), (time.time() - 90000,) * 2)
    lease = open(test.image_file(orphan), 'rb'); fcntl.flock(lease, fcntl.LOCK_EX)
    try:
        _, counts = maintenance('bin/cleanup-images.php', '--orphans')
        assert counts['deferred'] >= 1 and test.image_file(orphan).exists()
    finally:
        lease.close()
    maintenance('bin/cleanup-images.php', '--orphans')
    assert not test.image_file(orphan).exists() and test.image_file(current).exists()
    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        outside = os.path.join(directory, 'private.txt'); Path(outside).write_text(marker)
        link = 'uploads/avatars/u' + str(ids['owner']) + '-v1-' + secrets.token_hex(16) + '.png'
        os.symlink(outside, test.image_file(link))
        try:
            test.db('UPDATE users SET photo_path = ? WHERE id = ?', [link, ids['owner']])
            request(owner, link, expected=404)
            assert Path(outside).read_text() == marker
        finally:
            test.db('UPDATE users SET photo_path = ? WHERE id = ?', [current, ids['owner']])
            test.image_file(link).unlink()
    print('PASS: legacy normalization, orphan leases/reference checks and symlink-safe image serving')

    with tempfile.NamedTemporaryFile(dir=test.TEMP_ROOT) as source:
        source.write(png()); source.flush()
        info = subprocess.check_output(['php', '-r', '''require "db.php";
            [$bytes] = normalize_image_bytes($argv[1], 'logo');
            print json_encode(['encoded' => strlen($bytes), 'usage' => image_storage_usage()]);''', source.name], cwd=test.ROOT, text=True)
    info = json.loads(info)
    budget = info['usage']['bytes'] + info['encoded']
    quota_names = [test.PREFIX + '_quota_one', test.PREFIX + '_quota_two']
    with patch.dict(os.environ, {'BIJHOUDEN_IMAGE_STORAGE_QUOTA_BYTES': str(budget)}):
        with parallel.parallel_server(force_cli=True):
            # The quota-specific server has a different origin/protocol. Obtain
            # fresh cookies rather than replaying HTTPS host-only cookies there.
            quota_admin, quota_admin2 = test.Browser(), test.Browser()
            quota_admin.login(test.PREFIX + '_admin')
            quota_admin2.login(test.PREFIX + '_admin2')
            parallel.trigger(delay='group_added')
            try:
                with concurrent.futures.ThreadPoolExecutor(max_workers=2) as pool:
                    first = pool.submit(multipart, quota_admin, 'groups.php', 'logo', {'action': 'add', 'name': quota_names[0]}, png())
                    parallel.wait_for_database_state('sleep', first)
                    second = pool.submit(multipart, quota_admin2, 'groups.php', 'logo', {'action': 'add', 'name': quota_names[1]}, png())
                    assert 'Group added' in first.result(timeout=15)[1]
                    assert 'Image storage is full' in second.result(timeout=15)[1]
            finally:
                parallel.trigger()
                for row in test.db('SELECT id FROM `groups` WHERE name IN (?, ?)', quota_names)['rows']:
                    if int(row['id']) not in test.GROUP_IDS: test.GROUP_IDS.append(int(row['id']))
    created = test.db('SELECT id FROM `groups` WHERE name IN (?, ?)', quota_names)['rows']
    assert len(created) == 1
    usage = subprocess.check_output(['php', '-r', 'require "db.php"; print json_encode(image_storage_usage());'], cwd=test.ROOT, text=True)
    assert json.loads(usage)['bytes'] <= budget
    print('PASS: concurrent uploads count pending leased files and cannot exceed the shared storage quota')

    for path in ['login.php', 'style.css', current, logo, 'schema.sql']:
        response = request(owner, path, expected=404 if path == 'schema.sql' else 200)
        csp = response.headers['Content-Security-Policy']
        assert "script-src 'self'" in csp and "style-src-attr 'none'" in csp and 'unsafe-inline' not in csp
        assert response.headers['X-Frame-Options'] == 'DENY' and response.headers['Referrer-Policy'] == 'no-referrer'
        assert response.headers.get('X-Powered-By') is None
    print('PASS: enforced CSP, anti-framing, privacy headers and PHP version suppression across pages/assets/images/errors')


if __name__ == '__main__':
    try:
        run()
    finally:
        parallel.trigger()
        test.cleanup()
