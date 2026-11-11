"""Restoration epoch revocation and fail-closed enrollment on the isolated DB."""
import subprocess

import integration as test


script = r'''
require 'db.php';
db()->beginTransaction();
try {
    $epoch = auth_current_epoch();
    $user = ['id' => 1, 'username' => 'epoch-check', 'auth_version' => 1];
    auth_establish_session($user);
    if ($_SESSION['db_epoch'] !== $epoch) throw new RuntimeException('Enrollment failed');
    db()->exec('UPDATE application_state SET session_epoch = LOWER(HEX(RANDOM_BYTES(16))) WHERE id = 1');
    if (auth_current_user() !== null || isset($_SESSION['user'])) throw new RuntimeException('Revocation failed');
    db()->exec('DELETE FROM application_state WHERE id = 1');
    $refused = false;
    try { auth_establish_session($user); } catch (RuntimeException) { $refused = true; }
    if (!$refused || isset($_SESSION['user'])) throw new RuntimeException('Missing state accepted');
    db()->exec("INSERT INTO application_state (id, session_epoch) VALUES (1, 'bad')");
    $refused = false;
    try { auth_current_epoch(); } catch (RuntimeException) { $refused = true; }
    if (!$refused) throw new RuntimeException('Malformed state accepted');
} finally { db()->rollBack(); }
echo "PASS: epoch enrollment, rotation revocation, missing/malformed state refuses enrollment; fixture rolled back\n";
'''

if __name__ == '__main__':
    subprocess.run(['php', '-r', script], cwd=test.ROOT, check=True)
