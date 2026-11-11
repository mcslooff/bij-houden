"""Mock operator commands; never install packages or alter real services."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
DRIVER = r'''#!/usr/bin/python3
import json,os,pathlib,sys
command=pathlib.Path(sys.argv[0]).name
args=sys.argv[1:]
statefile=pathlib.Path(os.environ['MOCK_STATE'])
state=json.loads(statefile.read_text())
state['commands'].append([command]+args)
def finish(code=0, output=''):
    statefile.write_text(json.dumps(state))
    if output: print(output)
    sys.exit(code)
if command=='id': finish(output=os.environ.get('MOCK_UID','0'))
if command=='dpkg-query':
    if args[-1]==os.environ.get('MOCK_EXISTING'): finish(output='install ok installed')
    finish(1)
if command=='systemctl':
    if args[0]=='is-active': finish(0 if args[-1]==os.environ.get('MOCK_ACTIVE') else 3)
    if args[0]=='show': finish(output='disabled' if args[1] in state['disabled'] else 'enabled')
    if args[0]=='mask': state['masked']=args[1:]
    elif args[0]=='disable': state['disabled']=args[1:]
    elif args[0]=='unmask': state['masked']=[]
    else: finish(99, 'Unexpected systemctl operation')
    finish()
if command=='apt-get':
    if set(state['masked']) != {'nginx.service','php8.4-fpm.service'}: finish(99,'Unsafe package install without masks')
    if args[0]=='install' and os.environ.get('MOCK_APT_FAIL'): finish(100)
    finish()
if command=='install': finish() # Deliberately do not write real /var/lib paths.
if command=='nginx': finish(output='nginx mock version')
if command=='php-fpm8.4':
    if args==['-m']:
        finish(output='PDO\npdo_mysql\nmbstring\nfileinfo\ngd'+('' if os.environ.get('MOCK_MISSING_EXIF') else '\nexif'))
    finish(output='PHP-FPM mock version')
finish(99, 'Unexpected mock command')
'''


def run():
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as temporary:
        folder = Path(temporary)
        driver = folder / 'driver'
        driver.write_text(DRIVER)
        driver.chmod(0o700)
        for name in ['id', 'dpkg-query', 'systemctl', 'apt-get', 'install', 'nginx', 'php-fpm8.4']:
            (folder / name).symlink_to(driver)
        statefile = folder / 'state.json'
        for overrides, expected in [({}, 0), ({'MOCK_UID': '1000'}, 2),
                                    ({'MOCK_ACTIVE': 'nginx.service'}, 2),
                                    ({'MOCK_EXISTING': 'nginx'}, 2),
                                    ({'MOCK_APT_FAIL': '1'}, 100),
                                    ({'MOCK_MISSING_EXIF': '1'}, 1)]:
            statefile.write_text(json.dumps({'commands': [], 'masked': [], 'disabled': []}))
            env = dict(os.environ, PATH=str(folder) + os.pathsep + os.environ['PATH'],
                       MOCK_STATE=str(statefile), **overrides)
            result = subprocess.run(['bash', str(ROOT / 'deployment/pi/install-prerequisites.sh')],
                                    env=env, capture_output=True, text=True)
            assert result.returncode == expected, (overrides, result.stdout, result.stderr)
            state = json.loads(statefile.read_text())
            commands = state['commands']
            assert not any(c[:2] in [['systemctl', 'start'], ['systemctl', 'restart'], ['systemctl', 'enable']] for c in commands)
            if expected == 0:
                assert not state['masked'] and len(state['disabled']) == 2
                assert 'PREREQUISITES PREPARED' in result.stdout
            elif expected in [100, 1]:
                assert len(state['masked']) == 2, 'Failed preparation must retain safety masks'
            else:
                assert not any(c[0] == 'apt-get' for c in commands)
        print('PASS: package-preparation control flow, no service startup, root/existing-service guards and failure masks (mocked; no system changes)')


if __name__ == '__main__':
    run()
