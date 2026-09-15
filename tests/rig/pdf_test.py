#!/usr/bin/env python3
"""Converting to PDF puts the text in the PDF, and a font rebuild that did not
rebuild says so.

Exporting to PDF was blank for four years (#371, #251, #287) because
`font_selection.bin` holds the absolute path of every bundled font as they were
on the machine that ran allfontsgen - the build machine, for an app store
install. When a path does not resolve x2t prints "Can't load fontfile" on a
stream nobody reads, writes a PDF with no glyphs in it, and exits 0. From
everywhere else in the system that is a successful conversion, which is why
nothing caught it: same exit code, same absence of errors, 1.9 KB instead of
12.7 KB.

So the assertions here are the ones nothing else makes:

- a converted PDF contains the text that went in, and embeds a font
- a font whose path does not resolve reaches the log instead of nowhere
- a rebuild that cannot write its output fails loudly, rather than printing
  "rebuilding" and exiting 0 having changed nothing - the shape of #145 and
  #278, and of the rig's own bind mount
"""
import argparse
import hashlib
import os
import shutil
import subprocess
import sys
import tempfile
import zipfile

sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), 'lib'))
import config
import harness

MARKER = 'PdfFontProbe'
# What ConverterBinary looks for on x2t's stdout, and the start of what it logs.
MISSING_FONT_LOG = 'could not load'

SELECTION = os.path.join(config.APP_SOURCE, config.CONVERTER_BIN, 'font_selection.bin')
WORK = '/tmp/pdfrig'


