from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib import request
import base64
import hmac
import json
import os
import re
import threading
import time

RPC_URL = os.environ.get("RPC_URL", "http://bchn:8332/")
RPC_USER = os.environ.get("RPC_USER", "")
RPC_PASSWORD = os.environ.get("RPC_PASSWORD", "")
DASHBOARD_USER = os.environ.get("DASHBOARD_USER", "admin")
DASHBOARD_PASSWORD = os.environ.get("DASHBOARD_PASSWORD", "")
BCH_ADDRESS = os.environ.get("BCH_MINING_ADDRESS", "")
TITLE = os.environ.get("DASHBOARD_TITLE", "BCH Solo Pool")
STRATUM_PORT = os.environ.get("STRATUM_PORT", "3333")
EXPECTED_HASHRATE_TH = float(os.environ.get("EXPECTED_HASHRATE_TH", "97") or 97)
CACHE_SECONDS = max(30, int(os.environ.get("DASHBOARD_CACHE_SECONDS", "30") or 30))
SCAN_DEPTH = max(0, int(os.environ.get("BLOCK_SCAN_DEPTH", "10000") or 10000))
SCAN_BATCH = max(1, int(os.environ.get("BLOCK_SCAN_BATCH", "100") or 100))
CACHE_FILE = "/cache/dashboard.json"
LOG_DIR = "/logs"
PRICE_SECONDS = 3600

STATE_LOCK = threading.Lock()
STATE = {
    "time": int(time.time()),
    "title": TITLE,
    "status": "starting",
    "detail": "Waiting for Bitcoin Cash Node RPC",
    "stratum_port": STRATUM_PORT,
    "workers": [],
    "pool": {},
    "blocks": [],
}


def rpc(method, params=None, timeout=8):
    payload = json.dumps({
        "jsonrpc": "1.0",
        "id": "dashboard",
        "method": method,
        "params": params or [],
    }).encode()
    token = base64.b64encode(f"{RPC_USER}:{RPC_PASSWORD}".encode()).decode()
    req = request.Request(
        RPC_URL,
        data=payload,
        headers={"Authorization": f"Basic {token}", "Content-Type": "application/json"},
    )
    with request.urlopen(req, timeout=timeout) as res:
        body = json.loads(res.read().decode())
    if body.get("error"):
        raise RuntimeError(body["error"])
    return body.get("result")


def load_cache():
    try:
        with open(CACHE_FILE, "r", encoding="utf-8") as fh:
            value = json.load(fh)
            if isinstance(value, dict):
                return value
    except Exception:
        pass
    return {"hits": [], "price": {"usd": None, "at": 0}}


def save_cache(cache):
    os.makedirs(os.path.dirname(CACHE_FILE), exist_ok=True)
    temp = CACHE_FILE + ".tmp"
    with open(temp, "w", encoding="utf-8") as fh:
        json.dump(cache, fh, separators=(",", ":"))
    os.replace(temp, CACHE_FILE)


def price_usd(cache):
    now = int(time.time())
    price = cache.get("price", {})
    if price.get("usd") and now - int(price.get("at", 0)) < PRICE_SECONDS:
        return float(price["usd"])
    try:
        req = request.Request(
            "https://api.coingecko.com/api/v3/simple/price?ids=bitcoin-cash&vs_currencies=usd",
            headers={"User-Agent": "bch-solo-dashboard"},
        )
        body = json.loads(request.urlopen(req, timeout=5).read().decode())
        usd = float(body["bitcoin-cash"]["usd"])
        cache["price"] = {"usd": usd, "at": now}
        return usd
    except Exception:
        return float(price.get("usd") or 0)


def payout_script():
    try:
        result = rpc("validateaddress", [BCH_ADDRESS])
        return str(result.get("scriptPubKey") or "").lower()
    except Exception:
        return ""


