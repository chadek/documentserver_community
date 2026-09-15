#!/usr/bin/env python3
"""Check that the onlyoffice format settings are seeded, and then left alone.

`AutoConfig::autoConfigIfNeeded()` runs from `boot()`, so on every request, and
it used to re-apply a hardcoded format list - force-disabling anything the
admin had enabled that the list did not mention. Enabling pdf, docm or html
editing in the settings UI therefore worked until the next page load, which is
as good as not working at all. The list had also gone stale: twelve formats
where the bundled server declares twenty-nine editable, three of the twelve
(doc, ppt, xls) not editable at all.

Four things, in order:

  1. the seed - a fresh install gets every format the bundled server can edit,
     and only the curated set as default openers
  2. no reversion - an admin's own choice survives page loads
  3. the setting reaches the editor - the connector opens a file in view mode
     when its format is not enabled for editing, and in edit mode when it is
  4. the repair - an install configured before any of this gets the corrected
     matrix on upgrade, unless the admin has changed it

No browser needed: what the editor is told is the connector's own config
response, which is a cheaper and less ambiguous reading than sdkjs internals.
"""
import argparse, json, os, re, subprocess, sys

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

# What the old hardcoded seed wrote, and therefore what an install configured
# before this change still carries. Kept here as its own copy rather than
# imported from anywhere: the point of the check is that the repair recognises
# these exact lists, so a test that read them from the code under test would
# agree with it no matter what it said.
LEGACY_DEFAULT = ['doc', 'docx', 'odp', 'ods', 'odt', 'pdf', 'ppt', 'pptx', 'xls', 'xlsx']
LEGACY_EDIT = ['csv', 'doc', 'docx', 'odp', 'ods', 'odt', 'ppt', 'pptx', 'rtf', 'txt',
               'xls', 'xlsx']

INFO_XML = os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', '..',
                        'appinfo', 'info.xml')


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


def write_matrix(default_on, edit_on):
    """The stored matrix as the old code left it: an entry per known format.

    An explicit true or false for every format the connector knows, because
    that is what the old seed wrote - and what the repair has to recognise. A
    map with only the enabled formats in it would read back differently, since
    the connector fills the gaps from its own defaults.
    """
    keys = list((harness.get_json_app_config('defFormats') or {}).keys())
    harness.set_json_app_config('defFormats', {k: k in default_on for k in keys})
    harness.set_json_app_config('editFormats', {k: k in edit_on for k in keys})
    return keys


def enabled(key):
    return sorted(name for name, on in (harness.get_json_app_config(key) or {}).items() if on)


def previous_version():
    """A version below the one in info.xml, so the app's upgrade path runs."""
    version = re.search(r'<version>([^<]+)</version>', open(INFO_XML).read()).group(1)
    parts = [int(part) for part in version.split('.')]
    for i in range(len(parts) - 1, -1, -1):
        if parts[i]:
            parts[i] -= 1
            return '.'.join(str(part) for part in parts)
    raise RuntimeError(f'no version below {version}')


def upgrade_the_app():
    """Run the app's upgrade path, which is what carries its repair steps.

    Repair steps are not part of `occ maintenance:repair` - that runs core's -
    so the only way to reach one is an app whose installed version is behind
    what info.xml declares. Which is also the reason the version has to be
    bumped for any of this to reach a real instance.
    """
    harness.occ('config:app:set', harness.APP_ID, 'installed_version',
                '--value', previous_version(), check=True)
    harness.occ('upgrade', check=True)


def repair(ok):
    print('\n==> [4] an existing install is repaired on upgrade')
    before_default = harness.get_json_app_config('defFormats') or {}
    before_edit = harness.get_json_app_config('editFormats') or {}
    if not before_default:
        print('   FAIL - no stored format matrix to work from')
        return False

    try:
        # (a) an install still carrying exactly what the old seed wrote. Seeding
        # only ever runs while the connector has no url, so without the repair
        # this instance would keep the stale matrix for good.
        write_matrix(LEGACY_DEFAULT, LEGACY_EDIT)
        print(f'   wrote the old hardcoded matrix ({len(LEGACY_EDIT)} editable)')
        upgrade_the_app()

        editable, openers = enabled('editFormats'), enabled('defFormats')
        print(f'   after upgrade: {len(editable)} editable, {len(openers)} default openers')
        if len(editable) < 20:
            ok = False
            print(f'   FAIL - the matrix was not repaired: still {editable}')
        wrong = [name for name in MUST_NOT_EDIT if name in editable]
        if wrong:
            ok = False
            print(f'   FAIL - repair left formats the server cannot edit: {wrong}')
        missing = [name for name in MUST_EDIT if name not in editable]
        if missing:
            ok = False
            print(f'   FAIL - repair left these off: {missing}')
        if openers != sorted(DEFAULT_OPENERS):
            ok = False
            print(f'   FAIL - repair changed the default openers to {openers}')

        # (b) the same install, with one format an admin turned on themselves.
        # The old code force-wrote the matrix on every request, so anything
        # other than the two lists exactly is a choice somebody made after it
        # stopped doing that - and it has to survive.
        customised = sorted(LEGACY_EDIT + ['html'])
        write_matrix(LEGACY_DEFAULT, customised)
        print('   wrote the old matrix plus one format an admin enabled (html)')
        upgrade_the_app()

        editable = enabled('editFormats')
        if editable != customised:
            ok = False
            print(f"   FAIL - the admin's choice was overwritten: {editable}")
        else:
            print(f'   after upgrade: unchanged, {len(editable)} editable')
    finally:
        harness.set_json_app_config('defFormats', before_default)
        harness.set_json_app_config('editFormats', before_edit)
        harness.wait_for(lambda: harness.get_json_app_config('editFormats'), before_edit)
        print('   matrix restored')

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
    ok = repair(ok)

    errors = harness.app_log_errors()
    if errors:
        print('\n===== errors the app logged =====')
        for line in errors[:5]:
            print('  ', line[:200])

    print('\n   VERDICT:', 'the format settings hold' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(main())
