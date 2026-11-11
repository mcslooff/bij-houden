"""Exercise the pure denial assertion without importing database fixtures."""
import ast
from pathlib import Path
from types import SimpleNamespace


def run():
    source = Path(__file__).with_name('p0_hardening.py')
    tree = ast.parse(source.read_text())
    function = next(node for node in tree.body if isinstance(node, ast.FunctionDef) and node.name == 'assert_traversal_denied')
    scope = {}
    exec(compile(ast.Module(body=[function], type_ignores=[]), str(source), 'exec'), scope)
    check = scope['assert_traversal_denied']
    nginx = ('<html>\r\n<head><title>400 Bad Request</title></head>\r\n<body>\r\n'
             '<center><h1>400 Bad Request</h1></center>\r\n<hr><center>nginx</center>\r\n'
             '</body>\r\n</html>\r\n')
    check(SimpleNamespace(status=404, headers={}), 'Not found.')
    check(SimpleNamespace(status=400, headers={'Server': 'nginx'}), nginx)
    for status, headers, body in [(200, {}, 'Not found.'), (403, {}, 'Not found.'),
                                  (400, {}, nginx), (400, {'Server': 'nginx'}, nginx + 'SECRET'),
                                  (404, {}, 'Not found. SECRET'), (500, {'Server': 'nginx'}, nginx)]:
        try:
            check(SimpleNamespace(status=status, headers=headers), body)
        except AssertionError:
            pass
        else:
            raise AssertionError('Unexpected status/body accepted')
    print('PASS: only exact PHP 404 or nginx 400 denial accepted; success/errors/disclosures refused')


if __name__ == '__main__':
    run()
