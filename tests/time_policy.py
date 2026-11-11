"""Pure timestamp-policy tests; no database needed."""
import subprocess
from pathlib import Path

script = r'''
require 'time_policy.php';
$winter = inspection_visit_time('2026-01-01T12:00');
$summer = inspection_visit_time('2026-07-01T12:00');
if ($winter['visited_at_utc'] !== '2026-01-01 11:00:00' || $winter['visited_offset'] !== '+01:00') throw new RuntimeException('Winter conversion');
if ($summer['visited_at_utc'] !== '2026-07-01 10:00:00' || $summer['visited_offset'] !== '+02:00') throw new RuntimeException('Summer conversion');
foreach (['2026-03-29T02:30', '2026-10-25T02:30', '2026-02-30T12:00'] as $local) {
    $refused = false;
    try { inspection_visit_time($local); } catch (InvalidArgumentException) { $refused = true; }
    if (!$refused) throw new RuntimeException('Invalid/ambiguous time accepted');
}
$early = inspection_visit_time('2026-10-25T02:30', '+02:00');
$late = inspection_visit_time('2026-10-25T02:30', '+01:00');
if ($early['visited_at_utc'] !== '2026-10-25 00:30:00' || $late['visited_at_utc'] !== '2026-10-25 01:30:00') throw new RuntimeException('Repeated hour');
if (display_record_time('2020-01-01 12:34:56', null, null) !== '2020-01-01 12:34:56 (timezone unrecorded)') throw new RuntimeException('Legacy shifted');
if (app_now()->getTimezone()->getName() !== 'Europe/Amsterdam') throw new RuntimeException('Wrong default');
echo "PASS: Amsterdam winter/summer UTC conversion, DST gap/repeated-hour validation and unshifted historical display\n";
'''
subprocess.run(['php', '-r', script], cwd=Path(__file__).resolve().parents[1], check=True)
