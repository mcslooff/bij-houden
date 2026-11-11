"""Cookie namespace validation without connecting to any database."""
import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]


def run():
    with tempfile.TemporaryDirectory(dir='/tmp/opencode') as temporary:
        path = Path(temporary) / 'config.json'
        config = {'environment': 'test', 'db_host': 'localhost', 'db_name': 'bijhouden_test_cookie',
                  'db_user': 'fixture', 'db_password': 'test-only-placeholder',
                  'image_storage_path': temporary + '/media', 'diagnostic_log_directory': temporary + '/logs'}
        env = {key: value for key, value in os.environ.items() if not key.startswith('BIJHOUDEN_')}
        env['BIJHOUDEN_CONFIG'] = str(path)
        code = '''require "configuration.php"; try { echo app_config()['session_cookie_name']; }
                  catch (ConfigurationException) { echo "refused"; exit(2); }'''
        for name in [None, 'BIJHOUDENSTAGE', 'PHPSESSID', 'BIJHOUDENbad', 'BIJHOUDENSTAGE; Secure',
                     ['BIJHOUDENSTAGE'], '', 'BIJHOUDEN' + 'A' * 100]:
            values = dict(config)
            if name is not None:
                values['session_cookie_name'] = name
            path.write_text(json.dumps(values))
            path.chmod(0o600)
            result = subprocess.run(['php', '-r', code], cwd=ROOT, env=env, text=True, capture_output=True)
            if name in [None, 'BIJHOUDENSTAGE']:
                assert result.returncode == 0 and result.stdout == (name or 'BIJHOUDENSESSID'), result.stderr
            else:
                assert result.returncode == 2 and result.stdout == 'refused', name
        print('PASS: existing default cookie preserved, distinct stage cookie accepted, malformed/header-unsafe namespaces refused (no DB connection)')


if __name__ == '__main__':
    run()
