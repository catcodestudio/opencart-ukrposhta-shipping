"""Build ukrposhta.ocmod.zip from upload/.

The archive name must equal the extension code (`ukrposhta`) and install.json
must sit at the archive ROOT with the file tree beside it — no `upload/`
wrapper. Paths are written with forward slashes so the OpenCart installer can
read the archive when it is built on Windows.
"""
import os
import zipfile

OUT = 'ukrposhta.ocmod.zip'
ROOT = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.join(ROOT, 'upload')
SKIP_EXT = ('.zip', '.py', '.pyc')

with zipfile.ZipFile(os.path.join(ROOT, OUT), 'w', zipfile.ZIP_DEFLATED) as z:
    for base, dirs, files in os.walk(SRC):
        dirs[:] = [d for d in dirs if d not in ('.git', '__pycache__')]
        for f in sorted(files):
            if f.endswith(SKIP_EXT):
                continue
            full = os.path.join(base, f)
            arc = os.path.relpath(full, SRC).replace(os.sep, '/')
            z.write(full, arc)

print(OUT, os.path.getsize(os.path.join(ROOT, OUT)), 'bytes')
