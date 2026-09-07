"""Talking to the rig from outside the browser: occ, the database, the files.

The tests use these to set the app up, to read the state the app keeps, and to
look at what actually landed in the file - which is the only thing that counts
for the saving tests, since the editor saying "all changes are saved" only ever
meant they reached the server.
"""
import base64
import json
import os
import subprocess
import sys
import tempfile
import time
import urllib.request

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import config

APP_ID = 'documentserver_community'
# The onlyoffice connector. Its app config is where the admin settings this app
# has to honour live - the format matrix, the Save button - so the tests read
# and write it as an admin would.
CONNECTOR_ID = 'onlyoffice'


def occ(*args, check=False):
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ', *args],
        capture_output=True, text=True)
    out = (r.stdout + r.stderr).strip()
    if check and r.returncode != 0:
        raise RuntimeError(f'occ {" ".join(args)} failed: {out}')
    return out


def app_exec(command):
    r = subprocess.run(['docker', 'exec', config.APP_CONTAINER, 'sh', '-c', command],
                       capture_output=True, text=True)
    return r.stdout.strip()


def sql(query):
    r = subprocess.run(
        ['docker', 'exec', config.DB_CONTAINER, 'mariadb',
         f'-u{config.DB_USER}', f'-p{config.DB_PASSWORD}', config.DB_NAME,
         '-N', '-B', '-e', query],
        capture_output=True, text=True)
    return r.stdout.strip()


def set_app_config(key, value, app=APP_ID):
    """An app config value, and the wait for it to be visible to web requests.

    occ writes it through the local cache the web server does not share, so a
    test that reads it back straight away sees the old one and concludes the
    setting does nothing.
    """
    occ('config:app:set', app, key, '--value', str(value))


def get_app_config(key, app=APP_ID):
    return occ('config:app:get', app, key)


def delete_app_config(key, app=APP_ID):
    occ('config:app:delete', app, key)


APP_CONFIG_PROPAGATION = 14  # seconds; see set_app_config


def get_json_app_config(key, app=CONNECTOR_ID):
    """A json-valued app config setting, or None when it is not set at all.

    The connector keeps the format matrix like this: one setting holding an
    extension -> bool map.
    """
    raw = get_app_config(key, app)
    try:
        return json.loads(raw)
    except ValueError:
        return None


def set_json_app_config(key, value, app=CONNECTOR_ID):
    set_app_config(key, json.dumps(value, sort_keys=True), app)


def editor_config(fileid, user_pair=None):
    """What the connector decides about a file, as the editor page is told it.

    The connector's own answer, rather than a guess from sdkjs internals: this
    is where a format setting shows up. editorConfig.mode is "view" when it has
    concluded the file is not editable, and document.permissions.edit is only
    the user's write access, not the format decision.
    """
    user, password = config.credentials(user_pair or config.ADMIN)
    # the header outright, not an auth handler: those only send credentials
    # after a challenge, and an unauthenticated OCS call answers 200 with an
    # error envelope rather than the 401 that would trigger one
    credentials = base64.b64encode(f'{user}:{password}'.encode()).decode()
    request = urllib.request.Request(
        f'{config.BASE}/ocs/v2.php/apps/onlyoffice/api/v1/config/{fileid}?format=json',
        headers={'OCS-APIRequest': 'true', 'Authorization': f'Basic {credentials}'})
    with urllib.request.urlopen(request, timeout=30) as response:
        return json.loads(response.read().decode())


def editor_mode(fileid, user_pair=None):
    """"edit" or "view", as the connector would open the file."""
    return editor_config(fileid, user_pair)['editorConfig'].get('mode', 'edit')


def wait_for(reading, expected, timeout=30, interval=2):
    """Poll until a reading matches, and return the last one either way.

    For settings rather than documents: how long a value written with occ takes
    to reach a web request depends on which caches are in play, so a test that
    sleeps a fixed time is either slow or flaky.
    """
    deadline = time.time() + timeout
    while True:
        got = reading()
        if got == expected or time.time() >= deadline:
            return got
        time.sleep(interval)


def reset():
    """Drop per-document state so the next run starts from the file on disk."""
    sql('truncate oc_documentserver_sess; truncate oc_documentserver_changes; '
        'truncate oc_documentserver_locks; truncate oc_documentserver_ipc;')
    app_exec('rm -rf /var/www/html/data/appdata_*/documentserver_community/doc_*')
    # appdata was touched behind Nextcloud's back, so resync the file cache or
    # the next conversion fails with 'Could not create path .../Editor.bin'
    occ('files:scan-app-data')
    app_exec(': > /var/www/html/data/nextcloud.log')