def scan_block(height, script_hex):
    block_hash = rpc("getblockhash", [height])
    block = rpc("getblock", [block_hash, 1])
    txids = block.get("tx", [])
    if not txids:
        return None
    coinbase = rpc("getrawtransaction", [txids[0], True, block_hash])
    reward = 0.0
    for output in coinbase.get("vout", []):
        output_script = str(output.get("scriptPubKey", {}).get("hex") or "").lower()
        if script_hex and output_script == script_hex:
            reward += float(output.get("value") or 0)
    if reward <= 0:
        return None
    return {
        "height": height,
        "hash": block_hash,
        "time": int(block.get("time") or 0),
        "confirmations": int(block.get("confirmations") or 0),
        "reward": reward,
        "txid": coinbase.get("txid"),
    }


def scan_hits(cache, best):
    script_hex = payout_script()
    if not script_hex or best <= 0:
        return cache.get("hits", [])

    if cache.get("address") != BCH_ADDRESS:
        cache.clear()
        cache.update({"address": BCH_ADDRESS, "hits": [], "price": {"usd": None, "at": 0}})

    hits = cache.get("hits", [])
    known = {str(item.get("hash")) for item in hits}
    tip_scanned = int(cache.get("tip_scanned", 0) or 0)
    if not tip_scanned:
        tip_scanned = max(0, best - 1)

    last_tip = tip_scanned
    for height in range(tip_scanned + 1, best + 1):
        try:
            hit = scan_block(height, script_hex)
            if hit and hit["hash"] not in known:
                hits.append(hit)
                known.add(hit["hash"])
            last_tip = height
        except Exception:
            break
    cache["tip_scanned"] = last_tip

    if SCAN_DEPTH and not cache.get("history_complete"):
        history_start = max(0, best - SCAN_DEPTH)
        cursor = int(cache.get("history_cursor", history_start) or history_start)
        if cursor < history_start or cursor > best:
            cursor = history_start
        end = min(best, cursor + SCAN_BATCH - 1)
        for height in range(cursor, end + 1):
            try:
                block_hash = rpc("getblockhash", [height])
                if block_hash in known:
                    continue
                hit = scan_block(height, script_hex)
                if hit:
                    hits.append(hit)
                    known.add(hit["hash"])
            except Exception:
                continue
        cache["history_cursor"] = end + 1
        cache["history_complete"] = end >= best

    for hit in hits:
        hit["confirmations"] = max(0, best - int(hit.get("height") or best) + 1)
    cache["hits"] = sorted(hits, key=lambda item: int(item.get("height", 0)))
    return cache["hits"]


def parse_hashrate(value):
    if isinstance(value, (int, float)):
        return float(value) / 1e12
    match = re.fullmatch(r"\s*([0-9.]+)\s*([kKmMgGtTpPeE]?)\s*", str(value or ""))
    if not match:
        return 0.0
    scale = {"": 1, "k": 1e3, "m": 1e6, "g": 1e9, "t": 1e12, "p": 1e15, "e": 1e18}
    return float(match.group(1)) * scale[match.group(2).lower()] / 1e12


def worker_display_name(user_name, worker_name):
    worker_name = str(worker_name or "").strip()
    user_name = str(user_name or "").strip()
    if worker_name and user_name and worker_name.startswith(user_name + "."):
        return worker_name[len(user_name) + 1:] or worker_name
    return worker_name or user_name


def worker_stats():
    workers = []
    directory = os.path.join(LOG_DIR, "users")
    try:
        names = os.listdir(directory)
    except OSError:
        return workers
    for name in names:
        path = os.path.join(directory, name)
        try:
            with open(path, "r", encoding="utf-8") as fh:
                item = json.load(fh)
        except Exception:
            continue
        entries = item.get("worker") or []
        if not entries:
            entries = [{"workername": name, **item}]
        for worker in entries:
            raw_name = worker.get("workername") or name
            workers.append({
                "name": worker_display_name(name, raw_name),
                "full_name": raw_name,
                "user": name,
                "hashrate_1m_th": parse_hashrate(worker.get("hashrate1m")),
                "hashrate_5m_th": parse_hashrate(worker.get("hashrate5m")),
                "hashrate_1h_th": parse_hashrate(worker.get("hashrate1hr")),
                "shares": float(worker.get("shares") or 0),
                "bestshare": float(worker.get("bestshare") or 0),
                "last_share": int(worker.get("lastshare") or 0),
            })
    return sorted(workers, key=lambda item: item["name"].lower())


