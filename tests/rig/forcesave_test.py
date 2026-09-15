#!/usr/bin/env python3
"""Press the editor's Save button and check the save from every angle.

"Keep intermediate versions when editing" sets
editorConfig.customization.forcesave, which gives the editor a Save button.
Pressing it sends a forceSaveStart command the dispatcher had no handler for,
so the button did nothing and the setting looked broken from the settings UI.

The reply is in two parts and the editor is fussy about both: it stores the
timestamp from forceSaveStart and ignores a forceSave whose time does not
match, so a mismatch leaves the button spinning until the conversion timeout.
Pressing Save with nothing typed since the last write is a state of its own
(NotModified), not a failure.

And the save has to be a snapshot, not a flush. Writing the file while
consuming the change list looks fine if you only download the file - the bytes
are right at that moment - and breaks everything after: the change list is the
whole record of what was typed, Editor.bin stays at the version the document
was opened at, so a session opening next renders the document from before the
save and the eventual flush writes a file that has lost the earlier edits. So
after the save this checks, in order: what the editor was told, the file on
disk, a freshly opened session, and the file again once everyone has left.
"""
import argparse, asyncio, json, os, sys, time

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness
from driver import Session, DISMISS_JS, TEXT_JS

# The real Save button path, not CoAuthoringApi.forceSave() directly: with
# autosave off and the "Keep intermediate versions" customization on,
# asc_Save(false) is what the toolbar button and Ctrl+S run, and it only reaches
# forceSave() through isForceSaveOnUserSave. Calling forceSave() straight would
# hide the setting not arriving.
SAVE_JS = r"""(() => {
  try {
    const w = document.querySelector('iframe').contentWindow;
    const api = w.Asc.editor;
    const inner = api.CoAuthoringApi._CoAuthoringApi;
    if (!w.__rigForceSave) {
      w.__rigForceSave = [];
      const orig = inner.onForceSave;
      inner.onForceSave = function (e) { w.__rigForceSave.push(e); if (orig) orig.call(this, e); };
    }
    w.__rigForceSave.length = 0;
    api.asc_setAutoSaveGap(0);
    const res = api.asc_Save(false);
    return JSON.stringify({isForceSaveOnUserSave: api.isForceSaveOnUserSave,
                           docCanSave: api.asc_isDocumentCanSave(), ascSave: res});
  } catch (e) { return 'err: ' + e; }
})()"""

EVENTS_JS = r"""(() => {
  const w = document.querySelector('iframe').contentWindow;
  return JSON.stringify(w.__rigForceSave || 'no recorder');
})()"""


def markers(document, names):
    return harness.file_markers(document['name'], document['member'], names)


async def press_save(session, what):
    print(f'\n==> pressing Save {what}:', await session.eval(SAVE_JS))
    await session.drain(25)
    events = json.loads(await session.eval(EVENTS_JS))
    print('   the editor was told:', events)
    return events


