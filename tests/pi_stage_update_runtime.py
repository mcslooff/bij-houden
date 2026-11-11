"""Check staging updater runtime-directory handling without root/service changes."""
from pathlib import Path
import stat
import sys
from types import SimpleNamespace
from unittest.mock import MagicMock, patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'deployment/pi'))
import update_stage_media_test as update


def run():
    def check(exists, mode=stat.S_IFDIR | 0o755, uid=0, gid=0):
        path = MagicMock()
        path.exists.return_value = exists
        path.is_symlink.return_value = stat.S_ISLNK(mode)
        path.lstat.return_value = SimpleNamespace(st_mode=mode, st_uid=uid, st_gid=gid)
        root = MagicMock()
        root.__truediv__.return_value = path
        with patch.object(update, 'Path', return_value=root), patch.object(update.stage, 'directory') as create:
            update.prepare_runtime_directories()
            assert create.call_count == (0 if exists else 2)
            for call in create.call_args_list:
                assert call.kwargs == {'mode': 0o755}
    check(False)
    check(True)
    for values in [{'mode': stat.S_IFLNK | 0o755}, {'mode': stat.S_IFREG | 0o755},
                   {'mode': stat.S_IFDIR | 0o777}, {'uid': 1000}, {'gid': 1000}]:
        try:
            check(True, **values)
        except RuntimeError:
            pass
        else:
            raise AssertionError('Unsafe runtime directory accepted')
    print('PASS: missing stage runtime directories recreated; existing safe directories retained; symlinks/unsafe modes/owners refused')


if __name__ == '__main__':
    run()
