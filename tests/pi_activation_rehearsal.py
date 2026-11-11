"""Isolated activation/rollback rehearsal: real media-copy semantics, mocked
systemd/root state. No production unit is installed, started or enabled."""
import hashlib
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/pi'))
import activate_production as activation
import prepare_production as production
import prepare_stage as stage
import rollback_production as rollback


def expect_refusal(callable_, fragment):
    try:
        callable_()
    except RuntimeError as error:
        assert fragment in str(error), (fragment, str(error))
    else:
        raise AssertionError(f'Expected refusal containing {fragment!r}')


def run():
    # Template-derived expectations the acceptance probes rely on.
    nginx_template = (ROOT / 'deployment/pi/nginx.conf.in').read_text()
    assert nginx_template.count('listen 192.168.1.92') == 4 and nginx_template.count('listen 192.168.1.93') == 4
    assert 'ssl_reject_handshake on;' in nginx_template and 'return 444;' in nginx_template
    assert 'allow 192.168.1.0/24;' in nginx_template and 'deny all;' in nginx_template
    pool = (ROOT / 'deployment/pi/php-fpm-pool.conf.in').read_text()
    assert 'env[BIJHOUDEN_ENV] = production' in pool and 'user = bijhouden-web' in pool
    fpm_unit = (ROOT / 'deployment/pi/production-fpm.service.in').read_text()
    nginx_unit = (ROOT / 'deployment/pi/production-nginx.service.in').read_text()
    assert 'After=network-online.target bijhouden-fpm.service' in nginx_unit
    assert 'Requires=bijhouden-fpm.service' in nginx_unit and 'Requires=mariadb.service' in fpm_unit
    assert 'WantedBy=multi-user.target' in fpm_unit and 'WantedBy=multi-user.target' in nginx_unit
    assert activation.FPM_SOCKET == '/run/bijhouden-fpm/app.sock'
    assert activation.OFFPI_SHA256 == ('6d2f83e4dddd5a5af066d680641861248e6a12751ad878310'
                                       'b3bdcd238295b81')

    # Session-cookie acceptance: exactly one cookie, strict flags, production header.
    good = ('HTTP/2 200\r\nX-Bijhouden-Deployment: production\r\n'
            'Set-Cookie: BIJHOUDENSESSID=abc123; path=/; secure; HttpOnly; SameSite=Lax\r\n')
    activation.acceptance_session_cookie(good)
    for broken in [good.replace('X-Bijhouden-Deployment: production\r\n', ''),
                   good.replace('; secure', ''),
                   good.replace('; HttpOnly', ''),
                   good.replace('; SameSite=Lax', ''),
                   good + 'Set-Cookie: BIJHOUDENSESSID=second; secure; HttpOnly; SameSite=Lax\r\n',
                   good.replace('BIJHOUDENSESSID=', 'OTHERSESSID=')]:
        expect_refusal(lambda broken=broken: activation.acceptance_session_cookie(broken), '')

    # Media copy semantics on a real temporary tree, production STATE mocked.
    with tempfile.TemporaryDirectory(prefix='activation-rehearsal-', dir='/tmp/opencode') as temporary:
        root = Path(temporary)
        source = root / 'uploads'
        (source / 'avatars').mkdir(parents=True)
        (source / 'group-logos').mkdir()
        for name in ['.application.lock', '.image-storage.lock']:
            (source / name).write_bytes(b'')
        payload = {'avatars/u1-v1-' + 'a' * 32 + '.jpg': b'avatar-bytes',
                   'group-logos/' + 'b' * 32 + '.png': b'logo-bytes'}
        drift_path = source / ('group-logos/' + 'b' * 32 + '.png')
        link = source / ('avatars/u2-v1-' + 'c' * 32 + '.jpg')
        manifest = {}
        for relative, data in payload.items():
            (source / relative).write_bytes(data)
            manifest[relative] = hashlib.sha256(data).hexdigest()
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid())
        state = root / 'varlib'
        (state / 'media' / 'avatars').mkdir(parents=True)
        (state / 'media' / 'group-logos').mkdir()
        os.chmod(state / 'media', 0o700)
        os.chmod(state / 'media' / 'avatars', 0o700)
        os.chmod(state / 'media' / 'group-logos', 0o700)
        normal_config = {'image_storage_path': str(source)}
        def clear_target():
            for folder in ['avatars', 'group-logos']:
                for path in list((state / 'media' / folder).iterdir()):
                    path.unlink()
        with patch.object(production, 'STATE', state), \
                patch.object(activation, 'pwd', SimpleNamespace(getpwnam=lambda name: account)), \
                patch.object(production, 'protected'):
            activation.copy_media(normal_config, manifest)
            for relative, data in payload.items():
                target = state / 'media' / relative
                assert target.is_file() and not target.is_symlink()
                assert target.read_bytes() == data
                assert stat.S_IMODE(target.stat().st_mode) == 0o600
                assert target.stat().st_uid == account.pw_uid
            for folder in ['avatars', 'group-logos']:
                assert stat.S_IMODE((state / 'media' / folder).stat().st_mode) == 0o700
            # Original media unchanged; only the snapshot-listed items were copied.
            for relative, data in payload.items():
                assert (source / relative).read_bytes() == data
            # Re-running on a populated target must refuse, never overwrite.
            expect_refusal(lambda: activation.copy_media(normal_config, manifest),
                           'not an empty private directory')
            # Source drift must refuse; a fresh snapshot is required instead.
            drift_path.write_bytes(b'drifted')
            clear_target()
            expect_refusal(lambda: activation.copy_media(normal_config, manifest),
                           'differs from the fresh snapshot')
            # Manifest/target disagreement must refuse.
            clear_target()
            drift_path.write_bytes(b'logo-bytes')
            expect_refusal(lambda: activation.copy_media(normal_config, dict(manifest, extra='deadbeef')),
                           'missing or unsafe')
            # A symlinked source item must refuse.
            clear_target()
            os.symlink(source / ('avatars/u1-v1-' + 'a' * 32 + '.jpg'), link)
            expect_refusal(lambda: activation.copy_media(normal_config, dict(manifest, **{link.name: 'x'})),
                           'missing or unsafe')
            link.unlink()

    # Rollback: only the two dedicated units, and only when both are active.
    operator = Path(tempfile.mkdtemp(prefix='rollback-report-', dir='/tmp/opencode'))
    calls = []
    active = 'ActiveState=active\nUnitFileState=enabled\n'
    inactive = 'ActiveState=inactive\nUnitFileState=disabled\n'
    def check_output_side_effect(arguments, **kwargs):
        return active if len(calls) < 2 else inactive
    written = []
    with patch.object(rollback.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator), \
            patch.object(rollback, 'capture_live_state', return_value={'snap': True}), \
            patch.object(rollback.subprocess, 'check_output', side_effect=check_output_side_effect), \
            patch.object(stage, 'command', side_effect=lambda arguments, **kwargs: calls.append(list(map(str, arguments)))), \
            patch.object(stage, 'write_new', side_effect=lambda path, content, **kwargs: written.append(json.loads(content))):
        rollback.main()
    assert calls[:2] == [['systemctl', 'stop', 'bijhouden-fpm.service'],
                         ['systemctl', 'stop', 'bijhouden-nginx.service']], calls
    assert calls[2] == ['systemctl', 'disable', 'bijhouden-fpm.service', 'bijhouden-nginx.service'], calls
    assert calls[3] == ['systemctl', 'daemon-reload'], calls
    assert len(calls) == 4, calls
    report = written[-1]
    assert report['rolled_back'] == production.UNITS and report['data_unchanged_by_rollback'] is True
    # Refusal when the units are not both active: nothing is stopped.
    calls.clear()
    with patch.object(rollback.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator), \
            patch.object(rollback.subprocess, 'check_output', return_value=inactive), \
            patch.object(stage, 'command', side_effect=lambda arguments, **kwargs: calls.append(list(map(str, arguments)))):
        expect_refusal(rollback.main, 'not both active')
    assert calls == [], calls

    # Activation guards: approval token and double activation.
    with patch.object(activation.os, 'geteuid', return_value=0), patch.dict(os.environ, {}, clear=True):
        expect_refusal(activation.main, 'approval token')
    with patch.object(activation.os, 'geteuid', return_value=0), \
            patch.dict(os.environ, {'BIJHOUDEN_ACTIVATE_APPROVAL': 'short'}, clear=True):
        expect_refusal(activation.main, 'approval token')
    marker_parent = Path(tempfile.mkdtemp(prefix='activation-marker-', dir='/tmp/opencode'))
    (marker_parent / 'production-activated.json').write_text('{}\n')
    with patch.object(activation.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.dict(os.environ, {'BIJHOUDEN_ACTIVATE_APPROVAL': 'approved-token-20261111'}, clear=True), \
            patch.object(stage, 'OPERATOR', marker_parent):
        expect_refusal(activation.main, 'never activate twice')

    for directory in [operator, marker_parent]:
        for path in directory.iterdir():
            path.unlink()
        directory.rmdir()

    print('PASS: activation/rollback helpers refuse unapproved, repeated or unknown states; fail-closed semantics intact')
    print('PASS: session-cookie acceptance requires exactly one production cookie with Secure/HttpOnly/SameSite')
    print('PASS: media copy is hash/ownership-preserving, lock-aware, refuses drift and populated targets, touches nothing original')
    print('PASS: rollback stops/disables only bijhouden-fpm/bijhouden-nginx and only when both are active')
    print('No production unit was started, enabled or installed by this rehearsal.')


if __name__ == '__main__':
    run()
