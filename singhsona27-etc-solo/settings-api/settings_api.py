from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from urllib.parse import urlparse

CONFIG_PATH = "/config/app.env"

DEFAULTS = {
    "ETC_COINBASE": "",
    "ETC_STRATUM_PORT": "8008",
    "ETC_HTTP_MINING_PORT": "8888",
    "ETC_P2P_PORT": "30303",
    "GETH_CACHE_MB": "4096",
    "GETH_MAX_PEERS": "100",
    "ETC_SHARE_DIFFICULTY": "8250000000",
    "ETC_POOL_THREADS": "4",
    "ETC_BLOCK_REFRESH": "120ms",
    "ETC_STATE_REFRESH": "2s",
}

def read_config():
    values = dict(DEFAULTS)
    if os.path.exists(CONFIG_PATH):
        with open(CONFIG_PATH, "r", encoding="utf-8") as f:
            for line in f:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                if key in DEFAULTS:
                    values[key] = value
    return values

def write_config(values):
    merged = dict(DEFAULTS)
    for key, value in values.items():
        if key in DEFAULTS:
            merged[key] = str(value).strip()
    os.makedirs(os.path.dirname(CONFIG_PATH), exist_ok=True)
    with open(CONFIG_PATH, "w", encoding="utf-8") as f:
        for key in DEFAULTS:
            f.write(f"{key}={merged[key]}\n")
    return merged

class Handler(BaseHTTPRequestHandler):
    def send_json(self, status, payload):
        data = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.send_header("Content-Length", str(len(data)))
        self.end_headers()
        self.wfile.write(data)

    def do_GET(self):
        if urlparse(self.path).path != "/config":
            self.send_json(404, {"error": "not found"})
            return
        self.send_json(200, read_config())

    def do_POST(self):
        if urlparse(self.path).path != "/config":
            self.send_json(404, {"error": "not found"})
            return
        length = int(self.headers.get("Content-Length", "0"))
        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
        except json.JSONDecodeError:
            self.send_json(400, {"error": "invalid json"})
            return
        config = write_config(payload)
        self.send_json(200, {"config": config, "restartRequired": True})

    def log_message(self, fmt, *args):
        return

ThreadingHTTPServer(("0.0.0.0", 8090), Handler).serve_forever()
