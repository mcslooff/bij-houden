"""Isolated stage-retirement rehearsal: mocked systemd/root state; nothing masked."""
import json
import os
from pathlib import Path
import sys
import tempfile
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'deployment/pi'))
import prepare_production as production
import prepare_stage as stage
import retire_stage as retirement


def expect_refusal(callable_, fragment):
    try:
        callable_()
    except RuntimeError as error:
        assert fragment in str(error), (fragment, str(error))
    else:
        raise AssertionError(f'Expected refusal containing {fragment!r}')


def run():
    calls, written = [], []
    active_enabled = 'ActiveState=active\nUnitFileState=enabled\n'
    inactive_static = 'ActiveState=inactive\nUnitFileState=static\n'
    inactive_masked = 'ActiveState=inactive\nUnitFileState=masked\n'
    operator = Path(tempfile.mkdtemp(prefix='retire-rehearsal-', dir='/tmp/opencode'))
    # Refusal without a production activation marker (pre-launch state).
    with patch.object(retirement.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator):
        expect_refusal(retirement.main, 'post-launch action')
    (operator / 'production-activated.json').write_text('{}\n')
    def check_output(arguments, **kwargs):
        units = [argument for argument in arguments if str(argument).endswith('.service')]
        if not units:
            return ''
        unit = units[0]
        if unit in stage.UNITS:
            return inactive_masked if calls else inactive_static
        if unit in production.UNITS or unit == 'mariadb.service':
            return active_enabled
        return inactive_static
    with patch.object(retirement.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator), \
            patch.object(retirement.subprocess, 'check_output', side_effect=check_output), \
            patch.object(stage, 'command', side_effect=lambda arguments, **kwargs: calls.append(list(map(str, arguments)))), \
            patch.object(stage, 'write_new', side_effect=lambda path, content, **kwargs: written.append(json.loads(content))):
        retirement.main()
    assert calls == [['systemctl', 'mask', 'bijhouden-stage-fpm.service',
                      'bijhouden-stage-nginx.service']], calls
    assert written[-1]['masked'] == stage.UNITS
    assert all(path in written[-1]['evidence_preserved'] for path in
               ['/srv/bijhouden-stage', '/var/lib/bijhouden-stage', '/etc/bijhouden-stage', '/var/log/bijhouden-stage'])
    # Refusal when a stage unit is unexpectedly active: nothing masked.
    calls.clear()
    def check_output_active_stage(arguments, **kwargs):
        units = [argument for argument in arguments if str(argument).endswith('.service')]
        if units and units[0] in stage.UNITS:
            return 'ActiveState=active\nUnitFileState=static\n'
        return active_enabled
    with patch.object(retirement.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator), \
            patch.object(retirement.subprocess, 'check_output', side_effect=check_output_active_stage), \
            patch.object(stage, 'command', side_effect=lambda arguments, **kwargs: calls.append(list(map(str, arguments)))):
        expect_refusal(retirement.main, 'review before masking')
    assert calls == [], calls
    # Refusal when a stage port is listening.
    calls.clear()
    listener_output = 'LISTEN 0 511 0.0.0.0:8080\n'
    def check_output_with_listeners(arguments, **kwargs):
        units = [argument for argument in arguments if str(argument).endswith('.service')]
        if not units:
            return listener_output
        return check_output(arguments, **kwargs)
    with patch.object(retirement.os, 'geteuid', return_value=0), \
            patch.object(production, 'protected'), \
            patch.object(stage, 'OPERATOR', operator), \
            patch.object(retirement.subprocess, 'check_output', side_effect=check_output_with_listeners), \
            patch.object(stage, 'command', side_effect=lambda arguments, **kwargs: calls.append(list(map(str, arguments)))):
        expect_refusal(retirement.main, 'stage port is listening')
    assert calls == [], calls
    for path in operator.iterdir():
        path.unlink()
    operator.rmdir()
    print('PASS: stage retirement masks only the two stage units and only after confirmed go-live')
    print('PASS: refuses unknown unit activity and listening stage ports; no evidence path is touched')


if __name__ == '__main__':
    run()
