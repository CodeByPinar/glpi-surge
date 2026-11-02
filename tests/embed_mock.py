"""Fake OpenAI-compatible /v1/embeddings server for tests: maps words to a few "concepts" (so paraphrases without
shared words get the same vector) and hashes concepts into a 64-dim vector. Usage: python3 embed_mock.py 18082"""
import hashlib
import json
import math
import re
import sys
from http.server import BaseHTTPRequestHandler, HTTPServer

CONCEPTS = {
    'remote': ['vpn', 'remote', 'uzaktan', 'tunnel'],
    'auth': ['log', 'login', 'logon', 'sign', 'authentication', 'authenticate', 'credentials', 'password', 'giris', 'kimlik'],
    'fail': ['cannot', "can't", 'unable', 'failed', 'fails', 'error', 'denied', 'yapamiyorum', 'basarisiz'],
    'printer': ['printer', 'print', 'toner', 'yazici'],
}
WORD = {w: c for c, ws in CONCEPTS.items() for w in ws}


def embed(text):
    v = [0.0] * 64
    for w in re.findall(r"[a-z']+", text.lower()):
        c = WORD.get(w)
        if not c:
            continue
        h = int(hashlib.md5(c.encode()).hexdigest(), 16)
        v[h % 64] += 1.0
        v[(h // 64) % 64] += 0.5
    n = math.sqrt(sum(x * x for x in v)) or 1.0
    return [x / n for x in v]


class H(BaseHTTPRequestHandler):
    def do_POST(self):
        body = json.loads(self.rfile.read(int(self.headers.get('Content-Length', 0))) or b'{}')
        inputs = body.get('input', [])
        inputs = inputs if isinstance(inputs, list) else [inputs]
        out = json.dumps({'object': 'list', 'model': body.get('model'),
                          'data': [{'object': 'embedding', 'index': i, 'embedding': embed(t)} for i, t in enumerate(inputs)]}).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json')
        self.send_header('Content-Length', str(len(out)))
        self.end_headers()
        self.wfile.write(out)

    def log_message(self, *a):
        pass


HTTPServer(('127.0.0.1', int(sys.argv[1] if len(sys.argv) > 1 else 18082)), H).serve_forever()