def tail_text(path, limit=65536):
    try:
        with open(path, "rb") as fh:
            fh.seek(0, os.SEEK_END)
            size = fh.tell()
            fh.seek(max(0, size - limit), os.SEEK_SET)
            return fh.read().decode("utf-8", "replace")
    except OSError:
        return ""


def pool_stats():
    text = tail_text(os.path.join(LOG_DIR, "ckpool.log"))
    result = {
        "accepted": 0,
        "rejected": 0,
        "sps": 0,
        "bestshare": 0,
        "zmq_connected": any(marker in text for marker in (
            "ZMQ connected to",
            "ZMQ block hash",
            "Block hash changed",
        )),
    }
    matches = re.findall(r"Pool:(\{[^\r\n]+\})", text)
    for raw in reversed(matches):
        try:
            value = json.loads(raw)
        except Exception:
            continue
        if "accepted" in value:
            result.update({
                "accepted": float(value.get("accepted") or 0),
                "rejected": float(value.get("rejected") or 0),
                "sps": float(value.get("SPS1m") or 0),
                "bestshare": float(value.get("bestshare") or 0),
            })
            break
    result["logs"] = [line for line in text.splitlines()[-8:] if line.strip()]
    return result


def collect():
    now = int(time.time())
    cache = load_cache()
    workers = worker_stats()
    pool = pool_stats()
    data = {
        "time": now,
        "title": TITLE,
        "status": "starting",
        "detail": "Waiting for Bitcoin Cash Node RPC",
        "stratum_port": STRATUM_PORT,
        "address": BCH_ADDRESS,
        "workers": workers,
        "pool": pool,
        "blocks": cache.get("hits", []),
    }
    try:
        chain = rpc("getblockchaininfo")
        network = rpc("getnetworkinfo")
        mining = rpc("getmininginfo")
        zmq = rpc("getzmqnotifications")
        best = int(chain.get("blocks") or 0)
        ready = not chain.get("initialblockdownload") and int(chain.get("headers") or 0) <= best + 1
        if ready:
            hits = scan_hits(cache, best)
        else:
            if cache.get("address") != BCH_ADDRESS:
                cache.clear()
                cache.update({"address": BCH_ADDRESS, "hits": [], "price": {"usd": None, "at": 0}})
            cache["tip_scanned"] = best
            cache["history_cursor"] = max(0, best - SCAN_DEPTH)
            cache["history_complete"] = False
            hits = cache.get("hits", [])
        usd = price_usd(cache)
        total_bch = sum(float(item.get("reward") or 0) for item in hits)
        observed = sum(item["hashrate_5m_th"] for item in workers)
        effective_hashrate = observed or EXPECTED_HASHRATE_TH
        difficulty = float(mining.get("difficulty") or chain.get("difficulty") or 0)
        expected_seconds = difficulty * 4294967296 / (effective_hashrate * 1e12) if effective_hashrate else None
        data.update({
            "status": "ready" if ready else "syncing",
            "detail": f"Height {best:,} | {int(network.get('connections') or 0)} peers",
            "chain": chain,
            "network": network,
            "mining": mining,
            "zmq": zmq,
            "zmq_ready": (
                any(item.get("type") == "pubhashblock" for item in zmq)
                and pool.get("zmq_connected", False)
            ),
            "workers": workers,
            "pool": pool,
            "observed_hashrate_th": observed,
            "expected_seconds": expected_seconds,
            "blocks": hits[-20:],
            "blocks_total": len(hits),
            "total_bch": total_bch,
            "bch_usd": usd or None,
            "total_usd": total_bch * usd if usd else None,
            "history_cursor": cache.get("history_cursor"),
        })
        save_cache(cache)
    except Exception as error:
        data["detail"] = str(error)
    return data


