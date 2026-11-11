"""Render and syntax-test the isolated stack using real binaries and temporary keys."""
import grp
import importlib.util
import os
from pathlib import Path
import pwd
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/pi'))
import prepare_stage as stage
import prepare_tls as tls


def run():
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as temporary:
        folder = Path(temporary)
        pki = folder / 'pki'
        tls.create_pki(pki, b'ephemeral-stack-test-passphrase')
        for name in ['logs', 'release/public', 'state/sessions', 'state/tmp', 'nginx-run', 'fpm-run']:
            (folder / name).mkdir(parents=True, exist_ok=True)
        values = {'RELEASE': folder / 'release', 'PKI': pki, 'STATE': folder / 'state', 'LOGS': folder / 'logs'}
        templates = ROOT / 'deployment/pi'
        nginx = stage.render(templates / 'stage-nginx.conf.in', values)
        nginx = nginx.replace('/run/bijhouden-stage-nginx', str(folder / 'nginx-run'))
        nginx = nginx.replace('/run/bijhouden-stage-fpm', str(folder / 'fpm-run'))
        (folder / 'nginx.conf').write_text(nginx)
        result = subprocess.run(['/usr/sbin/nginx', '-t', '-e', str(folder / 'nginx-startup.log'), '-c', str(folder / 'nginx.conf')],
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        fpm = stage.render(templates / 'stage-fpm.conf.in', values)
        fpm = fpm.replace('user = bijhouden-stage', 'user = ' + pwd.getpwuid(os.getuid()).pw_name)
        fpm = fpm.replace('group = bijhouden-stage', 'group = ' + grp.getgrgid(os.getgid()).gr_name)
        fpm = fpm.replace('/run/bijhouden-stage-fpm', str(folder / 'fpm-run'))
        (folder / 'fpm.conf').write_text(fpm)
        result = subprocess.run(['/usr/sbin/php-fpm8.4', '--test', '--fpm-config', str(folder / 'fpm.conf')],
                                capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        units = []
        for source, name in [('stage-fpm.service.in', 'bijhouden-stage-fpm.service'),
                             ('stage-nginx.service.in', 'bijhouden-stage-nginx.service')]:
            path = folder / name
            path.write_text((templates / source).read_text())
            units.append(str(path))
        result = subprocess.run(['systemd-analyze', 'verify', '--man=no', *units], capture_output=True, text=True)
        assert result.returncode == 0, result.stderr
        assert 'security.limit_extensions = .php' in fpm and 'env[BIJHOUDEN_ENV] = test' in fpm
        assert 'SCRIPT_FILENAME ' + str(folder / 'release/public/index.php') in nginx
        assert 'ssl_reject_handshake on;' in nginx and 'deny all;' in nginx
        print('PASS: real nginx/FPM syntax and systemd unit validation with ephemeral test certificates; no services installed or started')


if __name__ == '__main__':
    run()
