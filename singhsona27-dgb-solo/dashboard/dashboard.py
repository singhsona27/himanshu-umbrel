from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib import request
import base64
import json
import math
import os
import re
import threading
import time

RPC_URL = os.environ.get("RPC_URL", "http://dgb-digibyte:14022/")
RPC_USER = os.environ.get("RPC_USER", "")
RPC_PASSWORD = os.environ.get("RPC_PASSWORD", "")
TITLE = os.environ.get("DASHBOARD_TITLE", "DGB SHA256 Solo Pool")
STRATUM_PORT = os.environ.get("STRATUM_PORT", "3355")
EXPECTED_HASHRATE_TH = float(os.environ.get("EXPECTED_HASHRATE_TH", "97") or 97)
MINING_ADDRESS = os.environ.get("DGB_MINING_ADDRESS", "")
LOG_DIR = os.environ.get("CKPOOL_LOG_DIR", "/logs")
CACHE_SECONDS = float(os.environ.get("DASHBOARD_CACHE_SECONDS", "5") or 5)
PRICE_CACHE_SECONDS = float(os.environ.get("PRICE_CACHE_SECONDS", "180") or 180)
BLOCK_SCAN_LIMIT = int(os.environ.get("BLOCK_SCAN_LIMIT", "180") or 180)
BLOCK_REWARD_DGB = float(os.environ.get("DGB_BLOCK_REWARD", "62.5") or 62.5)
EXPLORER_BLOCK_URL = os.environ.get("EXPLORER_BLOCK_URL", "https://blockchair.com/digibyte/block/{height}")
ROOT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
CONFIG_PATH = os.path.join(ROOT_DIR, ".env")

DEFAULTS = {
    "DGB_MINING_ADDRESS": "",
    "STRATUM_PORT": "3355",
    "DASHBOARD_PORT": "8095",
    "DGB_P2P_PORT": "12024",
    "DIGIBYTE_VERSION": "8.26.2",
    "RPC_USER": "dgbrpc",
    "RPC_PASSWORD": "",
    "DGB_DBCACHE_MB": "6144",
    "DGB_PAR": "3",
    "DGB_RPC_THREADS": "8",
    "DGB_RPC_WORKQUEUE": "128",
    "DGB_MAX_CONNECTIONS": "160",
    "DGB_MAX_MEMPOOL_MB": "768",
    "EXPECTED_HASHRATE_TH": "97",
    "START_DIFF": "10000",
    "MIN_DIFF": "1024",
    "POOL_SIGNATURE": "DGB SHA256 SOLO",
    "DASHBOARD_TITLE": "DGB SHA256 Solo Pool",
}

_cache_lock = threading.Lock()
_cache_at = 0.0
_cache_data = None
_price_lock = threading.Lock()
_price_at = 0.0
_price_data = {"usd": None, "source": "unavailable"}


def read_config():
    values = dict(DEFAULTS)
    if os.path.exists(CONFIG_PATH):
        with open(CONFIG_PATH, "r", encoding="utf-8") as fh:
            for line in fh:
                line = line.strip()
                if not line or line.startswith("#") or "=" not in line:
                    continue
                key, value = line.split("=", 1)
                if key in values:
                    values[key] = value
    return values


def write_config(payload):
    values = dict(DEFAULTS)
    for key, value in payload.items():
        if key in values:
            values[key] = str(value).strip()
    with open(CONFIG_PATH, "w", encoding="utf-8") as fh:
        for key in DEFAULTS:
            fh.write(f"{key}={values[key]}\n")
    return values


def rpc(method, params=None):
    payload = json.dumps({
        "jsonrpc": "1.0",
        "id": "dashboard",
        "method": method,
        "params": params or [],
    }).encode()
    auth = base64.b64encode(f"{RPC_USER}:{RPC_PASSWORD}".encode()).decode()
    req = request.Request(
        RPC_URL,
        data=payload,
        headers={"Authorization": f"Basic {auth}", "Content-Type": "application/json"},
    )
    with request.urlopen(req, timeout=6) as res:
        body = json.loads(res.read().decode())
    if body.get("error"):
        raise RuntimeError(body["error"])
    return body.get("result")


