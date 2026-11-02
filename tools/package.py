"""Surge — release archives: dist/surge-<version>.tgz, .zip and .sha256 (plugin files only: no tests, docs, tools).
Usage: python tools/package.py"""
import hashlib
import os
import re
import tarfile
import zipfile

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
INCLUDE = ['setup.php', 'hook.php', 'LICENSE', 'README.md', 'CHANGELOG.md', 'src', 'front', 'public', 'locales']
version = re.search(r"PLUGIN_SURGE_VERSION',\s*'([^']+)'", open(os.path.join(ROOT, 'setup.php'), encoding='utf-8').read()).group(1)

files = []
for item in INCLUDE:
    p = os.path.join(ROOT, item)
    if os.path.isfile(p):
        files.append(p)
    for base, _, fs in os.walk(p):
        files += [os.path.join(base, f) for f in fs]
files = sorted(set(files))
dist = os.path.join(ROOT, 'dist')
os.makedirs(dist, exist_ok=True)
name = f'surge-{version}'
arc = lambda p: 'surge/' + os.path.relpath(p, ROOT).replace(os.sep, '/')  # noqa: E731
with tarfile.open(os.path.join(dist, name + '.tgz'), 'w:gz') as t:
    for p in files:
        ti = t.gettarinfo(p, arc(p))
        ti.uid = ti.gid = 0
        ti.uname = ti.gname = 'root'
        ti.mode = 0o644
        with open(p, 'rb') as fh:
            t.addfile(ti, fh)
with zipfile.ZipFile(os.path.join(dist, name + '.zip'), 'w', zipfile.ZIP_DEFLATED) as z:
    for p in files:
        z.write(p, arc(p))
with open(os.path.join(dist, name + '.sha256'), 'w', newline='\n') as h:
    for ext in ('.tgz', '.zip'):
        h.write(hashlib.sha256(open(os.path.join(dist, name + ext), 'rb').read()).hexdigest() + f'  {name}{ext}\n')
print(name, len(files), 'files ->', dist)
