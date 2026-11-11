"""Rootless synthetic DB/media restore and recovery target-fence tests.

Uses a new socket-only MariaDB data directory, never the normal/stage database.
No system account/unit is installed and no actual backup/password is needed.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import time
from unittest.mock import patch

from PIL import Image

SOURCE = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(SOURCE / 'deployment/pi'))
import rehearse_windows_recovery as recovery
from verify_stage import normal_fingerprint


def run():
    os.umask(0o077)
    normal_before = normal_fingerprint()
    with tempfile.TemporaryDirectory(prefix='recovery-selftest-', dir='/tmp/opencode') as temporary:
        root = Path(temporary)
        socket = root / 'run/db.sock'
        config_path = root / 'database.json'
        for name in ['data', 'run', 'tmp', 'logs', 'media', 'app-logs', 'backups']:
            (root / name).mkdir(mode=0o700)
        config = {'environment': 'test', 'db_host': 'localhost', 'db_port': 3306,
                  'db_socket': str(socket), 'db_name': 'bijhouden', 'db_user': 'root', 'db_password': '',
                  'image_storage_path': str(root / 'media'), 'diagnostic_log_directory': str(root / 'app-logs')}
        config_path.write_text(json.dumps(config))
        with patch.multiple(recovery, ROOT=root, SOCKET=socket, CONFIG=config_path):
            unit = root / 'recovery-selftest.service'
            unit.write_text('[Service]\n' + '\n'.join(recovery.service_properties())
                            + '\nExecStart=' + ' '.join(recovery.daemon_arguments(root)) + '\n')
            subprocess.run(['systemd-analyze', 'verify', '--man=no', str(unit)], check=True,
                           capture_output=True, text=True)
            recovery.assert_target(config)
            for key, value in [('db_socket', '/run/mysqld/mysqld.sock'),
                               ('db_host', '127.0.0.1'), ('environment', 'production'),
                               ('image_storage_path', str(SOURCE / 'uploads'))]:
                try:
                    recovery.assert_target(dict(config, **{key: value}))
                except RuntimeError:
                    pass
                else:
                    raise AssertionError('Unsafe target accepted: ' + key)
            for identity in ['/var/lib/mysql/\t/run/mysqld/mysqld.sock\t1',
                             str(root / 'data') + '\t' + str(socket) + '\t0']:
                try:
                    recovery.validate_identity(identity)
                except RuntimeError:
                    pass
                else:
                    raise AssertionError('Unsafe server identity accepted')
            # Passwordless root auth is used ONLY in this disposable rootless
            # self-test with a private socket; operator rehearsal uses UNIX auth.
            with (root / 'initialize.log').open('w') as log:
                subprocess.run(['/usr/bin/mariadb-install-db', '--no-defaults', '--basedir=/usr',
                                '--datadir=' + str(root / 'data'),
                                '--auth-root-authentication-method=normal', '--skip-test-db'],
                               check=True, stdout=log, stderr=subprocess.STDOUT, timeout=90)
            process = subprocess.Popen(recovery.daemon_arguments(root), stdout=subprocess.DEVNULL,
                                       stderr=subprocess.DEVNULL)
            try:
                for _ in range(150):
                    try:
                        identity = recovery.sql('SELECT @@datadir, @@socket, @@skip_networking')
                    except subprocess.CalledProcessError:
                        time.sleep(.1)
                    else:
                        recovery.validate_identity(identity)
                        break
                else:
                    raise AssertionError('Private self-test MariaDB failed startup')
                recovery.sql('CREATE DATABASE bijhouden')
                cli = ['/usr/bin/mariadb', '--no-defaults', '--protocol=SOCKET', '--socket=' + str(socket), '--user=root', 'bijhouden']
                with (SOURCE / 'schema.sql').open() as schema:
                    subprocess.run(cli, stdin=schema, check=True)
                (root / 'media/group-logos').mkdir(mode=0o700)
                image = root / 'media/group-logos/v1-00000000000000000000000000000001.png'
                Image.new('RGB', (8, 8), 'gold').save(image)
                recovery.sql("INSERT INTO bijhouden.groups (name,logo_path,owner_label_token) VALUES ('synthetic-recovery','uploads/group-logos/" + image.name + "','0123456789abcdef0123456789abcdef')")
                env = recovery.clean_env(config_path)
                env['BIJHOUDEN_BACKUP_DIRECTORY'] = str(root / 'backups')
                shutil.copytree(root / 'media', root / 'snapshot-media')
                env['BIJHOUDEN_IMAGE_STORAGE_PATH'] = str(root / 'snapshot-media')
                output = subprocess.check_output(['php', 'bin/backup.php'], cwd=SOURCE, env=env, text=True)
                snapshot = Path(json.loads(output)['backup'])
                meta = json.loads((snapshot / 'meta.json').read_text())
                recovery.sql("UPDATE bijhouden.groups SET name='post-backup-change',owner_label_token='ffffffffffffffffffffffffffffffff'")
                image.write_bytes(b'changed after backup')
                try:
                    recovery.private_php(['bin/restore.php', snapshot], 'refused-storage-change.log')
                except subprocess.CalledProcessError:
                    assert 'storage target differs' in (root / 'refused-storage-change.log').read_text()
                else:
                    raise AssertionError('Unapproved media relocation accepted')
                recovery.private_php(['bin/restore.php', snapshot, '--allow-storage-change'], 'dry.log')
                recovery.private_php(['bin/restore.php', snapshot, '--allow-storage-change', '--apply', '--yes'], 'apply.log')
                checks = recovery.verify_restored(snapshot, meta)
                assert checks['media_hashes_match'] and checks['session_epoch_rotated']
                recovery.private_php(['bin/check-integrity.php'], 'integrity.log')
                token = recovery.sql('SELECT owner_label_token FROM bijhouden.groups').strip()
                assert token == '0123456789abcdef0123456789abcdef'
                print('PASS: private socket-only MariaDB restore, row counts, media hashes/references, owner token, epoch rotation and integrity')
                print('PASS: normal socket/network-enabled identities and wrong recovery paths refused')
            finally:
                process.terminate()
                process.wait(timeout=30)
    assert normal_before == normal_fingerprint(), 'Normal database changed during synthetic test'
    print('PASS: normal database fingerprints unchanged; disposable DB process stopped; no accounts/services installed')


if __name__ == '__main__':
    run()