def fetch_price():
    global _price_at, _price_data
    now = time.time()
    with _price_lock:
        if now - _price_at < PRICE_CACHE_SECONDS:
            return _price_data
        try:
            url = "https://api.coingecko.com/api/v3/simple/price?ids=digibyte&vs_currencies=usd"
            req = request.Request(url, headers={"User-Agent": "dgb-solo-dashboard/1.0"})
            with request.urlopen(req, timeout=5) as res:
                body = json.loads(res.read().decode())
            usd = body.get("digibyte", {}).get("usd")
            _price_data = {"usd": float(usd), "source": "coingecko"} if usd is not None else {"usd": None, "source": "empty"}
        except Exception as exc:
            _price_data = {"usd": None, "source": f"error: {exc}"}
        _price_at = now
        return _price_data


def expected_seconds(difficulty, hashrate_th):
    if not difficulty or hashrate_th <= 0:
        return None
    return float(difficulty) * 4294967296.0 / (hashrate_th * 1_000_000_000_000.0)


def probability(seconds, window_seconds):
    if not seconds or seconds <= 0:
        return None
    return 1.0 - math.exp(-window_seconds / seconds)


def latest_log_files(limit=8):
    candidates = []
    for root, _, files in os.walk(LOG_DIR):
        for name in files:
            lower = name.lower()
            if lower.endswith(".log") or "log" in lower or "ckpool" in lower:
                path = os.path.join(root, name)
                try:
                    candidates.append((os.path.getmtime(path), path))
                except OSError:
                    pass
    return [path for _, path in sorted(candidates)[-limit:]]


def read_recent_lines(max_lines=1200):
    lines = []
    for path in latest_log_files():
        try:
            with open(path, "rb") as fh:
                fh.seek(0, os.SEEK_END)
                size = fh.tell()
                fh.seek(max(0, size - 512000), os.SEEK_SET)
                chunk = fh.read().decode("utf-8", "replace").splitlines()
                lines.extend(chunk)
        except OSError:
            pass
    return lines[-max_lines:]


def parse_time(line, fallback=None):
    fallback = fallback or int(time.time())
    match = re.search(r"(\d{4}-\d{2}-\d{2})[ T](\d{2}:\d{2}:\d{2})", line)
    if match:
        try:
            return int(time.mktime(time.strptime(" ".join(match.groups()), "%Y-%m-%d %H:%M:%S")))
        except ValueError:
            pass
    match = re.search(r"\[(\d{10})\]", line)
    return int(match.group(1)) if match else fallback


def parse_hashrate(value):
    if value is None:
        return None
    if isinstance(value, (int, float)):
        return float(value) / 1_000_000_000_000.0
    text = str(value).strip()
    match = re.search(r"([0-9.]+)\s*([KMGTPE]?)(?:H|h)?", text)
    if not match:
        return None
    number = float(match.group(1))
    scale = {"": 1, "K": 1e3, "M": 1e6, "G": 1e9, "T": 1e12, "P": 1e15, "E": 1e18}
    return number * scale.get(match.group(2).upper(), 1) / 1e12


def normal_worker(name):
    if not name:
        return "unknown"
    name = str(name).strip().strip('"').strip("'")
    if "." in name:
        return name.split(".", 1)[1] or name
    if len(name) > 18:
        return name[:10] + "..." + name[-6:]
    return name


def worker_from_line(line):
    patterns = [
        r'"workername"\s*:\s*"([^"]+)"',
        r'"worker"\s*:\s*"([^"]+)"',
        r'"username"\s*:\s*"([^"]+)"',
        r"(?:worker|user|username)(?:name)?[=: ]+([A-Za-z0-9:._-]{3,})",
        r"Authori[sz]ed\s+([A-Za-z0-9:._-]{3,})",
    ]
    for pattern in patterns:
        match = re.search(pattern, line, re.I)
        if match:
            return match.group(1)
    return None


def empty_worker(name, now):
    return {
        "name": name,
        "hashrate_1m_th": 0.0,
        "hashrate_5m_th": 0.0,
        "hashrate_1h_th": 0.0,
        "shares": 0,
        "accepted": 0,
        "rejected": 0,
        "last_share": None,
        "authorised": now,
        "runtime_seconds": 0,
        "bestshare": 0.0,
        "source": "logs",
    }


def merge_worker(target, item):
    for key in ("hashrate_1m_th", "hashrate_5m_th", "hashrate_1h_th", "bestshare"):
        value = item.get(key)
        if value is not None:
            target[key] = max(float(target.get(key) or 0), float(value))
    for key in ("shares", "accepted", "rejected"):
        target[key] = max(int(target.get(key) or 0), int(item.get(key) or 0))
    if item.get("last_share"):
        target["last_share"] = max(target.get("last_share") or 0, int(item["last_share"]))
    if item.get("authorised"):
        target["authorised"] = min(target.get("authorised") or item["authorised"], int(item["authorised"]))
    target["runtime_seconds"] = max(0, int(time.time()) - int(target.get("authorised") or time.time()))
    if item.get("source") == "json":
        target["source"] = "json"


