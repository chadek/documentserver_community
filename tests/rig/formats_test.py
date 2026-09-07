#!/usr/bin/env python3
"""Check that the onlyoffice format settings are seeded, and then left alone.

`AutoConfig::autoConfigIfNeeded()` runs from `boot()`, so on every request, and
it used to re-apply a hardcoded format list - force-disabling anything the
admin had enabled that the list did not mention. Enabling pdf, docm or html
editing in the settings UI therefore worked until the next page load, which is
as good as not working at all. The list had also gone stale: twelve formats
where the bundled server declares twenty-nine editable, three of the twelve
(doc, ppt, xls) not editable at all.

Three things, in order:

  1. the seed - a fresh install gets every format the bundled server can edit,
     and only the curated set as default openers
  2. no reversion - an admin's own choice survives page loads
  3. the setting reaches the editor - the connector opens a file in view mode
     when its format is not enabled for editing, and in edit mode when it is

No browser needed: what the editor is told is the connector's own config
response, which is a cheaper and less ambiguous reading than sdkjs internals.
"""
import argparse, json, os, subprocess, sys

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness

# The four editors the bundled server ships, and the lossy-editable formats the
# connector leaves off by default - which is the reason seeding exists at all.
MUST_EDIT = ['docx', 'xlsx', 'pptx', 'pdf', 'odt', 'ods', 'odp', 'csv', 'rtf', 'txt']
# Known to the format matrix, but view-only. The old hardcoded list claimed doc,
# ppt and xls were editable; they only auto-convert.
MUST_NOT_EDIT = ['doc', 'ppt', 'xls', 'vsdx']
# Formats the old list did not mention, so an admin who enabled them had them
# switched back off by the next page load.
WAS_REVERTED = ['pdf', 'docm', 'html', 'epub', 'ott', 'tsv', 'xlsm']

DEFAULT_OPENERS = ['doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx']


def page_loads(times=3):
    """Ordinary web requests, which is all it took to lose the setting."""
    user, password = config.credentials(config.ADMIN)
    for _ in range(times):
        subprocess.run(['curl', '-s', '-o', '/dev/null', '-u', f'{user}:{password}',
                        f'{config.BASE}/apps/files/'], capture_output=True)


def seed(ok):
    """What a fresh install gets.

    The connector being unconfigured is what makes this the initial
    auto-config, so its url has to go last: occ boots this app too, and any occ
    call made while the url is missing re-runs the seed - which would then be
    undone by the deletes that were meant to come first.
    """
    print('\n==> [1] the seed a fresh install gets')
    url = harness.get_app_config('DocumentServerUrl', harness.CONNECTOR_ID)
    harness.delete_app_config('editFormats', harness.CONNECTOR_ID)
    harness.delete_app_config('defFormats', harness.CONNECTOR_ID)
    harness.delete_app_config('DocumentServerUrl', harness.CONNECTOR_ID)
    try:
        harness.occ('status')  # boots the app with the connector unconfigured
        edit = harness.get_json_app_config('editFormats') or {}
        default = harness.get_json_app_config('defFormats') or {}
        editable = sorted(name for name, on in edit.items() if on)
        openers = sorted(name for name, on in default.items() if on)
        print(f'   {len(editable)} editable: {editable}')
        print(f'   {len(openers)} default openers: {openers}')

        missing = [name for name in MUST_EDIT if not edit.get(name)]
        if missing:
            ok = False
            print(f'   FAIL - the seed left these off: {missing}')
        wrong = [name for name in MUST_NOT_EDIT if edit.get(name)]
        if wrong:
            ok = False
            print(f'   FAIL - the seed enabled editing for formats the server '
                  f'cannot edit: {wrong}')
        if openers != sorted(DEFAULT_OPENERS):
            ok = False
            print(f'   FAIL - default openers should be {sorted(DEFAULT_OPENERS)}')
        # the whole point of reading the matrix out of the package instead of
        # restating it: the number goes up with the bundled server
        if len(editable) < 20:
            ok = False
            print(f'   FAIL - only {len(editable)} editable formats seeded; the '
                  f'bundled server declares far more')
    finally:
        # the provisioned url, not the one auto-config just wrote: that one
        # comes from overwrite.cli.url, and an editor iframe on a different
        # origin than the page cannot be read by the other tests
        harness.set_app_config('DocumentServerUrl', url, harness.CONNECTOR_ID)
        print('   url restored to', harness.get_app_config(
            'DocumentServerUrl', harness.CONNECTOR_ID))
    return ok


def no_reversion(ok):
    print("\n==> [2] an admin's own format choice survives page loads")
    before = harness.get_json_app_config('editFormats') or {}
    choice = dict(before)
    # an admin turning things on, including the ones the hardcoded list used to
    # revert, and one thing off, so a wholesale rewrite shows up either way
    for name in WAS_REVERTED:
        choice[name] = True
    choice['docx'] = True
    choice['rtf'] = False
    harness.set_json_app_config('editFormats', choice)

    print(f'   set {len(WAS_REVERTED)} formats the old code reverted, plus rtf off')
    stored = harness.wait_for(lambda: harness.get_json_app_config('editFormats'), choice)
    lost = sorted(name for name, on in choice.items() if bool(stored.get(name)) != bool(on))
    if lost:
        # not "the write failed": reading it back is an occ call, which boots
        # this app, which is all it used to take to have the value rewritten
        ok = False
        print(f'   FAIL - the choice did not even survive being read back: {lost}')

    page_loads()
    after = harness.get_json_app_config('editFormats')
    reverted = sorted(name for name, on in choice.items() if bool(after.get(name)) != bool(on))
    if reverted:
        ok = False
        print(f'   FAIL - page loads changed these back: {reverted}')
    elif not lost:
        print('   nothing was rewritten')

    harness.set_json_app_config('editFormats', before)
    harness.wait_for(lambda: harness.get_json_app_config('editFormats'), before)
    return ok


def reaches_the_editor(ok, fileid, kind):
    print(f'\n==> [3] the {kind} setting reaches the editor')
    before = harness.get_json_app_config('editFormats') or {}

    off = dict(before, **{kind: False})
    harness.set_json_app_config('editFormats', off)
    mode = harness.wait_for(lambda: harness.editor_mode(fileid), 'view')
    print(f'   editing {kind} off -> the connector opens it in {mode!r} mode')
    if mode != 'view':
        ok = False
        print('   FAIL - the setting does not reach the editor at all')

    harness.set_json_app_config('editFormats', dict(before, **{kind: True}))
    mode = harness.wait_for(lambda: harness.editor_mode(fileid), 'edit')
    print(f'   editing {kind} on  -> the connector opens it in {mode!r} mode')
    if mode != 'edit':
        ok = False
        print('   FAIL - the file cannot be edited with its format enabled')

    harness.set_json_app_config('editFormats', before)
    harness.wait_for(lambda: harness.get_json_app_config('editFormats'), before)
    return ok


def main():
    p = argparse.ArgumentParser()
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    args = p.parse_args()
    fileid = args.fileid or config.document(args.kind)['fileid']

    ok = True
    ok = seed(ok)
    ok = no_reversion(ok)
    ok = reaches_the_editor(ok, fileid, args.kind)

    errors = harness.app_log_errors()
    if errors:
        print('\n===== errors the app logged =====')
        for line in errors[:5]:
            print('  ', line[:200])

    print('\n   VERDICT:', 'the format settings hold' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
