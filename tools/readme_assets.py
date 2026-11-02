"""Surge — README header assets (badge strip, tagline, navigation pills), light and dark variants.

  python tools/readme_assets.py      → docs/readme/*.svg   (re-run after a version bump)

Pure SVG with the banner's palette. Text widths are fixed with textLength so the layout does not depend on the fonts
available to GitHub's image proxy.
"""
import os
import re

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'docs', 'readme')
VERSION = re.search(r"PLUGIN_SURGE_VERSION',\s*'([^']+)'", open(os.path.join(ROOT, 'setup.php'), encoding='utf-8').read()).group(1)
FONT = "Segoe UI, Inter, -apple-system, Helvetica, Arial, sans-serif"
ORANGE = '#e8590c'
AMBER = '#ffb347'

THEMES = {
    'dark':  {'bg': '#151b2e', 'bg2': '#1d2440', 'line': '#2e3756', 'text': '#e8ecf4', 'muted': '#97a1b8', 'icon': AMBER},
    'light': {'bg': '#ffffff', 'bg2': '#f6f7fb', 'line': '#dfe3ec', 'text': '#1b2236', 'muted': '#5b6478', 'icon': ORANGE},
}

# 16×16 stroke icons
ICONS = {
    'bolt':     '<path d="M9.5 1.5 4 9h4l-1.5 5.5L12 7H8z" fill="{c}" stroke="none"/>',
    'glpi':     '<rect x="2" y="2.5" width="12" height="11" rx="2.5"/><path d="M5 6.5h6M5 9.5h4"/>',
    'php':      '<path d="M5.5 4 2 8l3.5 4M10.5 4 14 8l-3.5 4"/>',
    'scale':    '<path d="M8 2v12M4 14h8M3 5h10M3 5l-2 5h4zM13 5l-2 5h4z"/>',
    'globe':    '<circle cx="8" cy="8" r="6"/><path d="M2 8h12M8 2c2 2 2 10 0 12M8 2c-2 2-2 10 0 12"/>',
    'shield':   '<path d="M8 1.8 13 4v4c0 3-2.2 5.2-5 6.2C5.2 13.2 3 11 3 8V4z"/><path d="m5.8 8 1.6 1.6L10.4 6.5"/>',
    'bulb':     '<path d="M5.5 10.5C4 9.5 3.5 8 3.5 6.8a4.5 4.5 0 0 1 9 0c0 1.2-.5 2.7-2 3.7v1.5h-5z"/><path d="M6 14h4"/>',
    'sparkles': '<path d="M7 1.8 8.4 5.6 12.2 7 8.4 8.4 7 12.2 5.6 8.4 1.8 7 5.6 5.6z"/><path d="M12.5 10.5v3M11 12h3"/>',
    'camera':   '<rect x="1.8" y="4.5" width="12.4" height="9" rx="2"/><path d="M5.5 4.5 6.5 2.5h3l1 2"/><circle cx="8" cy="9" r="2.4"/>',
    'flow':     '<rect x="1.5" y="2" width="5" height="4" rx="1"/><rect x="9.5" y="10" width="5" height="4" rx="1"/><path d="M4 6v3.5a1.5 1.5 0 0 0 1.5 1.5H9.5"/>',
    'box':      '<path d="M8 1.8 14 5v6l-6 3.2L2 11V5z"/><path d="M2 5l6 3.2L14 5M8 8.2v6"/>',
    'rocket':   '<path d="M9.5 2.2c2.4-.6 4.2-.4 4.3-.3.1.1.3 1.9-.3 4.3L9 10.7 5.3 7z"/><path d="M5.3 7 2.8 7.4 2 9.5l3.3.3M9 10.7l-.4 2.5-2.1.8-.3-3.3"/><circle cx="10.6" cy="5.4" r="1"/>',
    'sliders':  '<path d="M2 4h7M12 4h2M2 8h2M7 8h7M2 12h9M14 12h0"/><circle cx="10.5" cy="4" r="1.5"/><circle cx="5.5" cy="8" r="1.5"/><circle cx="12.5" cy="12" r="1.5"/>',
    'question': '<circle cx="8" cy="8" r="6.2"/><path d="M6.2 6.3a1.9 1.9 0 0 1 3.7.5c0 1.3-1.9 1.6-1.9 2.8"/><circle cx="8" cy="11.6" r=".4" fill="{c}"/>',
    'map':      '<path d="M1.8 4 6 2.2l4 1.8 4.2-1.8V12L10 13.8 6 12l-4.2 1.8z"/><path d="M6 2.2V12M10 4v9.8"/>',
}


def icon(name, x, y, color, scale=1.0):
    body = ICONS[name].replace('{c}', color)
    return (f'<g transform="translate({x} {y}) scale({scale})" fill="none" stroke="{color}" stroke-width="1.6" '
            f'stroke-linecap="round" stroke-linejoin="round">{body}</g>')


def text_w(s, size):
    """Rough advance width (px) for a sans-serif font; used with textLength so rendering stays exact."""
    w = 0.0
    for ch in s:
        if ch in 'il.,:;|!\'·':
            w += 0.28
        elif ch in 'fjrt ()[]':
            w += 0.36
        elif ch in 'mwMW@':
            w += 0.86
        elif ch.isupper() or ch.isdigit() or ch in '+-':
            w += 0.62
        else:
            w += 0.53
    return round(w * size, 1)