def read_json_stats():
    stats = {}
    candidates = []
    for root, _, files in os.walk(LOG_DIR):
        for name in files:
            if name.endswith(".json") or name in ("pool", "users"):
                candidates.append(os.path.join(root, name))
    for path in candidates[-40:]:
        try:
            with open(path, "r", encoding="utf-8", errors="replace") as fh:
                data = json.load(fh)
        except Exception:
            continue
        users = data.get("worker") or data.get("workers") or []
        if isinstance(users, dict):
            users = [{"workername": k, **v} for k, v in users.items() if isinstance(v, dict)]
        if not isinstance(users, list):
            continue
        for row in users:
            if not isinstance(row, dict):
                continue
            raw = row.get("workername") or row.get("worker") or row.get("username") or row.get("name")
            name = normal_worker(raw)
            item = empty_worker(name, int(time.time()))
            item.update({
                "hashrate_1m_th": parse_hashrate(row.get("hashrate1m")) or parse_hashrate(row.get("hashrate_1m")) or 0.0,
                "hashrate_5m_th": parse_hashrate(row.get("hashrate5m")) or parse_hashrate(row.get("hashrate_5m")) or 0.0,
                "hashrate_1h_th": parse_hashrate(row.get("hashrate1hr")) or parse_hashrate(row.get("hashrate_1h")) or 0.0,
                "shares": int(float(row.get("shares") or 0)),
                "accepted": int(float(row.get("accepted") or row.get("shares") or 0)),
                "rejected": int(float(row.get("rejected") or 0)),
                "last_share": int(float(row.get("lastshare") or 0)) or None,
                "authorised": int(float(row.get("authorised") or time.time())),
                "bestshare": float(row.get("bestshare") or row.get("bestever") or 0),
                "source": "json",
            })
            stats.setdefault(name, empty_worker(name, int(time.time())))
            merge_worker(stats[name], item)
    return stats


def parse_logs(lines):
    now = int(time.time())
    workers = read_json_stats()
    blocks = []
    accepted_total = rejected_total = share_total = 0
    for line in lines:
        lower = line.lower()
        raw_worker = worker_from_line(line)
        worker = normal_worker(raw_worker) if raw_worker else "pool"
        if raw_worker and worker not in workers:
            workers[worker] = empty_worker(worker, now)
        target = workers.get(worker)
        line_time = parse_time(line, now)
        if "accepted" in lower or "share accepted" in lower:
            accepted_total += 1
            share_total += 1
            if target:
                target["accepted"] += 1
                target["shares"] += 1
                target["last_share"] = max(target.get("last_share") or 0, line_time)
        if "reject" in lower:
            rejected_total += 1
            if target:
                target["rejected"] += 1
        if "share" in lower:
            share_total += 1
        for key, pattern in (
            ("hashrate_1m_th", r'"hashrate1m"\s*:\s*"([^"]+)"'),
            ("hashrate_5m_th", r'"hashrate5m"\s*:\s*"([^"]+)"'),
            ("hashrate_1h_th", r'"hashrate1hr"\s*:\s*"([^"]+)"'),
        ):
            found = re.search(pattern, line)
            if found and target:
                target[key] = parse_hashrate(found.group(1)) or target[key]
        found = re.search(r'"shares"\s*:\s*([0-9.]+)', line)
        if found and target:
            target["shares"] = max(target["shares"], int(float(found.group(1))))
        found = re.search(r'"lastshare"\s*:\s*([0-9]+)', line)
        if found and target:
            target["last_share"] = max(target.get("last_share") or 0, int(found.group(1)))
        found = re.search(r'"authorised"\s*:\s*([0-9]+)', line)
        if found and target:
            target["authorised"] = min(target.get("authorised") or int(found.group(1)), int(found.group(1)))
        found = re.search(r'"bestshare"\s*:\s*([0-9.]+)', line)
        if found and target:
            target["bestshare"] = max(target["bestshare"], float(found.group(1)))
        if any(token in lower for token in ("block found", "found block", "accepted block", "solved block", "block solve")):
            height = None
            block_hash = None
            h = re.search(r"(?:height|block)[:= ]+([0-9]{4,})", line, re.I)
            if h:
                height = int(h.group(1))
            bh = re.search(r"\b([0-9a-f]{64})\b", line, re.I)
            if bh:
                block_hash = bh.group(1)
            blocks.append({"time": line_time, "height": height, "hash": block_hash, "worker": worker, "raw": line})
    for item in workers.values():
        item["runtime_seconds"] = max(0, now - int(item.get("authorised") or now))
    return {
        "workers": sorted(workers.values(), key=lambda x: x.get("hashrate_5m_th") or x.get("hashrate_1m_th") or 0, reverse=True),
        "blocks": blocks[-20:],
        "accepted_total": accepted_total,
        "rejected_total": rejected_total,
        "share_total": share_total,
        "last_line": lines[-1] if lines else "",
    }


