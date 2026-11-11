"""Run the complete local regression battery against an explicitly isolated server."""
import argparse
import hashlib
import json
import os
from pathlib import Path
import subprocess
import sys
import time

import integration as test  # Enforces test DB/storage and matching HTTP fingerprints.


SUITES = [
    'authentication_integration', 'integration', 'tasks_integration',
    'group_owner_labels_integration', 'consistency_integration',
    'media_security_integration', 'p0_hardening', 'browser_security',
    'pagination_integration', 'session_epoch_integration', 'operations_integration',
    'stale_edits_integration', 'time_policy', 'time_policy_integration',
]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--report-directory', required=True)
    parser.add_argument('--include-scalability', action='store_true',
                        help='Also create and clean up 13,000 scoped records for a local large-list check.')
    args = parser.parse_args()
    suites = SUITES + (['scalability_integration'] if args.include_scalability else [])
    raw = Path(args.report_directory)
    if not raw.is_absolute() or raw.is_symlink():
        parser.error('Use an absolute private report directory, not a symlink.')
    directory = raw.resolve()
    public = (test.ROOT / 'public').resolve()
    media = Path(test.CONFIG_INFO['image_root']).resolve()
    if any(directory == protected or protected in directory.parents for protected in [public, media]):
        parser.error('Reports must be outside public and managed media directories.')
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    if directory.stat().st_mode & 0o077:
        parser.error('Report directory must have private 0700 permissions.')
    os.umask(0o077)
    report = {'started_at_utc': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()),
              'database': test.CONFIG_INFO['database'],
              'database_fingerprint': test.CONFIG_INFO['fingerprint'],
              'storage_fingerprint': test.CONFIG_INFO['storage_fingerprint'], 'results': []}
    sources = set(test.ROOT.glob('*.php')) | set(test.ROOT.glob('*.css')) | set(test.ROOT.glob('*.js'))
    for pattern in ['bin/*.php', 'tests/*.py', 'migrations/*.sql', 'public/*.php', 'public/.user.ini', 'schema.sql']:
        sources.update(test.ROOT.glob(pattern))
    report['source_sha256'] = {str(path.relative_to(test.ROOT)): hashlib.sha256(path.read_bytes()).hexdigest()
                               for path in sorted(sources)}
    # Exclusive creation prevents silently replacing a previous verification run.
    with (directory / 'results.json').open('x') as summary:
        try:
            for suite in suites:
                print('RUN:', suite, flush=True)
                started = time.monotonic()
                try:
                    result = subprocess.run([sys.executable, str(test.ROOT / 'tests' / (suite + '.py'))],
                                            cwd=test.ROOT, capture_output=True, text=True, timeout=300)
                    code, output = result.returncode, result.stdout + result.stderr
                except subprocess.TimeoutExpired:
                    code, output = 124, 'Suite exceeded its 300-second timeout. Inspect sandbox state before retrying.\n'
                with (directory / (suite + '.log')).open('x') as log:
                    log.write(output)
                report['results'].append({'suite': suite, 'exit_code': code,
                                          'seconds': round(time.monotonic() - started, 2)})
                print(('PASS' if code == 0 else 'FAIL') + ': ' + suite, flush=True)
                if code != 0:
                    break
        finally:
            report['passed'] = len(report['results']) == len(suites) and all(r['exit_code'] == 0 for r in report['results'])
            report['finished_at_utc'] = time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime())
            json.dump(report, summary, indent=2)
            summary.write('\n')
    print('Private report:', directory / 'results.json')
    return 0 if report['passed'] else 1


if __name__ == '__main__':
    sys.exit(main())