def svg(w, h, body, title):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" width="{w}" height="{h}" viewBox="0 0 {w} {h}" role="img" '
            f'aria-label="{title}"><title>{title}</title>{body}</svg>\n')


def write(name, content):
    with open(os.path.join(OUT, name), 'w', encoding='utf-8', newline='\n') as f:
        f.write(content)


def badges(theme):
    t = THEMES[theme]
    items = [
        ('bolt', 'Release', 'v' + VERSION),
        ('glpi', 'GLPI', '11 · 12'),
        ('php', 'PHP', '8.2+'),
        ('scale', 'License', 'GPL-3.0'),
        ('globe', 'Languages', '6'),
        ('shield', 'External calls', 'none'),
    ]
    h, gap, x, parts = 34, 10, 0.0, []
    for ic, label, value in items:
        lw, vw = text_w(label, 12.5), round(text_w(value, 12.5) * 1.1, 1)  # bold value is wider
        w = 12 + 16 + 8 + lw + 10 + vw + 14
        sep = 12 + 16 + 8 + lw + 5
        parts.append(
            f'<g transform="translate({x:.1f} 0)">'
            f'<rect x=".75" y=".75" width="{w - 1.5:.1f}" height="{h - 1.5}" rx="{(h - 1.5) / 2}" fill="{t["bg"]}" stroke="{t["line"]}" stroke-width="1.5"/>'
            f'{icon(ic, 12, 9, t["icon"])}'
            f'<text x="36" y="21.5" font-family="{FONT}" font-size="12.5" fill="{t["muted"]}" textLength="{lw}" lengthAdjust="spacingAndGlyphs">{label}</text>'
            f'<line x1="{sep:.1f}" y1="10" x2="{sep:.1f}" y2="{h - 10}" stroke="{t["line"]}" stroke-width="1.5"/>'
            f'<text x="{sep + 5:.1f}" y="21.5" font-family="{FONT}" font-size="12.5" font-weight="700" fill="{t["text"]}" textLength="{vw}" lengthAdjust="spacingAndGlyphs">{value}</text>'
            f'</g>')
        x += w + gap
    total = round(x - gap + 2)
    write(f'badges-{theme}.svg', svg(total, h, ''.join(parts), f'Surge {VERSION} · GLPI 11 and 12 · PHP 8.2+ · GPL-3.0 · English and Turkish · no external calls'))


def tagline(theme):
    t = THEMES[theme]
    a = 'Spot outages from the ticket queue while they are happening'
    b = 'and handle thirty tickets as one incident.'
    size = 21
    wa, wb = text_w(a, size), text_w(b, size)
    w = round(max(wa, wb) + 40)
    body = (f'<defs><linearGradient id="g" x1="0" y1="0" x2="1" y2="0"><stop offset="0" stop-color="{AMBER}"/>'
            f'<stop offset="1" stop-color="{ORANGE}"/></linearGradient></defs>'
            f'<text x="{w / 2}" y="30" text-anchor="middle" font-family="{FONT}" font-size="{size}" fill="{t["text"]}" textLength="{wa}" lengthAdjust="spacingAndGlyphs">{a} —</text>'
            f'<text x="{w / 2}" y="64" text-anchor="middle" font-family="{FONT}" font-size="{size}" font-weight="700" fill="url(#g)" textLength="{wb}" lengthAdjust="spacingAndGlyphs">{b}</text>'
            f'<rect x="{w / 2 - 28}" y="80" width="56" height="4" rx="2" fill="url(#g)"/>')
    write(f'tagline-{theme}.svg', svg(w, 90, body, a + ' — ' + b))


NAV = [
    ('why', 'bulb', 'Why'),
    ('features', 'sparkles', 'Features'),
    ('how', 'flow', 'How it works'),
    ('install', 'box', 'Install'),
    ('config', 'sliders', 'Configuration'),
    ('faq', 'question', 'FAQ'),
    ('roadmap', 'map', 'Roadmap'),
]


def nav(theme):
    t = THEMES[theme]
    for key, ic, label in NAV:
        size = 12.5
        lw = round(text_w(label, size) * 1.06, 1)  # semi-bold
        w, h = round(10 + 15 + 6 + lw + 11), 32
        body = (f'<rect x=".75" y=".75" width="{w - 1.5}" height="{h - 1.5}" rx="9" fill="{t["bg2"]}" stroke="{t["line"]}" stroke-width="1.5"/>'
                f'{icon(ic, 10, 8.5, t["icon"], 0.94)}'
                f'<text x="31" y="20.5" font-family="{FONT}" font-size="{size}" font-weight="600" fill="{t["text"]}" textLength="{lw}" lengthAdjust="spacingAndGlyphs">{label}</text>')
        write(f'nav-{key}-{theme}.svg', svg(w, h, body, label))


def main():
    os.makedirs(OUT, exist_ok=True)
    for theme in THEMES:
        badges(theme)
        tagline(theme)
        nav(theme)
    print(f'docs/readme: {len(os.listdir(OUT))} files (v{VERSION})')


if __name__ == '__main__':
    main()
