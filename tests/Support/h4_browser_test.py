#!/usr/bin/env python3
"""Real local Chrome/Edge browser checks over CDP; Python websockets is dev-only.

Start h4_browser_router.php on loopback with the guarded disposable test env.
H4_BROWSER_ORIGIN must be a loopback HTTP origin. H4_BROWSER_BIN names a local
Chromium browser. No production URLs or live providers are used. Fixture reset
truncates ONLY the dedicated *_test database through IntegrationTestCase's guard.
"""
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time
import urllib.parse
import urllib.request
from websockets.sync.client import connect

ORIGIN = os.environ.get('H4_BROWSER_ORIGIN', 'http://127.0.0.1:38941')
u = urllib.parse.urlsplit(ORIGIN)
assert u.scheme == 'http' and u.hostname == '127.0.0.1' and u.port and u.port != 80 and not u.path
ROOT = Path(__file__).resolve().parents[2]
BINARY = os.environ.get('H4_BROWSER_BIN', '/opt/google/chrome/chrome')
PASSWORD = 'correct horse battery staple'
CHECKS = 0


def check(value, message):
    global CHECKS
    assert value, message
    CHECKS += 1


class Browser:
    def __init__(self, javascript, mobile):
        self.directory = tempfile.mkdtemp(prefix='dla-h4-chrome-')
        self.process = subprocess.Popen([BINARY, '--headless', '--no-sandbox', '--disable-dev-shm-usage',
            '--remote-debugging-port=0', '--remote-debugging-address=127.0.0.1', '--disable-background-networking',
            '--disable-component-update', '--disable-sync', '--disable-extensions', '--no-first-run',
            '--no-default-browser-check', '--disable-default-apps', '--metrics-recording-only',
            '--host-resolver-rules=MAP * ~NOTFOUND, EXCLUDE 127.0.0.1',
            '--user-data-dir=' + self.directory, 'about:blank'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        self.connection = None
        self.number = 0
        self.errors = []
        try:
            active = Path(self.directory) / 'DevToolsActivePort'
            deadline = time.monotonic() + 15
            while not active.exists():
                if time.monotonic() > deadline or self.process.poll() is not None:
                    raise RuntimeError('Browser failed to start')
                time.sleep(.05)
            port = active.read_text().splitlines()[0]
            req = urllib.request.Request('http://127.0.0.1:' + port + '/json/new?about:blank', method='PUT')
            target = json.load(urllib.request.urlopen(req, timeout=5))
            self.connection = connect(target['webSocketDebuggerUrl'], proxy=None, open_timeout=5)
            self.call('Page.enable')
            self.call('Runtime.enable')
            self.call('Fetch.enable', {'patterns': [{'urlPattern': '*'}]})
            self.call('Emulation.setDeviceMetricsOverride', {'width': 390 if mobile else 1366, 'height': 844 if mobile else 900, 'deviceScaleFactor': 1, 'mobile': mobile})
            self.call('Emulation.setScriptExecutionDisabled', {'value': not javascript})
        except BaseException:
            self.close()
            raise

    def call(self, method, params=None):
        self.number += 1
        number = self.number
        self.connection.send(json.dumps({'id': number, 'method': method, 'params': params or {}}))
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            event = json.loads(self.connection.recv(timeout=15))
            if event.get('method') == 'Fetch.requestPaused':
                p = event['params']
                url = p['request']['url']
                command = 'Fetch.continueRequest' if url.startswith(ORIGIN + '/') or url == ORIGIN else 'Fetch.failRequest'
                arguments = {'requestId': p['requestId']}
                if command == 'Fetch.failRequest':
                    arguments['errorReason'] = 'BlockedByClient'
                # Events may interleave replies; do not recursively consume this call's response.
                self.number += 1
                self.connection.send(json.dumps({'id': self.number, 'method': command, 'params': arguments}))
            if event.get('method') == 'Runtime.exceptionThrown':
                self.errors.append(event['params'])
            if event.get('id') == number:
                if 'error' in event:
                    raise RuntimeError(event['error'])
                return event.get('result', {})
        raise TimeoutError(method)

    def js(self, expression):
        result = self.call('Runtime.evaluate', {'expression': expression, 'returnByValue': True, 'awaitPromise': True})
        if 'exceptionDetails' in result:
            raise RuntimeError(result['exceptionDetails'])
        return result['result'].get('value')

    def navigate(self, path):
        self.call('Page.navigate', {'url': ORIGIN + path})
        self.wait(lambda: self.js('document.readyState') == 'complete')

    def wait(self, condition):
        deadline = time.monotonic() + 15
        while time.monotonic() < deadline:
            if condition():
                return
            time.sleep(.05)
        raise AssertionError('Browser condition timeout: ' + str(self.js('location.href')))

    def set(self, selector, value):
        self.js('document.querySelector(' + json.dumps(selector) + ').value=' + json.dumps(str(value)))

    def submit(self, selector, button=None):
        # Explicit debugger interaction also works with page JavaScript disabled.
        expression = 'document.querySelector(' + json.dumps(selector) + ').requestSubmit('
        expression += ('document.querySelector(' + json.dumps(button) + ')') if button else ''
        self.js(expression + ')')
        time.sleep(.15)
        self.wait(lambda: self.js('document.readyState') == 'complete')

    def login(self, role):
        self.navigate('/login')
        self.set('[name=email]', 'browser-' + role + '@example.org')
        self.set('[name=password]', PASSWORD)
        self.submit('form[action="/login"]')
        self.wait(lambda: self.js('location.pathname') != '/login')

    def text(self):
        return self.js('document.body.innerText')

    def close(self):
        if self.connection is not None:
            self.connection.close()
        self.process.terminate()
        try:
            self.process.wait(timeout=10)
        except subprocess.TimeoutExpired:
            self.process.kill()
            self.process.wait()
        shutil.rmtree(self.directory)


def seed():
    return json.loads(subprocess.check_output(['php', str(ROOT / 'tests/Support/h4_browser_fixture.php')], cwd=ROOT, text=True))


def run(javascript, locale):
    ids = seed()
    browser = Browser(javascript, mobile=locale != 'en')
    try:
        browser.login('member')
        browser.navigate('/books')
        check('BROWSER-PRIVATE-CANARY' not in browser.text(), 'Member catalogue leak')
        browser.navigate('/admin/inventory')
        check(not browser.js('!!document.querySelector("input[type=checkbox]")'), 'Member reached inventory management')
        browser.navigate('/')
        browser.submit('form[action="/logout"]')
        browser.login('viewer')
        browser.navigate('/books')
        check('BROWSER-PRIVATE-CANARY' not in browser.text(), 'Viewer catalogue leak')
        browser.navigate('/admin/inventory/transfers/999999')
        check('BROWSER-CODE' not in browser.text(), 'Viewer transfer disclosure')
        browser.navigate('/')
        browser.submit('form[action="/logout"]')
        browser.login('admin')
        browser.set('select[name=locale]', locale)
        browser.submit('form[action="/locale"]')
        browser.navigate('/admin/inventory?source=' + str(ids['source']))
        check(browser.js('document.documentElement.lang') == locale, 'Wrong locale')
        check(browser.js('document.documentElement.scrollWidth <= innerWidth + 1'), 'Mobile page overflow')
        if javascript and locale != 'en':
            browser.js('document.querySelector("[data-nav-toggle]").click()')
            check(browser.js('document.querySelector("[data-nav-toggle]").getAttribute("aria-expanded")') == 'true', 'H3 mobile JS menu failed')
        browser.js('document.querySelector(' + json.dumps('input[type="checkbox"][value="' + str(ids["copy"]) + ':1"]') + ').checked=true')
        browser.set('[name=destination]', ids['destination'])
        browser.set('[name=location]', ids['target'])
        browser.set('[name=reason]', 'Browser reviewed transfer')
        browser.submit('form[action="/admin/inventory/preview"]', 'button[value=plan]')
        browser.wait(lambda: '/transfers/' in browser.js('location.pathname'))
        path = browser.js('location.pathname')
        check(browser.js('!!document.querySelector("[name=digest]")'), 'Confirmation preview absent')
        browser.js('document.querySelector("[name=confirmed]").checked=true')
        replay = browser.js('new URLSearchParams(new FormData(document.querySelector(\'form[action$="/apply"]\'))).toString()')
        browser.submit('form[action$="/apply"]')
        check(not browser.js('!!document.querySelector("[name=digest]")'), 'Operation did not complete')
        browser.navigate(path)
        check(not browser.js('!!document.querySelector("[name=digest]")'), 'Completed operation offered again')
        replay_status = browser.js('fetch(' + json.dumps(path + '/apply') + ', {method:"POST",headers:{"Content-Type":"application/x-www-form-urlencoded"},body:' + json.dumps(replay) + '}).then(r=>r.status)')
        check(replay_status == 200, 'Repeated confirmation failed')
        # A second saved operation becomes stale after a normal location edit.
        browser.navigate('/admin/inventory?source=' + str(ids['source']))
        browser.set('[name=kind]', 'subtree')
        browser.set('[name=destination]', ids['destination'])
        browser.set('[name=parent]', ids['target'])
        browser.set('[name=root]', str(ids['root']) + ':1')
        browser.set('[name=reason]', 'Browser subtree review')
        browser.submit('form[action="/admin/inventory/preview"]', 'button[value=plan]')
        browser.wait(lambda: '/transfers/' in browser.js('location.pathname'))
        subtree_path = browser.js('location.pathname')
        browser.navigate('/admin/locations/' + str(ids['root']) + '/edit')
        browser.set('input[name=name]', 'Changed browser root')
        browser.submit('form[action="/admin/locations/' + str(ids['root']) + '"]')
        browser.navigate(subtree_path)
        browser.js('document.querySelector("[name=confirmed]").checked=true')
        browser.submit('form[action$="/apply"]')
        check(browser.js('!!document.querySelector(".alert-error")'), 'Stale subtree was not refused')
        browser.navigate('/admin/inventory?source=' + str(ids['source']))
        check(browser.js('!!document.querySelector("input[type=checkbox]")'), 'Stale operation moved source inventory')

        # Exercise the H3 script and ordinary same-household member editing.
        compiled = browser.call('Runtime.compileScript', {'expression': (ROOT / 'public/assets/js/app.js').read_text(), 'sourceURL': ORIGIN + '/assets/js/app.js', 'persistScript': False})
        check('exceptionDetails' not in compiled, 'JavaScript syntax error')
        browser.navigate('/')
        browser.submit('form[action="/logout"]')
        browser.login('owner')
        browser.navigate('/editions/' + str(ids['edition']) + '/copies/new')
        browser.set('[name=household_id]', ids['source'])
        browser.js('document.querySelector("[name=household_id]").dispatchEvent(new Event("change"))')
        if javascript:
            check(browser.js('Array.from(document.querySelectorAll("optgroup[data-household]")).filter(g=>!g.disabled).every(g=>g.dataset.household===' + json.dumps(str(ids['source'])) + ')'), 'H3 household/location JavaScript filtering failed')
        browser.navigate('/copies/' + str(ids['copy']) + '/edit')
        browser.set('[name=notes]', 'Browser same-household edit')
        browser.submit('form[action="/copies/' + str(ids['copy']) + '"]')
        browser.navigate('/copies/' + str(ids['copy']) + '/edit')
        check(browser.js('document.querySelector("[name=notes]").value') == 'Browser same-household edit', 'Member same-household edit failed')
        check(not browser.errors, 'Page JavaScript exception')
        print('PASS', 'JS' if javascript else 'NO-JS', locale, 'admin/member/viewer, transfer, layout', flush=True)
    finally:
        browser.close()


if __name__ == '__main__':
    for js_enabled in (True, False):
        for language in ('en', 'es', 'gl'):
            run(js_enabled, language)
    print(str(CHECKS) + ' browser checks passed')