def confirm_blocks(blocks):
    out = []
    for block in blocks:
        enriched = dict(block)
        try:
            if not enriched.get("hash") and enriched.get("height"):
                enriched["hash"] = rpc("getblockhash", [int(enriched["height"])])
            if enriched.get("hash"):
                header = rpc("getblockheader", [enriched["hash"]])
                enriched["height"] = header.get("height", enriched.get("height"))
                enriched["confirmations"] = header.get("confirmations")
                enriched["node_time"] = header.get("time")
        except Exception as exc:
            enriched["confirmations_error"] = str(exc)
        height = enriched.get("height")
        enriched["explorer"] = EXPLORER_BLOCK_URL.format(height=height or "", hash=enriched.get("hash") or "")
        out.append(enriched)
    return out


def rpc_group():
    data = {}
    for key, method in {
        "blockchain": "getblockchaininfo",
        "network": "getnetworkinfo",
        "mining": "getmininginfo",
        "mempool": "getmempoolinfo",
        "uptime": "uptime",
    }.items():
        try:
            data[key] = rpc(method)
        except Exception as exc:
            data[key] = {"error": str(exc)}
    return data


def collect_uncached():
    lines = read_recent_lines()
    pool = parse_logs(lines)
    price = fetch_price()
    data = {
        "title": TITLE,
        "time": int(time.time()),
        "stratum_port": STRATUM_PORT,
        "configured_hashrate_th": EXPECTED_HASHRATE_TH,
        "mining_address": MINING_ADDRESS,
        "price": price,
        "block_reward_dgb": BLOCK_REWARD_DGB,
        "block_reward_usd": BLOCK_REWARD_DGB * price["usd"] if price.get("usd") else None,
        "logs": lines[-120:],
        "pool": pool,
    }
    data.update(rpc_group())
    mining = data.get("mining") if isinstance(data.get("mining"), dict) else {}
    blockchain = data.get("blockchain") if isinstance(data.get("blockchain"), dict) else {}
    difficulty = mining.get("difficulty") or blockchain.get("difficulty")
    observed_th = sum(float(w.get("hashrate_5m_th") or w.get("hashrate_1m_th") or 0) for w in pool["workers"])
    effective_th = observed_th or EXPECTED_HASHRATE_TH
    seconds = expected_seconds(difficulty, effective_th)
    data["solo"] = {
        "effective_hashrate_th": effective_th,
        "observed_hashrate_th": observed_th,
        "expected_seconds": seconds,
        "expected_days": seconds / 86400.0 if seconds else None,
        "probability_1h": probability(seconds, 3600),
        "probability_24h": probability(seconds, 86400),
        "probability_7d": probability(seconds, 604800),
        "probability_30d": probability(seconds, 2592000),
        "luck_percent": (effective_th / EXPECTED_HASHRATE_TH * 100.0) if EXPECTED_HASHRATE_TH else None,
    }
    data["pool"]["blocks"] = confirm_blocks(pool["blocks"])
    return data


def collect():
    global _cache_at, _cache_data
    now = time.time()
    with _cache_lock:
        if _cache_data is not None and now - _cache_at < CACHE_SECONDS:
            return _cache_data
        _cache_data = collect_uncached()
        _cache_at = now
        return _cache_data


