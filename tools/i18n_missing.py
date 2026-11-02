"""List strings of locales/surge.pot that a tools/<lang>.json does not translate yet.
Usage: python tools/i18n_missing.py tr_TR"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from i18n import LOC, parse_po  # noqa: E402

lang = sys.argv[1] if len(sys.argv) > 1 else 'tr_TR'
entries, _ = parse_po(os.path.join(LOC, 'surge.pot'))
path = os.path.join(os.path.dirname(os.path.abspath(__file__)), lang + '.json')
have = json.load(open(path, encoding='utf-8')) if os.path.exists(path) else {}
missing = [(k, p) for k, (p, _) in entries.items() if k not in have]
sys.stdout.reconfigure(encoding='utf-8')
print(f'{len(entries)} strings, {len(missing)} missing in {lang}')
for k, p in missing:
    print(json.dumps(k, ensure_ascii=False) + (f'  ||plural: {json.dumps(p, ensure_ascii=False)}' if p else ''))