TEMPLATES = ('/var/www/html/custom_apps/documentserver_community/3rdparty/onlyoffice'
             '/documentserver/document-templates/new/en-US')


def restore_document(kind):
    """Put the pristine blank document back.

    The sample files keep whatever earlier runs typed into them, which is a
    problem beyond leftover markers: an empty presentation placeholder takes a
    click and starts editing, one that already has text in it takes the same
    click and selects a shape instead. A test that wants to behave like the
    first time has to start from the file provisioning uploaded.
    """
    document = config.DOCUMENTS[kind]
    user, password = config.credentials(config.ADMIN)
    app_exec(
        f'cp {TEMPLATES}/new.{kind} /tmp/pristine.{kind} && '
        f'curl -s -u {user}:{password} -T /tmp/pristine.{kind} '
        f'"http://localhost/remote.php/dav/files/{user}/{document["name"]}" && '
        f'rm -f /tmp/pristine.{kind}')


def doc_folders():
    """Open documents, ignoring doc_0 - the scratch folder the connector's
    preview conversions go through, which has nothing to do with editing."""
    out = app_exec('ls -d /var/www/html/data/appdata_*/documentserver_community/doc_* '
                   '2>/dev/null')
    return len([line for line in out.split() if not line.endswith('/doc_0')])


def snapshot_state():
    """The {index, time} each open document was last written out at."""
    out = app_exec(
        'for f in /var/www/html/data/appdata_*/documentserver_community/doc_*/snapshot; '
        'do echo "$(dirname $f | sed s#.*/##)=$(cat $f)"; done')
    return out.replace('\n', ' ') or 'none'


def changes():
    return int(sql('select count(*) from oc_documentserver_changes;') or 0)


def sessions():
    return int(sql('select count(*) from oc_documentserver_sess;') or 0)


def drop_sessions():
    sql('delete from oc_documentserver_sess;')


def job_last_run():
    """When the Cleanup background job last ran.

    A rig running background jobs in AJAX mode runs them whenever a browser
    makes a request, and the job writes documents that are being edited too, so
    a test asserting that a file did *not* change has to allow for it.
    """
    return sql("select last_run from oc_jobs where class like "
               "'%DocumentServer%Cleanup';") or '0'


def flush(*options):
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ',
         'documentserver:flush', *options],
        capture_output=True, text=True)
    return r.returncode, (r.stdout + r.stderr).strip()


def run_cleanup_job():
    """Run the Cleanup background job once, whatever the cron mode is."""
    job = sql("select id from oc_jobs where class like '%DocumentServer%Cleanup';")
    if not job:
        return 'no cleanup job registered'
    r = subprocess.run(
        ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER, 'php', 'occ',
         'background-job:execute', job.split()[0], '--force-execute'],
        capture_output=True, text=True)
    return f'exit={r.returncode}'


def download(name, user_pair=None):
    """Fetch a file over WebDAV, returning the local path."""
    user, password = config.credentials(user_pair or config.ADMIN)
    target = os.path.join(tempfile.gettempdir(), f'rig-{user}-{os.path.basename(name)}')
    subprocess.run(['curl', '-s', '-u', f'{user}:{password}',
                    f'{config.BASE}/remote.php/dav/files/{user}/{name}',
                    '-o', target], capture_output=True)
    return target


def file_markers(name, member, markers, user_pair=None):
    """Which of these markers are in the saved file right now."""
    path = download(name, user_pair)
    found = {}
    for marker in markers:
        r = subprocess.run(f'unzip -p {path} {member} 2>/dev/null | grep -c {marker}',
                           shell=True, capture_output=True, text=True)
        found[marker] = (r.stdout.strip() or '0') != '0'
    return found


def app_log(pattern):
    out = app_exec(f"grep -c '{pattern}' /var/www/html/data/nextcloud.log || true")
    return int(out or 0)


def app_log_errors():
    """Error-level log lines from this app, as one string per line."""
    out = app_exec(
        "grep -o '\"level\":[34][^\\n]*documentserver[^\\n]*' "
        "/var/www/html/data/nextcloud.log | tail -20 || true")
    return [line for line in out.split('\n') if line.strip()]
