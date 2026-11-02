"""Surge — translations.

  python tools/i18n.py          extract strings → locales/surge.pot, merge into every locales/*.po, compile *.mo

Strings are found in __('…', 'surge'), __s('…', 'surge'), _x('ctx', '…', 'surge') and _n('one', 'many', n, 'surge').
New strings are added to each .po with an empty translation (GLPI then shows the English text); obsolete ones are kept
at the end as comments so no work is lost. No external tools needed (pure Python .mo writer).
"""
import ast
import os
import re
import struct
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LOC = os.path.join(ROOT, 'locales')
DOMAIN = 'surge'
STR = r"'((?:[^'\\]|\\.)*)'"
PATTERNS = [
    (re.compile(r"\b__s?\(\s*" + STR + r"\s*,\s*'surge'\s*\)"), 'single'),
    (re.compile(r"\b_n\(\s*" + STR + r"\s*,\s*" + STR + r"\s*,[^,]+,\s*'surge'\s*\)"), 'plural'),
]


def php_unquote(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def extract():
    found = {}  # msgid -> (plural or None, [refs])
    for base, dirs, files in os.walk(ROOT):
        dirs[:] = [d for d in dirs if d not in ('.git', 'vendor', 'node_modules', 'tests', 'docs')]
        for f in sorted(files):
            if not f.endswith('.php'):
                continue
            p = os.path.join(base, f)
            rel = os.path.relpath(p, ROOT).replace(os.sep, '/')
            src = open(p, encoding='utf-8').read()
            for rx, kind in PATTERNS:
                for m in rx.finditer(src):
                    line = src.count('\n', 0, m.start()) + 1
                    mid = php_unquote(m.group(1))
                    plural = php_unquote(m.group(2)) if kind == 'plural' else None
                    e = found.setdefault(mid, [plural, []])
                    if plural:
                        e[0] = plural
                    e[1].append(f'{rel}:{line}')
    return found


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def parse_po(path):
    """→ {msgid: (msgid_plural, [msgstr…])}"""
    out = {}
    if not os.path.exists(path):
        return out, ''
    cur = {}
    key = None
    header = ''

    def flush():
        if 'msgid' in cur:
            if cur['msgid'] == '':
                nonlocal header
                header = cur.get('msgstr[0]', cur.get('msgstr', ''))
            else:
                strs = [cur[k] for k in sorted(k for k in cur if k.startswith('msgstr'))]
                out[cur['msgid']] = (cur.get('msgid_plural'), strs)
    for raw in open(path, encoding='utf-8'):
        line = raw.strip()
        if not line or line.startswith('#'):
            if not line and cur:
                flush()
                cur = {}
            continue
        m = re.match(r'^(msgid_plural|msgid|msgstr(?:\[\d\])?)\s+(".*")$', line)
        if m:
            key = m.group(1)
            cur[key] = ast.literal_eval(m.group(2))
        elif line.startswith('"') and key:
            cur[key] += ast.literal_eval(line)
    if cur:
        flush()
    return out, header


def write_po(path, lang, entries, old, header, nplurals):
    lines = []
    if not header:
        header = (f'Project-Id-Version: Surge\nLanguage: {lang}\nMIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n'
                  f'Content-Transfer-Encoding: 8bit\nPlural-Forms: {nplurals}\n')
    lines.append('msgid ""')
    lines.append('msgstr ""')
    for h in header.splitlines():
        lines.append(f'"{po_escape(h)}\\n"')
    lines.append('')
    for mid, (plural, refs) in entries.items():
        lines.append('#: ' + ' '.join(refs[:4]))
        lines.append(f'msgid "{po_escape(mid)}"')
        prev = old.get(mid, (None, []))[1]
        if plural:
            lines.append(f'msgid_plural "{po_escape(plural)}"')
            n = 2 if 'nplurals=2' in nplurals else 1
            for i in range(n):
                lines.append(f'msgstr[{i}] "{po_escape(prev[i] if i < len(prev) else "")}"')
        else:
            lines.append(f'msgstr "{po_escape(prev[0] if prev else "")}"')
        lines.append('')
    gone = [k for k in old if k not in entries]
    for mid in gone:
        lines.append(f'#~ msgid "{po_escape(mid)}"')
        lines.append(f'#~ msgstr "{po_escape(old[mid][1][0] if old[mid][1] else "")}"')
        lines.append('')
    open(path, 'w', encoding='utf-8', newline='\n').write('\n'.join(lines))


def write_mo(po_path, mo_path):
    entries, header = parse_po(po_path)
    cat = {'': header}
    for mid, (plural, strs) in entries.items():
        if not any(strs):
            continue
        if plural:
            cat[mid + '\0' + plural] = '\0'.join(strs)
        else:
            cat[mid] = strs[0]
    keys = sorted(cat)
    ids = b''
    strs = b''
    offs = []
    for k in keys:
        kb, vb = k.encode('utf-8'), cat[k].encode('utf-8')
        offs.append((len(ids), len(kb), len(strs), len(vb)))
        ids += kb + b'\0'
        strs += vb + b'\0'
    n = len(keys)
    kstart = 7 * 4 + 16 * n
    vstart = kstart + len(ids)
    out = struct.pack('Iiiiiii', 0x950412de, 0, n, 7 * 4, 7 * 4 + 8 * n, 0, 0)
    out += b''.join(struct.pack('ii', l, o + kstart) for o, l, _, _ in offs)
    out += b''.join(struct.pack('ii', l, o + vstart) for _, _, o, l in offs)
    open(mo_path, 'wb').write(out + ids + strs)
    return sum(1 for k in keys if k)


def main():
    os.makedirs(LOC, exist_ok=True)
    found = extract()
    entries = dict(sorted(found.items(), key=lambda kv: kv[1][1][0]))
    write_po(os.path.join(LOC, DOMAIN + '.pot'), '', entries, {}, '', 'nplurals=2; plural=(n != 1);')
    langs = {'tr_TR': 'nplurals=2; plural=(n > 1);', 'en_GB': 'nplurals=2; plural=(n != 1);', 'fr_FR': 'nplurals=2; plural=(n > 1);',
             'de_DE': 'nplurals=2; plural=(n != 1);', 'es_ES': 'nplurals=2; plural=(n != 1);', 'pt_BR': 'nplurals=2; plural=(n > 1);'}
    for f in os.listdir(LOC):
        if f.endswith('.po'):
            langs.setdefault(f[:-3], 'nplurals=2; plural=(n != 1);')
    for lang, plural in langs.items():
        po = os.path.join(LOC, lang + '.po')
        old, header = parse_po(po)
        # tools/<lang>.json fills translations that are still empty in the .po (source of truth for this repo)
        js = os.path.join(ROOT, 'tools', lang + '.json')
        if os.path.exists(js):
            import json
            ph = re.compile(r'%(?:\d\$)?[-+ 0#]*\d*(?:\.\d+)?[dsf%]')
            for mid, tr in json.load(open(js, encoding='utf-8')).items():
                for t in (tr if isinstance(tr, list) else [tr]):
                    if sorted(ph.findall(mid)) != sorted(ph.findall(t)) and not (isinstance(tr, list) and t is tr[0]):
                        print(f'  ! {lang}: placeholders differ: {mid!r} -> {t!r}')
                if mid in entries and not any(old.get(mid, (None, []))[1]):
                    old[mid] = (entries[mid][0], tr if isinstance(tr, list) else [tr])
        write_po(po, lang, entries, old, header, plural)
        n = write_mo(po, os.path.join(LOC, lang + '.mo'))
        print(f'{lang}: {n}/{len(entries)} translated')
    print(f'{len(entries)} strings')


if __name__ == '__main__':
    sys.exit(main())
