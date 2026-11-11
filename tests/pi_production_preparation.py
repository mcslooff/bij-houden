"""Real config/unit syntax and grant fences, without root or service changes."""
import grp
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile
from types import SimpleNamespace
from unittest.mock import MagicMock, patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/pi'))
import prepare_production as production
import prepare_tls as tls


def run():
    account = SimpleNamespace(pw_gid=12345)
    for mode, gid in [(0o700, 0), (0o710, account.pw_gid), (0o755, 0)]:
        config = MagicMock()
        parent = config.parent
        parent.stat.return_value = SimpleNamespace(st_gid=gid, st_mode=mode)
        with patch.object(production, 'CONFIG', config), patch.object(production, 'protected'), \
                patch.object(production.os, 'chown') as chown:
            production.allow_configuration_traversal(account)
            chown.assert_called_once_with(parent, 0, account.pw_gid)
            parent.chmod.assert_called_once_with(0o710)
    for mode, gid in [(0o777, 0), (0o750, 99999)]:
        config = MagicMock()
        config.parent.stat.return_value = SimpleNamespace(st_gid=gid, st_mode=mode)
        with patch.object(production, 'CONFIG', config), patch.object(production, 'protected'), \
                patch.object(production.os, 'chown') as chown:
            try:
                production.allow_configuration_traversal(account)
            except RuntimeError:
                pass
            else:
                raise AssertionError('Unexpected config parent permission accepted')
            chown.assert_not_called()
    failure = subprocess.CalledProcessError(255, ['php'], stderr='synthetic private diagnostic')
    with patch.object(production.stage, 'run_as', side_effect=failure), \
            patch.object(production.stage, 'write_new') as write:
        try:
            production.runtime_check(account, Path('/synthetic-release'))
        except RuntimeError as error:
            assert 'private diagnostic' in str(error) and 'synthetic private diagnostic' not in str(error)
        else:
            raise AssertionError('Failed runtime check accepted')
        assert write.call_args.kwargs['mode'] == 0o600
    valid = ["GRANT USAGE ON *.* TO `app`@`localhost` IDENTIFIED BY PASSWORD 'synthetic'",
             'GRANT SELECT, INSERT, UPDATE, DELETE ON `bijhouden`.* TO `app`@`localhost`']
    assert production.dml_only(valid, 'bijhouden')
    for grants in [valid + ['GRANT PROCESS ON *.* TO `app`@`localhost`'],
                   valid + ['GRANT SELECT ON `bijhouden_test_db`.* TO `app`@`localhost`'],
                   ['GRANT ALL PRIVILEGES ON `bijhouden`.* TO `app`@`localhost`'],
                   [valid[1] + ' WITH GRANT OPTION'],
                   ['GRANT SELECT, INSERT, UPDATE, DELETE, TRIGGER ON `bijhouden`.* TO `app`@`localhost`'],
                   ['GRANT SELECT ON `bijhouden`.* TO `app`@`localhost`'],
                   ['GRANT `role_name` TO `app`@`localhost`']]:
        assert not production.dml_only(grants, 'bijhouden'), grants
    with tempfile.TemporaryDirectory(prefix='production-config-', dir='/tmp/opencode') as temporary:
        root = Path(temporary)
        pki = root / 'pki'
        tls.create_pki(pki, b'synthetic-config-test-only')
        for folder in ['release/public', 'logs', 'nginx-run', 'fpm-run']:
            (root / folder).mkdir(parents=True)
        with patch.object(production, 'LOGS', root / 'logs'), patch.object(tls, 'PKI', pki):
            nginx, fpm = production.render_configs(root / 'release')
        nginx = nginx.replace('/run/bijhouden-nginx', str(root / 'nginx-run'))
        nginx = nginx.replace('/run/bijhouden-fpm', str(root / 'fpm-run'))
        # Rootless syntax checks use high ports; the operator renders 80/443.
        nginx = nginx.replace(':80;', ':18080;').replace(':80 default_server;', ':18080 default_server;')
        nginx = nginx.replace(':443 ssl', ':14443 ssl')
        fpm = fpm.replace('/run/bijhouden-fpm', str(root / 'fpm-run'))
        fpm = fpm.replace('user = bijhouden-web', 'user = ' + pwd.getpwuid(os.getuid()).pw_name)
        fpm = fpm.replace('group = bijhouden-web', 'group = ' + grp.getgrgid(os.getgid()).gr_name)
        (root / 'nginx.conf').write_text(nginx)
        (root / 'fpm.conf').write_text(fpm)
        for command in [
            ['/usr/sbin/nginx', '-t', '-e', str(root / 'nginx-startup.log'), '-c', str(root / 'nginx.conf')],
            ['/usr/sbin/php-fpm8.4', '--test', '--fpm-config', str(root / 'fpm.conf')],
        ]:
            result = subprocess.run(command, capture_output=True, text=True)
            assert result.returncode == 0, result.stderr
        paths = []
        for template, name in [('production-fpm.service.in', 'bijhouden-fpm.service'),
                               ('production-nginx.service.in', 'bijhouden-nginx.service')]:
            path = root / name
            text = (ROOT / 'deployment/pi' / template).read_text()
            assert 'ProtectHome=true' in text and 'LimitCORE=0' in text
            assert 'RestrictAddressFamilies=AF_UNIX AF_INET' in text
            path.write_text(text)
            paths.append(str(path))
        result = subprocess.run(['systemd-analyze', 'verify', '--man=no', *paths], capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        assert 'listen [::]' not in nginx and 'deny all;' in nginx
        assert 'env[BIJHOUDEN_ENV] = production' in fpm
        assert 'env[BIJHOUDEN_PUBLIC_URL] = https://pai01.home' in fpm
        assert 'SCRIPT_FILENAME ' + str(root / 'release/public/index.php') in nginx
        assert 'ssl_reject_handshake on;' in nginx
    print('PASS: production nginx/FPM/systemd syntax with ephemeral keys; no production services/accounts installed or started')
    print('PASS: runtime SQL fence accepts only exact-database DML; rejects global/test/DDL/role/grant-option privileges')
    print('PASS: config-parent traversal is production-group-only; unknown modes/groups refused; runtime failures use private diagnostics')


if __name__ == '__main__':
    run()