async def main():
    p = argparse.ArgumentParser()
    p.add_argument('--base', default=config.BASE)
    p.add_argument('--a', default=config.ADMIN)
    p.add_argument('--kind', default='docx', choices=sorted(config.DOCUMENTS))
    p.add_argument('--fileid', default=None)
    p.add_argument('--chromium', default=config.CHROMIUM)
    p.add_argument('--tag', default='SAVE')
    args = p.parse_args()
    document = config.DOCUMENTS[args.kind]
    fileid = args.fileid or config.document(args.kind)['fileid']

    ua, pa = args.a.split(':', 1)
    # unique per run: the sample file keeps what earlier runs typed into it, and
    # a leftover marker looks exactly like one that was just written
    t = args.tag + str(int(time.time()) % 100000)
    before, after = t + 'a', t + 'b'
    ok = True

    print('==> resetting rig state')
    harness.reset()
    # from the blank document, not whatever earlier runs left in the sample:
    # this test types twice, and a second click into a document that already
    # has text in it lands in the middle of it rather than after it
    harness.restore_document(args.kind)
    # the setting whose button this is, and no autosave, so nothing but the
    # button can put the text in the file
    harness.set_app_config('customizationForcesave', 'true', harness.CONNECTOR_ID)
    harness.set_app_config('autosave_interval', 0)
    forcesave = harness.wait_for(
        lambda: harness.editor_config(fileid)['editorConfig']['customization'].get('forcesave'),
        True)
    print('   forcesave in the editor config:', forcesave)
    if forcesave is not True:
        harness.delete_app_config('autosave_interval')
        print('   FAIL - the editor will not even have a Save button')
        return 1
    time.sleep(harness.APP_CONFIG_PROPAGATION)
    # a rig with background jobs in AJAX mode runs the Cleanup job whenever a
    # browser makes a request, and that job writes live documents too - so "the
    # file has not been written yet" has to be checked against it
    job_before = harness.job_last_run()

    A = Session('A', 9222, ua, pa, args.base, fileid, args.chromium)
    try:
        await A.start()
        print(f'==> opening file {fileid} as {ua}')
        await A.login_and_open()
        await A.drain(25)
        await A.eval(DISMISS_JS)
        await A.type(before + ' ', row_offset=0)
        await A.drain(8)

        got = markers(document, [before])
        print(f'   file before any save: {got}')
        if got[before]:
            if harness.job_last_run() != job_before:
                print('   (the Cleanup job ran in this window and wrote it; the '
                      'button is not the only writer this run)')
            else:
                ok = False
                print('   FAIL - something else wrote the file, with autosave off '
                      'and the job not having run')

        events = await press_save(A, 'once')
        # {start: true} then {success: true}, both for the Button type: the two
        # halves of the reply, and the second only arrives if its timestamp
        # matches the one the first handed over
        if not any(e.get('start') for e in events):
            ok = False
            print('   FAIL - the editor was never told the save started; the '
                  'command was dropped')
        if not any(e.get('success') for e in events):
            ok = False
            print('   FAIL - no forceSave reply the editor accepted: a missing '
                  'reply, or a timestamp that did not match forceSaveStart')

        got = markers(document, [before])
        print(f'\n   1. file on disk right after the save: {got}')
        if not got[before]:
            ok = False
            print('      FAIL - the save did not reach the file')

        print('\n==> pressing Save again with nothing typed since')
        events = await press_save(A, 'with nothing to save')
        if any(e.get('success') is False for e in events):
            ok = False
            print('      FAIL - a redundant save is reported as an error')
        if not any(e.get('refuse') for e in events):
            print('      (not answered as NotModified; harmless as long as it '
                  'is not an error)')

        print('\n==> typing again, which is what makes consuming the change '
              'list dangerous')
        await A.type(after + ' ', row_offset=1)
        await A.drain(8)
        await press_save(A, 'after the second edit')

        print('\n==> a second session opens the document while the first still edits')
        B = Session('B', 9333, ua, pa, args.base, fileid, args.chromium)
        try:
            await B.start()
            await B.login_and_open()
            await B.drain(25)
            text = str(await B.eval(TEXT_JS) or '')
            missing = [m for m in (before, after) if m not in text]
            print(f'   2. the new session renders: missing {missing or "nothing"}')
            if missing:
                ok = False
                print('      FAIL - it shows the document from before the save; '
                      'the change list was consumed')
                print('      it renders:', text[:160])
        finally:
            await B.stop()
    finally:
        await A.stop()

    print('\n==> everyone has left; expiring the sessions and flushing as cron would')
    harness.drop_sessions()
    code, out = harness.flush()
    print(f'   documentserver:flush exit={code} {out}')
    if code != 0:
        ok = False
        print('      FAIL - the flush failed')

    got = markers(document, [before, after])
    print(f'   3. file on disk after the flush: {got}')
    # the pre-save edit is the one at risk: a flush replaying a change list that
    # no longer holds it, against a baseline that was never rebased, drops it
    if not all(got.values()):
        ok = False
        print('      FAIL - the flush lost an edit the Save button had written')

    harness.delete_app_config('autosave_interval')

    errors = harness.app_log_errors()
    if errors:
        print('\n===== errors the app logged =====')
        for line in errors[:5]:
            print('  ', line[:200])

    print('\n   VERDICT:', 'the Save button saves' if ok else 'FAIL')
    return 0 if ok else 1


if __name__ == '__main__':
    sys.exit(asyncio.run(main()))
