"""Filesystem maintenance checks on explicitly isolated private test paths."""
import json
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile

import integration as test


def run():
    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        env = dict(os.environ, BIJHOUDEN_DIAGNOSTIC_LOG_DIRECTORY=directory,
                   BIJHOUDEN_DIAGNOSTIC_LOG_MAX_BYTES='1024', BIJHOUDEN_DIAGNOSTIC_LOG_RETENTION='3')
        script = 'require "db.php"; for ($i=0;$i<30;$i++) report_error(new RuntimeException("SECRET NEVER LOG"), "rotation_test");'
        workers = [subprocess.Popen(['php', '-r', script], cwd=test.ROOT, env=env,
                                    stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True) for _ in range(4)]
        for worker in workers:
            out, err = worker.communicate(timeout=30)
            assert worker.returncode == 0 and not out and not err, (out, err)
        logs = sorted(Path(directory).glob('errors.jsonl*'))
        assert len(logs) == 4, logs
        for log in logs:
            assert log.stat().st_size <= 1024
            assert log.stat().st_mode & 0o077 == 0
            text = log.read_text()
            assert 'SECRET' not in text
            for line in text.splitlines():
                assert json.loads(line)['context'] == 'rotation_test'
        assert Path(directory, '.diagnostic.lock').stat().st_mode & 0o077 == 0
    print('PASS: concurrent diagnostic rotation, size/retention bounds, private modes and redaction')
    result = subprocess.run(['php', 'bin/health.php'], cwd=test.ROOT, text=True, capture_output=True)
    assert result.returncode in [0, 1], result.stderr
    health = json.loads(result.stdout)
    assert health['checks']['database'] and health['checks']['session_state']
    assert health['checks']['media_private_writable'] and health['checks']['logs_private_writable']
    assert 'password' not in result.stdout and 'session_epoch' not in result.stdout
    test.Browser().request('bin/health.php', expected=404)
    print('PASS: CLI health inventories database/storage/dependencies without exposing a public endpoint')

    holder = subprocess.Popen(['php', '-r', 'require "db.php"; $lock=application_maintenance_lock(true); echo "ready\\n"; fflush(STDOUT); fgets(STDIN);'],
                              cwd=test.ROOT, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
    try:
        assert holder.stdout.readline().strip() == 'ready'
        blocked = subprocess.run(['php', '-r', 'require "db.php"; db();'], cwd=test.ROOT, capture_output=True, text=True)
        assert blocked.returncode != 0
        test.Browser().post('login.php', {'username': 'maintenance_probe', 'password': 'Not-a-real-account'}, expected=503)
    finally:
        holder.communicate('\n', timeout=20)
    assert holder.returncode == 0
    print('PASS: exclusive maintenance barrier refuses concurrent CLI database access and HTTP login with 503')
    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        marker = Path(directory, '.restore-incomplete')
        marker.write_text('Simulated interrupted recovery')
        env = dict(os.environ, BIJHOUDEN_IMAGE_STORAGE_PATH=directory)
        blocked = subprocess.run(['php', '-r', 'require "db.php"; db();'], cwd=test.ROOT, env=env, capture_output=True, text=True)
        assert blocked.returncode != 0
        marker.unlink()
        recovered = subprocess.run(['php', '-r', 'require "db.php"; db();'], cwd=test.ROOT, env=env, capture_output=True, text=True)
        assert recovered.returncode == 0
    print('PASS: incomplete-recovery marker refuses runtime access independently of the live lock')

    with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
        root = Path(directory)
        media = root / 'media' / 'group-logos'
        media.mkdir(parents=True)
        file = media / ('a' * 32 + '.png')
        file.write_bytes(b'checksum-fixture-not-served')
        dump = root / 'database.sql'
        dump.write_text('CREATE DATABASE test;\nCREATE TABLE `example` (id INT);\n')
        meta = {'database': test.CONFIG_INFO['database'], 'created_at': '2026-01-01T00:00:00Z',
                'storage_fingerprint': test.CONFIG_INFO['storage_fingerprint'],
                'dump_sha256': hashlib.sha256(dump.read_bytes()).hexdigest(),
                'media_files': 1, 'media_bytes': file.stat().st_size,
                'media_sha256': {'group-logos/' + file.name: hashlib.sha256(file.read_bytes()).hexdigest()}}
        (root / 'meta.json').write_text(json.dumps(meta))
        def dry_run():
            return subprocess.run(['php', 'bin/restore.php', directory], cwd=test.ROOT, capture_output=True, text=True)
        valid = dry_run()
        assert valid.returncode == 0 and 'Dry run only' in valid.stdout, valid.stderr
        meta['storage_fingerprint'] = '0' * 64
        (root / 'meta.json').write_text(json.dumps(meta))
        wrong_storage = dry_run()
        assert wrong_storage.returncode == 1 and 'storage target differs' in wrong_storage.stderr
        meta['storage_fingerprint'] = test.CONFIG_INFO['storage_fingerprint']
        (root / 'meta.json').write_text(json.dumps(meta))
        file.write_bytes(b'corrupt')
        corrupt = dry_run()
        assert corrupt.returncode == 1 and 'checksum verification failed' in corrupt.stderr
        file.write_bytes(b'checksum-fixture-not-served')
        (media / ('b' * 32 + '.png')).write_bytes(b'extra')
        extra = dry_run()
        assert extra.returncode == 1 and 'not covered' in extra.stderr
    print('PASS: nonempty backup manifest dry-run, corrupted media and untracked media refused before restore')


if __name__ == '__main__':
    run()