def collector():
    while True:
        started = time.monotonic()
        try:
            value = collect()
        except Exception as error:
            value = {
                "time": int(time.time()),
                "title": TITLE,
                "status": "error",
                "detail": str(error),
                "stratum_port": STRATUM_PORT,
                "workers": [],
                "pool": {},
                "blocks": [],
            }
        with STATE_LOCK:
            STATE.clear()
            STATE.update(value)
        time.sleep(max(1, CACHE_SECONDS - (time.monotonic() - started)))


def authorized(headers):
    if not DASHBOARD_PASSWORD:
        return False
    header = headers.get("Authorization", "")
    if not header.startswith("Basic "):
        return False
    try:
        raw = base64.b64decode(header.split(" ", 1)[1]).decode()
    except Exception:
        return False
    return hmac.compare_digest(raw, f"{DASHBOARD_USER}:{DASHBOARD_PASSWORD}")


HTML = r"""<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BCH Solo Pool</title>
<style>
:root{color-scheme:dark;--bg:#0c1014;--panel:#151b21;--line:#29343e;--text:#edf4f7;--muted:#91a3ad;--green:#45d483;--blue:#55b9ff;--amber:#f0c54d;--red:#ff7272}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.4 system-ui,Segoe UI,Arial,sans-serif}header{border-bottom:1px solid var(--line);background:#10151a}.bar,main{max-width:1280px;margin:auto}.bar{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;gap:14px}h1{font-size:20px;margin:0}.status{color:var(--muted)}main{padding:16px 18px 28px;display:grid;gap:12px}.grid{display:grid;gap:12px;grid-template-columns:repeat(4,minmax(0,1fr))}.card{background:var(--panel);border:1px solid var(--line);border-radius:7px;padding:13px;min-width:0}.label{color:var(--muted);font-size:11px;text-transform:uppercase}.value{font-size:25px;font-weight:750;margin-top:3px}.green{color:var(--green)}.blue{color:var(--blue)}.amber{color:var(--amber)}.red{color:var(--red)}table{width:100%;border-collapse:collapse}th,td{padding:8px 6px;border-top:1px solid var(--line);text-align:left}th{color:var(--muted);font-size:11px;text-transform:uppercase}.mono{font-family:ui-monospace,Consolas,monospace}pre{margin:0;white-space:pre-wrap;max-height:180px;overflow:auto;color:#cbd8de;font-size:11px}.small{font-size:12px;color:var(--muted)}@media(max-width:850px){.grid{grid-template-columns:repeat(2,1fr)}}@media(max-width:540px){.grid{grid-template-columns:1fr}.bar{display:block}.status{margin-top:5px}}
</style></head><body>
<header><div class="bar"><h1 id="title">BCH Solo Pool</h1><div class="status" id="status">Starting</div></div></header>
<main>
<section class="grid">
<div class="card"><div class="label">Hashrate</div><div class="value blue" id="hashrate">-</div></div>
<div class="card"><div class="label">Workers</div><div class="value" id="workers">-</div></div>
<div class="card"><div class="label">Best Share</div><div class="value" id="best">-</div></div>
<div class="card"><div class="label">Total USD Earned</div><div class="value green" id="earned">-</div></div>
</section>
<section class="grid">
<div class="card"><div class="label">Node Height</div><div class="value" id="height">-</div></div>
<div class="card"><div class="label">Peers</div><div class="value" id="peers">-</div></div>
<div class="card"><div class="label">ZMQ</div><div class="value" id="zmq">-</div></div>
<div class="card"><div class="label">Expected Solo Time</div><div class="value amber" id="expected">-</div></div>
</section>
<section class="card"><div class="label">Miner Username Stats</div><table><thead><tr><th>User</th><th>1m</th><th>5m</th><th>1h</th><th>Shares</th><th>Last Share</th><th>Best</th></tr></thead><tbody id="minerrows"></tbody></table></section>
<section class="card"><div class="label">Confirmed Block Hits</div><table><thead><tr><th>Time</th><th>Height</th><th>Reward</th><th>Confirmations</th></tr></thead><tbody id="blockrows"></tbody></table></section>
<section class="card"><div class="label">Pool Activity</div><div class="small" id="poolmeta"></div><pre id="logs"></pre></section>
</main>
<script>
const $=id=>document.getElementById(id);
function fmt(v,d=2){return v===null||v===undefined?'-':Number(v).toLocaleString(undefined,{maximumFractionDigits:d})}
function compact(v){if(!v)return '0';const u=['','K','M','G','T','P'];let x=Number(v),i=0;while(Math.abs(x)>=1000&&i<u.length-1){x/=1000;i++}return fmt(x,x>=100?0:x>=10?1:2)+u[i]}
function age(ts){if(!ts)return '-';let s=Math.max(0,Date.now()/1000-ts);if(s<60)return Math.round(s)+'s';if(s<3600)return Math.round(s/60)+'m';return (s/3600).toFixed(1)+'h'}
function duration(s){if(!s)return '-';if(s>31557600)return (s/31557600).toFixed(2)+'y';if(s>86400)return (s/86400).toFixed(1)+'d';return (s/3600).toFixed(1)+'h'}
async function load(){try{const d=await(await fetch('/api/status',{cache:'no-store'})).json();document.title=d.title;$('title').textContent=d.title;$('status').textContent=d.status.toUpperCase()+' | '+d.detail+' | updated '+new Date(d.time*1000).toLocaleTimeString();$('hashrate').textContent=fmt(d.observed_hashrate_th,2)+' TH/s';$('workers').textContent=fmt((d.workers||[]).length,0);$('best').textContent=compact(Math.max(d.pool?.bestshare||0,...(d.workers||[]).map(x=>x.bestshare||0)));$('earned').textContent=d.total_usd==null?'$-':'$'+fmt(d.total_usd,2);$('height').textContent=fmt(d.chain?.blocks,0);$('peers').textContent=fmt(d.network?.connections,0);$('zmq').textContent=d.zmq_ready?'ACTIVE':'WAITING';$('zmq').className='value '+(d.zmq_ready?'green':'amber');$('expected').textContent=duration(d.expected_seconds);$('minerrows').innerHTML=(d.workers||[]).map(x=>'<tr><td class="mono">'+x.name+'</td><td>'+fmt(x.hashrate_1m_th,2)+'T</td><td>'+fmt(x.hashrate_5m_th,2)+'T</td><td>'+fmt(x.hashrate_1h_th,2)+'T</td><td>'+compact(x.shares)+'</td><td>'+age(x.last_share)+'</td><td>'+compact(x.bestshare)+'</td></tr>').join('')||'<tr><td colspan="7" class="small">Waiting for miner authorization and first shares.</td></tr>';$('blockrows').innerHTML=(d.blocks||[]).slice().reverse().map(x=>'<tr><td>'+new Date(x.time*1000).toLocaleString()+'</td><td>'+fmt(x.height,0)+'</td><td>'+fmt(x.reward,8)+' BCH</td><td>'+fmt(x.confirmations,0)+'</td></tr>').join('')||'<tr><td colspan="4" class="small">No payout blocks detected yet.</td></tr>';$('poolmeta').textContent='Accepted '+compact(d.pool?.accepted)+' | Rejected '+compact(d.pool?.rejected)+' | '+fmt(d.pool?.sps,2)+' shares/s | Stratum '+d.stratum_port;$('logs').textContent=(d.pool?.logs||[]).join('\n')}catch(e){$('status').textContent='Dashboard error: '+e.message}finally{setTimeout(load,30000)}}
load();
</script></body></html>"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        if not authorized(self.headers):
            self.send_response(401)
            self.send_header("WWW-Authenticate", 'Basic realm="BCH Dashboard"')
            self.end_headers()
            return
        if self.path.startswith("/api/status"):
            with STATE_LOCK:
                body = json.dumps(STATE, separators=(",", ":")).encode()
            self.send_response(200)
            self.send_header("Content-Type", "application/json")
            self.send_header("Cache-Control", "no-store")
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)
            return
        body = HTML.encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    threading.Thread(target=collector, daemon=True).start()
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