HTML = r"""<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>DGB SHA256 Solo Pool</title>
<style>
:root{color-scheme:dark;--bg:#0b0e13;--ink:#f4f8fb;--muted:#8fa3b7;--soft:#c8d7e7;--panel:#151b24;--panel2:#10161d;--line:#2a3848;--green:#43d18c;--amber:#f4c542;--red:#ff6b6b;--blue:#55b9ff;--violet:#a78bfa}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,Segoe UI,Arial,sans-serif}body:before{content:"";position:fixed;inset:0;pointer-events:none;background:linear-gradient(180deg,rgba(85,185,255,.11),transparent 300px)}
header{position:sticky;top:0;z-index:5;backdrop-filter:blur(12px);background:rgba(11,14,19,.84);border-bottom:1px solid var(--line)}.bar{max-width:1500px;margin:auto;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;gap:12px;align-items:center}.mark{width:38px;height:38px;border-radius:8px;background:linear-gradient(135deg,var(--blue),var(--green));box-shadow:0 0 28px rgba(85,185,255,.22)}
h1{font-size:20px;line-height:1.1;margin:0}.sub,.muted{color:var(--muted)}.topstats{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}.pill{border:1px solid var(--line);border-radius:999px;padding:6px 10px;background:rgba(21,27,36,.78);color:var(--soft);white-space:nowrap}
main{max-width:1500px;margin:auto;padding:18px 22px 32px;display:grid;gap:14px}.grid{display:grid;gap:14px}.hero{grid-template-columns:1.25fr 1fr 1fr 1fr}.metrics{grid-template-columns:repeat(6,minmax(0,1fr))}.cols{grid-template-columns:1.18fr .82fr}.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:8px;padding:14px;min-width:0;box-shadow:0 12px 30px rgba(0,0,0,.16)}
.label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.08em}.value{font-size:27px;font-weight:760;margin-top:4px;overflow-wrap:anywhere}.big{font-size:38px}.small{font-size:13px}.ok{color:var(--green)}.warn{color:var(--amber)}.bad{color:var(--red)}.blue{color:var(--blue)}.violet{color:var(--violet)}
.meter{height:8px;background:#080d12;border:1px solid var(--line);border-radius:999px;overflow:hidden;margin-top:12px}.fill{height:100%;width:0;background:linear-gradient(90deg,var(--blue),var(--green))}
table{width:100%;border-collapse:collapse}td,th{border-top:1px solid var(--line);padding:8px 6px;text-align:left;vertical-align:top}th{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.06em}td:first-child{color:var(--soft)}a{color:var(--blue);text-decoration:none}a:hover{text-decoration:underline}
pre{white-space:pre-wrap;word-break:break-word;max-height:360px;overflow:auto;margin:10px 0 0;color:#d8e6eb;font-size:12px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.mono{font-family:ui-monospace,SFMono-Regular,Consolas,monospace}
.settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.settings label{display:grid;gap:6px;color:var(--muted);font-size:12px}.settings input{width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:8px;background:#0a1017;color:var(--ink);font:inherit}.settings button{min-height:42px;border:0;border-radius:8px;background:linear-gradient(90deg,var(--blue),var(--green));color:#041118;font:inherit;font-weight:800;cursor:pointer}.settings-status{margin-top:8px;color:var(--muted)}
.checklist{list-style:none;margin:0;padding:0;display:grid;gap:10px}.checklist li{border:1px solid var(--line);border-radius:8px;padding:10px 12px;background:#10161d}.checklist li span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}.checklist li strong{display:block;font-size:16px;margin-top:4px}.checklist li.ok{border-color:#245b49}.checklist li.bad{border-color:#6d343f}
@media(max-width:1180px){.hero,.metrics,.cols{grid-template-columns:repeat(2,minmax(0,1fr))}.big{font-size:32px}}@media(max-width:720px){.bar{display:block}.topstats{justify-content:flex-start;margin-top:12px}.hero,.metrics,.cols{grid-template-columns:1fr}.value{font-size:24px}.big{font-size:30px}main{padding:14px}td,th{font-size:12px}}
</style>
</head>
<body>
<header><div class="bar"><div class="brand"><div class="mark"></div><div><h1 id="title">DGB SHA256 Solo Pool</h1><div class="sub">Miner hashrate, solo luck, blocks, rewards</div></div></div><div class="topstats"><span class="pill" id="updated">Loading</span><span class="pill" id="port">Stratum -</span><span class="pill" id="price">DGB $-</span></div></div></header>
<main>
<section class="grid hero">
<div class="card"><div class="label">Actual Miner Hashrate</div><div class="value big blue" id="actual">-</div><div class="meter"><div class="fill" id="hashbar"></div></div><div class="muted small" id="actualmeta">from ckpool worker stats/logs</div></div>
<div class="card"><div class="label">Expected Solo Time</div><div class="value" id="expected">-</div><div class="muted small">using actual TH/s when visible</div></div>
<div class="card"><div class="label">Mining Luck</div><div class="value violet" id="luck">-</div><div class="muted small">actual vs configured hash target</div></div>
<div class="card"><div class="label">Reward Value</div><div class="value ok" id="reward">-</div><div class="muted small" id="rewardmeta">block reward estimate</div></div>
</section>
<section class="grid metrics">
<div class="card"><div class="label">Workers</div><div class="value" id="workers">-</div></div>
<div class="card"><div class="label">Shares</div><div class="value" id="shares">-</div></div>
<div class="card"><div class="label">Rejected</div><div class="value" id="rejected">-</div></div>
<div class="card"><div class="label">Best Share</div><div class="value" id="best">-</div></div>
<div class="card"><div class="label">24h Chance</div><div class="value blue" id="p24">-</div></div>
<div class="card"><div class="label">30d Chance</div><div class="value blue" id="p30">-</div></div>
</section>
<section class="card"><div class="section-title"><div class="label">Miner Wise Individual Stats</div><div class="muted small" id="minerstate">waiting</div></div><table><thead><tr><th>Miner</th><th>TH/s 1m</th><th>TH/s 5m</th><th>TH/s 1h</th><th>Shares</th><th>Last Share</th><th>Running</th><th>Best Share Diff</th></tr></thead><tbody id="minertable"></tbody></table></section>
<section class="grid cols">
<div class="card"><div class="section-title"><div class="label">Block Found Instances</div><div class="muted small">confirmed through node when height/hash is visible</div></div><table><thead><tr><th>Time</th><th>Height</th><th>Confirmations</th><th>Worker</th><th>Explorer</th></tr></thead><tbody id="blocks"></tbody></table></div>
<div class="card"><div class="section-title"><div class="label">Node + Market</div><div class="muted small" id="chain">Chain -</div></div><table id="node"></table></div>
</section>
<section class="grid cols">
<div class="card"><div class="section-title"><div class="label">Readiness Checklist</div><div class="muted small">node, pool, wallet, stratum</div></div><ul id="checklist" class="checklist"></ul></div>
<div class="card"><div class="section-title"><div class="label">Settings</div><div class="muted small" id="settings-status">Saved in `.env`</div></div><form id="settings-form" class="settings"><label>DGB payout address<input name="DGB_MINING_ADDRESS" placeholder="DGB..." autocomplete="off"></label><label>Stratum port<input name="STRATUM_PORT" inputmode="numeric"></label><label>Dashboard port<input name="DASHBOARD_PORT" inputmode="numeric"></label><label>DigiByte P2P port<input name="DGB_P2P_PORT" inputmode="numeric"></label><label>Expected hashrate TH/s<input name="EXPECTED_HASHRATE_TH" inputmode="decimal"></label><label>Node cache MB<input name="DGB_DBCACHE_MB" inputmode="numeric"></label><label>Parallel threads<input name="DGB_PAR" inputmode="numeric"></label><label>RPC threads<input name="DGB_RPC_THREADS" inputmode="numeric"></label><label>RPC workqueue<input name="DGB_RPC_WORKQUEUE" inputmode="numeric"></label><label>Max connections<input name="DGB_MAX_CONNECTIONS" inputmode="numeric"></label><label>Max mempool MB<input name="DGB_MAX_MEMPOOL_MB" inputmode="numeric"></label><label>Pool signature<input name="POOL_SIGNATURE" autocomplete="off"></label><label>Dashboard title<input name="DASHBOARD_TITLE" autocomplete="off"></label><label>RPC user<input name="RPC_USER" autocomplete="off"></label><label>RPC password<input name="RPC_PASSWORD" autocomplete="off"></label><button type="submit">Save Settings</button></form><p class="settings-status">Changes take effect after restarting the Umbrel app.</p></div>
</section>
<section class="card"><div class="section-title"><div class="label">Recent ckpool Logs</div><div class="muted small" id="logstate">latest files</div></div><pre id="logs"></pre></section>
</main>
<script>
const $=id=>document.getElementById(id);
function n(v,d=0){return v===null||v===undefined||Number.isNaN(Number(v))?'-':Number(v).toLocaleString(undefined,{maximumFractionDigits:d})}
function pct(v){return v===null||v===undefined?'-':(v*100).toLocaleString(undefined,{maximumFractionDigits:4})+'%'}
function usd(v){return v===null||v===undefined?'$-':'$'+Number(v).toLocaleString(undefined,{maximumFractionDigits:4})}
function dur(sec){if(!sec)return '-'; if(sec>86400)return (sec/86400).toFixed(1)+'d'; if(sec>3600)return (sec/3600).toFixed(1)+'h'; if(sec>60)return Math.round(sec/60)+'m'; return Math.round(sec)+'s'}
function ago(ts){if(!ts)return '-'; return dur(Date.now()/1000-ts)+' ago'}
function fullDur(sec){if(!sec)return '-'; if(sec>31557600)return (sec/31557600).toFixed(2)+' years'; if(sec>86400)return (sec/86400).toFixed(2)+' days'; if(sec>3600)return (sec/3600).toFixed(1)+' hours'; return Math.round(sec)+' sec'}
function rows(items){return items.map(x=>'<tr><td>'+x[0]+'</td><td>'+String(x[1]??'-')+'</td></tr>').join('')}
const settingsForm = document.querySelector("#settings-form");
const settingsStatus = document.querySelector("#settings-status");
const checklistEl = document.querySelector("#checklist");

function readyItem(label, ok, detail) {
  return '<li class="'+(ok?'ok':'bad')+'"><span>'+label+'</span><strong>'+(ok?'Ready':'Not ready')+'</strong><div class="muted small">'+detail+'</div></li>';
}

async function getConfig() {
  const res = await fetch('/api/config', {cache:'no-store'});
  if (!res.ok) throw new Error('config unavailable');
  return res.json();
}

function populateSettings(config) {
  if (!settingsForm) return;
  for (const [key, value] of Object.entries(config)) {
    const input = settingsForm.elements.namedItem(key);
    if (input) input.value = value;
  }
}

function renderChecklist(d, config) {
  if (!checklistEl) return;
  const bc = d.blockchain || {};
  const mining = d.mining || {};
  const pool = d.pool || {};
  const synced = (bc.verificationprogress || 0) > 0.999 || !bc.initialblockdownload;
  const walletReady = !!(config.DGB_MINING_ADDRESS && config.DGB_MINING_ADDRESS !== 'CHANGE_ME_DGB_ADDRESS');
  const stratumReady = !!(d.stratum_port || config.STRATUM_PORT);
  const poolReady = (pool.share_total || 0) >= 0 && (pool.last_line || '').length >= 0;
  checklistEl.innerHTML = [
    readyItem('Node sync', synced, synced ? 'DigiByte Core is ready for templates.' : 'Wait for DigiByte Core to finish syncing.'),
    readyItem('Wallet set', walletReady, walletReady ? 'Mining rewards will go to your payout address.' : 'Set DGB_MINING_ADDRESS before mining.'),
    readyItem('Pool running', !!poolReady, 'ckpool is serving worker shares and logs.'),
    readyItem('Stratum port', stratumReady, 'Configured port: ' + (d.stratum_port || config.STRATUM_PORT || '-')),
  ].join('');
}

async function saveConfig(payload) {
  const res = await fetch('/api/config', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  if (!res.ok) throw new Error(`save failed (${res.status})`);
  return res.json();
}

if (settingsForm) {
  settingsForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const payload = {};
    for (const element of settingsForm.elements) {
      if (element.name) payload[element.name] = element.value.trim();
    }
    try {
      await saveConfig(payload);
      settingsStatus.textContent = "Saved. Restart the Umbrel app to apply node and pool changes.";
    }
    catch (err) {
      settingsStatus.textContent = "Could not save settings.";
    }
  });
}

async function load(){
 try{
  const [statusRes, config] = await Promise.all([fetch('/api/status',{cache:'no-store'}), getConfig()]);
  const d = await statusRes.json();
  const pool=d.pool||{}, solo=d.solo||{}, bc=d.blockchain||{}, net=d.network||{}, mining=d.mining||{}, mem=d.mempool||{}, price=d.price||{};
  const miners=pool.workers||[]; const actual=solo.observed_hashrate_th||0; const effective=solo.effective_hashrate_th||d.configured_hashrate_th||0;
  document.title=d.title; $('title').textContent=d.title; $('updated').textContent='Updated '+new Date(d.time*1000).toLocaleTimeString(); $('port').textContent='Stratum '+d.stratum_port; $('price').textContent='DGB '+usd(price.usd);
  $('actual').textContent=n(actual||effective,3)+' TH/s'; $('actualmeta').textContent=actual?'live from pool worker stats':'fallback to configured '+n(d.configured_hashrate_th,2)+' TH/s'; $('hashbar').style.width=Math.min(100,(actual/(d.configured_hashrate_th||actual||1))*100)+'%';
  $('expected').textContent=fullDur(solo.expected_seconds); $('luck').textContent=solo.luck_percent?n(solo.luck_percent,1)+'%':'-'; $('reward').textContent=d.block_reward_usd?usd(d.block_reward_usd):'-'; $('rewardmeta').textContent=n(d.block_reward_dgb,4)+' DGB estimated';
  $('workers').textContent=n(miners.length); $('shares').textContent=n(miners.reduce((a,x)=>a+(x.shares||0),0)||pool.share_total); $('rejected').textContent=n(miners.reduce((a,x)=>a+(x.rejected||0),0)||pool.rejected_total); $('best').textContent=n(Math.max(0,...miners.map(x=>x.bestshare||0)),2); $('p24').textContent=pct(solo.probability_24h); $('p30').textContent=pct(solo.probability_30d);
  $('minerstate').textContent=miners.length?miners.length+' detected':'waiting for worker stat lines';
  $('minertable').innerHTML=miners.length?miners.map(m=>'<tr><td class="mono">'+m.name+'</td><td>'+n(m.hashrate_1m_th,3)+'</td><td>'+n(m.hashrate_5m_th,3)+'</td><td>'+n(m.hashrate_1h_th,3)+'</td><td>'+n(m.shares)+'</td><td>'+ago(m.last_share)+'</td><td>'+dur(m.runtime_seconds)+'</td><td>'+n(m.bestshare,2)+'</td></tr>').join(''):'<tr><td colspan="8" class="muted">No miner worker stats visible yet. Once miners submit shares, ckpool logs or JSON stats will populate this table.</td></tr>';
  const blocks=pool.blocks||[];
  $('blocks').innerHTML=blocks.length?blocks.slice().reverse().map(b=>'<tr><td>'+new Date((b.node_time||b.time)*1000).toLocaleString()+'</td><td>'+n(b.height)+'</td><td>'+n(b.confirmations)+'</td><td class="mono">'+(b.worker||'-')+'</td><td>'+(b.explorer?'<a target="_blank" rel="noreferrer" href="'+b.explorer+'">details</a>':'-')+'</td></tr>').join(''):'<tr><td colspan="5" class="muted">No found-block events parsed yet.</td></tr>';
  $('chain').textContent='Chain '+(bc.chain||'-');
  $('node').innerHTML=rows([
    ['DigiByte Core', net.subversion||'-'], ['Height', n(bc.blocks)], ['Headers', n(bc.headers)], ['Sync', bc.verificationprogress?n(bc.verificationprogress*100,4)+'%':'-'], ['Peers', n(net.connections)], ['SHA256 difficulty', n(mining.difficulty||bc.difficulty,2)], ['Network hash', n((mining.networkhashps||0)/1e15,3)+' PH/s'], ['Mempool', n(mem.size)+' tx / '+n((mem.usage||0)/1048576,1)+' MB'], ['Price source', price.source||'-'], ['Payout address', '<span class="mono">'+(d.mining_address||'-')+'</span>']
  ]);
  $('logs').textContent=(d.logs||[]).join('\n')||'No ckpool logs visible yet.'; $('logstate').textContent=(d.logs||[]).length+' lines';
  populateSettings(config);
  renderChecklist(d, config);
 }catch(e){$('actual').textContent='Dashboard error'; $('actual').className='value big bad'; $('actualmeta').textContent=e.message}
}
load(); setInterval(load, 10000);
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        if self.path.startswith("/api/config"):
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(json.dumps(read_config()).encode())
            return
        if self.path.startswith("/api/status"):
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(json.dumps(collect()).encode())
            return
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(HTML.encode())

    def do_POST(self):
        if not self.path.startswith("/api/config"):
            self.send_response(404)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "not found"}).encode())
            return
        length = int(self.headers.get("Content-Length", "0"))
        try:
            payload = json.loads(self.rfile.read(length).decode("utf-8"))
        except json.JSONDecodeError:
            self.send_response(400)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": "invalid json"}).encode())
            return
        try:
            config = write_config(payload if isinstance(payload, dict) else {})
        except OSError as exc:
            self.send_response(500)
            self.send_header("Content-Type", "application/json")
            self.end_headers()
            self.wfile.write(json.dumps({"error": str(exc)}).encode())
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.send_header("Cache-Control", "no-store")
        self.end_headers()
        self.wfile.write(json.dumps({"config": config, "restartRequired": True}).encode())

if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
