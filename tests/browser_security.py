import base64
import io
import json
import os
import re
import secrets
import subprocess
import tempfile
from pathlib import Path

import cv2
import numpy
from PIL import Image

import integration as test
import group_owner_labels_integration as logos
import media_security_integration as media
from browser_driver import Chromium


def run():
    hashed = subprocess.check_output(['php', '-r', 'require "db.php"; print hash_user_password($argv[1]);', test.PASSWORD], cwd=test.ROOT, text=True)
    group = test.insert('groups', {'name': test.PREFIX + '_brand', 'owner_label_token': secrets.token_hex(16)})
    other_group = test.insert('groups', {'name': test.PREFIX + '_other', 'owner_label_token': secrets.token_hex(16)})
    test.GROUP_IDS.extend([group, other_group])
    users = {}
    clients = {}
    for name, role, gid, first in [('admin', 'admin', None, None), ('owner', 'user', group, 'Beekeeper'), ('other', 'user', other_group, None)]:
        users[name] = test.insert('users', {'username': test.PREFIX + '_' + name, 'password_hash': hashed, 'role': role, 'group_id': gid, 'first_name': first})
        test.USER_IDS.append(users[name])
        client = test.Browser()
        assert 'Welkom ' in client.post('login.php', {'username': test.PREFIX + '_' + name, 'password': test.PASSWORD})
        clients[name] = client
    logo = media.png(80, 2000)
    logos.upload(clients['admin'], {'action': 'update', 'id': group, 'name': test.PREFIX + '_brand',
        'owner_name': 'Browser owner', 'owner_address': 'Bee Lane 12\nApiary Town', 'owner_phone': '+31 6 12345678', 'owner_email': 'owner@example.com'}, logo)
    media.multipart(clients['owner'], 'profile.php', 'photo', {'action': 'photo'}, media.png())
    hive = test.insert('hives', {'name': test.PREFIX + '_hive', 'group_id': group, 'notes': ''})
    def sid(name):
        return next(cookie.value for cookie in clients[name].cookies if cookie.name == test.COOKIE_NAME)
    label = test.BASE + '/owner_label.php?token=' + test.row('groups', group)['owner_label_token']
    with Chromium() as browser:
        browser.call('Emulation.setDeviceMetricsOverride', {'width': 1440, 'height': 1000, 'deviceScaleFactor': 2, 'mobile': False})
        browser.call('Network.setCookie', {'name': test.COOKIE_NAME, 'value': sid('owner'), 'url': test.BASE + '/', 'secure': test.BASE.startswith('https://'), 'httpOnly': True, 'sameSite': 'Lax'})
        browser.navigate(test.BASE + '/index.php')
        browser.wait('[...document.images].every(image => image.complete && image.naturalWidth > 0)')
        assert browser.evaluate('document.querySelector("#dashboard-welcome h1").textContent.includes("Welkom Beekeeper")')
        assert browser.evaluate('window.cspViolations.length') == 0
        assert browser.evaluate('''(() => {const p = document.getElementById('dashboard-welcome'), box = p.querySelector('.welcome-group-logo');
            const height = p.getBoundingClientRect().height, image = box.querySelector('img');
            const fits = image.naturalHeight > height && image.getBoundingClientRect().height <= height;
            box.remove(); const unchanged = Math.abs(p.getBoundingClientRect().height - height) < 1; p.append(box); return fits && unchanged;})()''')
        browser.evaluate('document.getElementById("sidebar-toggle").click()')
        browser.wait('document.querySelector(".sidebar").getBoundingClientRect().width <= 73')
        assert browser.evaluate('document.getElementById("sidebar-toggle").getAttribute("aria-expanded")') == 'false'
        browser.evaluate('document.querySelector("nav a[href=\\"hives.php\\"]").click()')
        browser.wait('location.pathname === "/hives.php" && document.readyState === "complete"')
        assert browser.evaluate('document.documentElement.dataset.sidebarCollapsed') == 'true'
        assert browser.evaluate('window.cspViolations.length') == 0
        browser.evaluate('''window.confirmCalls = 0; window.confirm = () => {window.confirmCalls++; return false;};
            document.querySelector('form[data-confirm]').requestSubmit();''')
        assert browser.evaluate('window.confirmCalls') == 1 and test.row('hives', hive)
        print('PASS: actual HTTP/CSP dashboard images, fixed-height logo, persistent icon navigation and external confirmation handler')

        browser.call('Emulation.setDeviceMetricsOverride', {'width': 390, 'height': 844, 'deviceScaleFactor': 2, 'mobile': True})
        browser.navigate(test.BASE + '/index.php')
        browser.wait('[...document.images].every(image => image.complete && image.naturalWidth > 0)')
        assert browser.evaluate('document.querySelector(".sidebar").getBoundingClientRect().width <= 73')
        assert browser.evaluate('window.cspViolations.length') == 0
        browser.call('Network.setCookie', {'name': test.COOKIE_NAME, 'value': sid('admin'), 'url': test.BASE + '/', 'secure': test.BASE.startswith('https://'), 'httpOnly': True, 'sameSite': 'Lax'})
        browser.navigate(test.BASE + '/tasks.php')
        assert browser.evaluate('''(() => {const group = document.getElementById('task_group'), assignee = document.getElementById('assigned_to');
            group.value = 'GROUP'; group.dispatchEvent(new Event('change')); const member = [...assignee.options].find(o => o.value === 'OWNER');
            const other = [...assignee.options].find(o => o.value === 'OTHER'); if (member.disabled || !other.disabled) return false;
            assignee.value = member.value; group.value = 'OTHERGROUP'; group.dispatchEvent(new Event('change')); return assignee.value === '';})()'''
            .replace('OTHERGROUP', str(other_group)).replace('GROUP', str(group)).replace('OWNER', str(users['owner'])).replace('OTHER', str(users['other'])))
        assert browser.evaluate('window.cspViolations.length') == 0
        browser.evaluate("window.blockedInline = false; const s=document.createElement('script'); s.textContent='window.blockedInline=true'; document.body.append(s);")
        browser.wait('window.cspViolations.length > 0')
        assert browser.evaluate('window.blockedInline') is False
        print('PASS: mobile layout, task member filtering, zero normal CSP violations and blocked injected inline script')

        browser.call('Network.deleteCookies', {'name': test.COOKIE_NAME, 'url': test.BASE + '/'})
        browser.call('Emulation.setDeviceMetricsOverride', {'width': 1440, 'height': 1000, 'deviceScaleFactor': 2, 'mobile': False})
        browser.navigate(label)
        browser.wait('document.querySelector("#owner-qr svg") && [...document.images].every(image => image.complete && image.naturalWidth > 0)')
        assert browser.evaluate('document.getElementById("print-owner-label") === null')
        assert browser.evaluate('window.cspViolations.length') == 0
        screenshot = base64.b64decode(browser.call('Page.captureScreenshot', {'format': 'png', 'captureBeyondViewport': True})['data'])
        image = cv2.imdecode(numpy.frombuffer(screenshot, numpy.uint8), cv2.IMREAD_COLOR)
        decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
        assert decoded == label, ('Screenshot QR mismatch', decoded)
        browser.call('Network.setCookie', {'name': test.COOKIE_NAME, 'value': sid('owner'), 'url': test.BASE + '/', 'secure': test.BASE.startswith('https://'), 'httpOnly': True, 'sameSite': 'Lax'})
        browser.navigate(label)
        browser.wait('document.getElementById("print-owner-label") && !document.getElementById("print-owner-label").hidden && [...document.images].every(image => image.complete && image.naturalWidth > 0)')
        browser.evaluate('''window.printCalls=0; window.print=()=>window.printCalls++; document.getElementById('print-owner-label').click();''')
        browser.wait('window.printCalls === 1')
        assert browser.evaluate('window.cspViolations.length') == 0
        pdf = base64.b64decode(browser.call('Page.printToPDF', {'printBackground': True, 'preferCSSPageSize': True, 'displayHeaderFooter': False})['data'])
        with tempfile.TemporaryDirectory(dir=test.TEMP_ROOT) as directory:
            path = os.path.join(directory, 'owner.pdf'); Path(path).write_bytes(pdf)
            text = subprocess.check_output(['pdftotext', path, '-'], text=True)
            assert 'Browser owner' in text and 'owner@example.com' in text and 'Print owner label' not in text
            subprocess.run(['pdftoppm', '-singlefile', '-scale-to', '1800', '-png', path, os.path.join(directory, 'owner')], check=True, capture_output=True)
            image = cv2.imread(os.path.join(directory, 'owner.png'))
            decoded, _, _ = cv2.QRCodeDetector().detectAndDecode(image)
            assert decoded == label, ('Printed QR mismatch', decoded)
            gold = (image[:, :, 0] < 80) & (image[:, :, 1] > 100) & (image[:, :, 1] < 230) & (image[:, :, 2] > 200)
            assert int(gold.sum()) > 50
        print('PASS: anonymous QR label, authenticated-only print control, CSP-safe print action and PDF with visible logo/decodable QR')


if __name__ == '__main__':
    try:
        run()
    finally:
        test.cleanup()