def make_docx(path, marker):
    """A document with real typed text in it, in a bundled font."""
    content_types = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        '<Default Extension="xml" ContentType="application/xml"/>'
        '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-'
        'officedocument.wordprocessingml.document.main+xml"/></Types>')
    rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/'
        'relationships/officeDocument" Target="word/document.xml"/></Relationships>')
    document = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        '<w:body><w:p><w:r><w:rPr><w:rFonts w:ascii="Liberation Serif" w:hAnsi="Liberation Serif"/>'
        f'<w:sz w:val="40"/></w:rPr><w:t>{marker}</w:t></w:r></w:p></w:body></w:document>')

    with zipfile.ZipFile(path, 'w', zipfile.ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', content_types)
        z.writestr('_rels/.rels', rels)
        z.writestr('word/document.xml', document)


def convert_to_pdf(marker):
    """Convert a docx to PDF through the app's own converter, and bring it back.

    Through DocumentConverter rather than by calling x2t directly: the point is
    that what the app does with a document produces a readable PDF, and it is
    also what puts a missing font in the log.
    """
    local = os.path.join(tempfile.gettempdir(), f'rig-{marker}.docx')
    make_docx(local, marker)

    harness.app_exec(f'mkdir -p {WORK} && chmod 777 {WORK}')
    subprocess.run(['docker', 'cp', local, f'{config.APP_CONTAINER}:{WORK}/in.docx'],
                   capture_output=True)
    harness.app_exec(f'rm -f {WORK}/out.pdf')

    out = harness.php_eval(f"""<?php
use OCA\\DocumentServer\\Document\\DocumentFormat;
$converter = \\OC::$server->get(\\OCA\\DocumentServer\\DocumentConverter::class);
try {{
    $converter->convertFiles('{WORK}/in.docx', '{WORK}/out.pdf',
        DocumentFormat::AVS_OFFICESTUDIO_FILE_CROSSPLATFORM_PDF);
    echo "converted\\n";
}} catch (\\Throwable $e) {{
    echo "FAILED: ", $e->getMessage(), "\\n";
}}
""")

    pdf = os.path.join(tempfile.gettempdir(), f'rig-{marker}.pdf')
    with open(pdf, 'wb') as f:
        r = subprocess.run(['docker', 'exec', config.APP_CONTAINER, 'cat', f'{WORK}/out.pdf'],
                           stdout=f, stderr=subprocess.DEVNULL)
    return out, (pdf if r.returncode == 0 and os.path.getsize(pdf) else None)


def pdf_text(path):
    r = subprocess.run(['pdftotext', path, '-'], capture_output=True, text=True)
    return r.stdout


def pdf_embeds_a_font(path):
    """Whether the PDF carries a font program at all.

    The backstop for the text check: a PDF x2t wrote with no fonts loaded has
    no /FontFile2 in it, and this needs nothing installed to see that.
    """
    with open(path, 'rb') as f:
        return b'/FontFile2' in f.read()


def check_conversion():
    print('== a converted PDF has the text in it')
    before = harness.app_log(MISSING_FONT_LOG)
    out, pdf = convert_to_pdf(MARKER)

    if pdf is None:
        print(f'   FAIL - no PDF came out: {out}')
        return False

    ok = True
    size = os.path.getsize(pdf)
    text = pdf_text(pdf)
    if MARKER not in text:
        ok = False
        print(f'   FAIL - "{MARKER}" is not in the {size} byte PDF; '
              f'extracted {len(text.split())} word(s)')
    else:
        print(f'   ok - "{MARKER}" is in the {size} byte PDF')

    if not pdf_embeds_a_font(pdf):
        ok = False
        print('   FAIL - the PDF embeds no font program, so x2t loaded no fonts')

    after = harness.app_log(MISSING_FONT_LOG)
    if after > before:
        ok = False
        print(f'   FAIL - x2t reported {after - before} font(s) it could not load')

    return ok


def check_missing_font_is_reported():
    """The other half: when a path really does not resolve, somebody says so.

    Without this the log line added for it is untested, and a blank PDF stays
    indistinguishable from a good one.
    """
    print('== a font path that does not resolve reaches the log')
    original = open(SELECTION, 'rb').read()
    digest = hashlib.sha256(original).hexdigest()

    try:
        # Same length, so every length prefix in the file stays correct - only
        # the path stops existing.
        broken = original.replace(b'../../../core-fonts/', b'../../../core-f0nts/')
        if broken == original:
            print('   FAIL - no pinned font paths in font_selection.bin to break')
            return False
        with open(SELECTION, 'wb') as f:
            f.write(broken)

        before = harness.app_log(MISSING_FONT_LOG)
        _, pdf = convert_to_pdf(MARKER + 'Broken')
        after = harness.app_log(MISSING_FONT_LOG)

        if after <= before:
            print('   FAIL - x2t loaded no fonts and nothing was logged')
            return False
        if pdf and MARKER in pdf_text(pdf):
            print('   FAIL - the text survived a font that cannot be loaded, '
                  'so this check proves nothing')
            return False
        print('   ok - logged, and the PDF came out blank as expected')
        return True
    finally:
        with open(SELECTION, 'wb') as f:
            f.write(original)
        restored = hashlib.sha256(open(SELECTION, 'rb').read()).hexdigest()
        if restored != digest:
            print(f'   !! font_selection.bin was NOT restored ({restored} != {digest})')


def check_rebuild_that_cannot_write_fails():
    print('== a font rebuild that cannot write says so')
    mode = os.stat(SELECTION).st_mode
    try:
        os.chmod(SELECTION, 0o444)
        r = subprocess.run(
            ['docker', 'exec', '-u', 'www-data', config.APP_CONTAINER,
             'php', 'occ', 'documentserver:fonts', '--rebuild'],
            capture_output=True, text=True)
        out = (r.stdout + r.stderr).strip()

        if r.returncode == 0:
            print(f'   FAIL - exited 0 with the font list read-only: {out}')
            return False
        if 'not writable' not in out:
            print(f'   FAIL - exited {r.returncode} but did not say what was wrong: {out}')
            return False
        print(f'   ok - exit {r.returncode}, and it named the file')
        return True
    finally:
        os.chmod(SELECTION, mode)


def main():
    p = argparse.ArgumentParser()
    p.parse_args()

    if not shutil.which('pdftotext'):
        print('pdftotext is needed to read the converted PDF (apt install poppler-utils)')
        return 2
    if not os.path.isfile(SELECTION):
        print(f'{SELECTION} is missing: run make in the app directory first')
        return 2

    results = {
        'conversion': check_conversion(),
        'reported': check_missing_font_is_reported(),
        'rebuild': check_rebuild_that_cannot_write_fails(),
    }
    harness.app_exec(f'rm -rf {WORK}')

    print('\n   VERDICT:', ' '.join(f'{k}={"ok" if v else "FAIL"}' for k, v in results.items()))
    return 0 if all(results.values()) else 1


if __name__ == '__main__':
    sys.exit(main())
