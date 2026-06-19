from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from urllib import request
import base64
import json
import math
import os
import re
import threading
import time

RPC_URL = os.environ.get("RPC_URL", "http://bsv-node:8332/")
RPC_USER = os.environ.get("RPC_USER", "")
RPC_PASSWORD = os.environ.get("RPC_PASSWORD", "")
TITLE = os.environ.get("DASHBOARD_TITLE", "BSV Solo Node")
STRATUM_PORT = os.environ.get("STRATUM_PORT", "3336")
EXPECTED_HASHRATE_TH = float(os.environ.get("EXPECTED_HASHRATE_TH", "97") or 97)
MINING_ADDRESS = os.environ.get("BSV_MINING_ADDRESS", "")
LOG_DIR = "/logs"
DASHBOARD_USER = os.environ.get("DASHBOARD_USER", "admin")
DASHBOARD_PASSWORD = os.environ.get("DASHBOARD_PASSWORD", "")
CACHE_SECONDS = float(os.environ.get("DASHBOARD_CACHE_SECONDS", "5") or 5)
ROOT_DIR = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
CONFIG_PATH = os.path.join(ROOT_DIR, ".env")

DEFAULTS = {
    "BSV_MINING_ADDRESS": "",
    "STRATUM_PORT": "3336",
    "DASHBOARD_PORT": "8086",
    "BSV_P2P_PORT": "8334",
    "BSVN_IMAGE": "zquestz/bitcoin-sv:latest",
    "CKPOOL_IMAGE": "ghcr.io/willitmod/wim-solo-ckpool:0.8.3-rc1-590fb2a",
    "RPC_USER": "bsvrpc",
    "RPC_PASSWORD": "",
    "DASHBOARD_USER": "admin",
    "DASHBOARD_PASSWORD": "",
    "BSVN_DBCACHE_MB": "6144",
    "BSVN_PAR": "3",
    "BSVN_RPC_THREADS": "8",
    "BSVN_RPC_WORKQUEUE": "128",
    "BSVN_MAX_CONNECTIONS": "96",
    "BSVN_MAX_MEMPOOL_MB": "1024",
    "EXPECTED_HASHRATE_TH": "97",
    "POOL_SIGNATURE": "BSV Solo Q90 Nano",
    "DASHBOARD_TITLE": "BSV Solo Node",
}

_cache_lock = threading.Lock()
_cache_at = 0.0
_cache_data = None


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
        headers={
            "Authorization": f"Basic {auth}",
            "Content-Type": "application/json",
        },
    )
    with request.urlopen(req, timeout=6) as res:
        body = json.loads(res.read().decode())
    if body.get("error"):
        raise RuntimeError(body["error"])
    return body.get("result")


def block_seconds(difficulty, hashrate_th):
    if not difficulty or hashrate_th <= 0:
        return None
    return float(difficulty) * 4294967296.0 / (hashrate_th * 1_000_000_000_000.0)


def probability(seconds, window_seconds):
    if not seconds or seconds <= 0:
        return None
    return 1.0 - math.exp(-window_seconds / seconds)


def latest_log_file():
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
    return sorted(candidates)[-1][1] if candidates else None


def tail_logs(max_lines=100):
    path = latest_log_file()
    if not path:
        return []
    try:
        with open(path, "rb") as fh:
            fh.seek(0, os.SEEK_END)
            size = fh.tell()
            fh.seek(max(0, size - 98304), os.SEEK_SET)
            return fh.read().decode("utf-8", "replace").splitlines()[-max_lines:]
    except OSError:
        return []


def log_stats(lines):
    text = "\n".join(lines)
    lower = text.lower()
    users = set()
    for line in lines:
        for pattern in (r"user(?:name)?[=: ]+([A-Za-z0-9:._-]{8,})", r"worker[=: ]+([A-Za-z0-9:._-]{3,})"):
            found = re.search(pattern, line, re.I)
            if found:
                users.add(found.group(1))
    return {
        "active_workers_hint": len(users) or None,
        "accepted_hint": lower.count("accepted"),
        "rejected_hint": lower.count("reject"),
        "share_hint": lower.count("share"),
        "block_hint": lower.count("block"),
        "last_line": lines[-1] if lines else "",
    }


def rpc_group():
    methods = {
        "blockchain": "getblockchaininfo",
        "network": "getnetworkinfo",
        "mining": "getmininginfo",
        "mempool": "getmempoolinfo",
        "uptime": "uptime",
    }
    data = {}
    for key, method in methods.items():
        try:
            data[key] = rpc(method)
        except Exception as exc:
            data[key] = {"error": str(exc)}
    return data


def collect_uncached():
    now = int(time.time())
    logs = tail_logs()
    data = {
        "title": TITLE,
        "time": now,
        "stratum_port": STRATUM_PORT,
        "expected_hashrate_th": EXPECTED_HASHRATE_TH,
        "mining_address": MINING_ADDRESS,
        "logs": logs,
        "pool": log_stats(logs),
    }
    data.update(rpc_group())
    mining = data.get("mining") if isinstance(data.get("mining"), dict) else {}
    blockchain = data.get("blockchain") if isinstance(data.get("blockchain"), dict) else {}
    difficulty = mining.get("difficulty") or blockchain.get("difficulty")
    seconds = block_seconds(difficulty, EXPECTED_HASHRATE_TH)
    data["solo"] = {
        "expected_seconds": seconds,
        "expected_days": seconds / 86400.0 if seconds else None,
        "probability_24h": probability(seconds, 86400),
        "probability_7d": probability(seconds, 604800),
        "probability_30d": probability(seconds, 2592000),
    }
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
<title>BSV Solo Pool</title>
<style>
:root{color-scheme:dark;--bg:#0e1111;--ink:#f3f7f5;--muted:#91a29a;--soft:#c5d5cf;--panel:#171d1b;--panel2:#111715;--line:#2a3833;--green:#48d597;--amber:#f5c84b;--red:#ff6b6b;--cyan:#72d7ff}
*{box-sizing:border-box}html{scrollbar-color:#35463f #101514}body{margin:0;background:var(--bg);color:var(--ink);font:14px/1.45 Inter,ui-sans-serif,system-ui,Segoe UI,Arial,sans-serif}
body:before{content:"";position:fixed;inset:0;pointer-events:none;background:linear-gradient(180deg,rgba(72,213,151,.08),transparent 260px)}
header{position:sticky;top:0;z-index:5;backdrop-filter:blur(12px);background:rgba(14,17,17,.82);border-bottom:1px solid var(--line)}
.bar{max-width:1400px;margin:auto;padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:18px}.brand{display:flex;align-items:center;gap:12px}
.mark{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,#48d597,#72d7ff);box-shadow:0 0 24px rgba(72,213,151,.22)}
h1{font-size:20px;line-height:1.1;margin:0}.sub,.muted{color:var(--muted)}.topstats{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
.pill{border:1px solid var(--line);border-radius:999px;padding:6px 10px;background:rgba(23,29,27,.78);color:var(--soft);white-space:nowrap}
main{max-width:1400px;margin:auto;padding:18px 22px 30px;display:grid;gap:14px}.grid{display:grid;gap:14px}.hero{grid-template-columns:1.4fr 1fr 1fr 1fr}.cols{grid-template-columns:1.1fr 1fr}.metrics{grid-template-columns:repeat(4,minmax(0,1fr))}
.card{background:linear-gradient(180deg,var(--panel),var(--panel2));border:1px solid var(--line);border-radius:8px;padding:14px;min-width:0;box-shadow:0 12px 30px rgba(0,0,0,.16)}
.label{color:var(--muted);font-size:11px;text-transform:uppercase;letter-spacing:.08em}.value{font-size:28px;font-weight:750;margin-top:4px;overflow-wrap:anywhere}.big{font-size:38px}.small{font-size:13px}.ok{color:var(--green)}.warn{color:var(--amber)}.bad{color:var(--red)}.cyan{color:var(--cyan)}
.meter{height:8px;background:#0b0f0e;border:1px solid var(--line);border-radius:999px;overflow:hidden;margin-top:12px}.fill{height:100%;width:0;background:linear-gradient(90deg,var(--green),var(--cyan))}
table{width:100%;border-collapse:collapse}td{border-top:1px solid var(--line);padding:8px 4px;vertical-align:top}td:first-child{color:var(--muted);width:45%}
pre{white-space:pre-wrap;word-break:break-word;max-height:420px;overflow:auto;margin:10px 0 0;color:#d8e6eb;font-size:12px}.section-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.settings{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.settings label{display:grid;gap:6px;color:var(--muted);font-size:12px}.settings input{width:100%;padding:10px 11px;border:1px solid var(--line);border-radius:8px;background:#0a1017;color:var(--ink);font:inherit}.settings button{min-height:42px;border:0;border-radius:8px;background:linear-gradient(90deg,var(--blue),var(--green));color:#041118;font:inherit;font-weight:800;cursor:pointer}.settings-status{margin-top:8px;color:var(--muted)}.checklist{list-style:none;margin:0;padding:0;display:grid;gap:10px}.checklist li{border:1px solid var(--line);border-radius:8px;padding:10px 12px;background:#10161d}.checklist li span{display:block;color:var(--muted);font-size:12px;text-transform:uppercase;letter-spacing:.06em}.checklist li strong{display:block;font-size:16px;margin-top:4px}.checklist li.ok{border-color:#245b49}.checklist li.bad{border-color:#6d343f}
@media(max-width:1050px){.hero,.metrics,.cols{grid-template-columns:repeat(2,minmax(0,1fr))}.big{font-size:32px}}@media(max-width:650px){.bar{display:block}.topstats{justify-content:flex-start;margin-top:12px}.hero,.metrics,.cols{grid-template-columns:1fr}.value{font-size:24px}.big{font-size:30px}main{padding:14px}}
</style>
</head>
<body>
<header><div class="bar"><div class="brand"><div class="mark"></div><div><h1 id="title">BSV Solo Pool</h1><div class="sub">Full node + solo stratum monitor</div></div></div><div class="topstats"><span class="pill" id="updated">Loading</span><span class="pill" id="port">Stratum -</span><span class="pill" id="hashrate">- TH/s</span></div></div></header>
<main>
<section class="grid hero">
<div class="card"><div class="label">Node Sync</div><div class="value big" id="sync">-</div><div class="meter"><div class="fill" id="syncbar"></div></div><div class="muted small" id="syncmeta">Waiting for BSVN RPC</div></div>
<div class="card"><div class="label">Best Height</div><div class="value" id="height">-</div><div class="muted small" id="headers">Headers -</div></div>
<div class="card"><div class="label">Network Difficulty</div><div class="value" id="difficulty">-</div><div class="muted small" id="networkhash">Network hash -</div></div>
<div class="card"><div class="label">Expected Solo Time</div><div class="value" id="expected">-</div><div class="muted small">Probability based, not a guarantee</div></div>
</section>
<section class="grid metrics">
<div class="card"><div class="label">24h Chance</div><div class="value cyan" id="p24">-</div></div>
<div class="card"><div class="label">7d Chance</div><div class="value cyan" id="p7">-</div></div>
<div class="card"><div class="label">30d Chance</div><div class="value cyan" id="p30">-</div></div>
<div class="card"><div class="label">Peers</div><div class="value" id="peers">-</div><div class="muted small" id="peermeta">Network connections</div></div>
</section>
<section class="grid cols">
<div class="card"><div class="section-title"><div class="label">Readiness Checklist</div><div class="muted small">node, pool, wallet, stratum</div></div><ul id="checklist" class="checklist"></ul></div>
<div class="card"><div class="section-title"><div class="label">Settings</div><div class="muted small" id="settings-status">Saved in `.env`</div></div><form id="settings-form" class="settings"><label>BSV payout address<input name="BSV_MINING_ADDRESS" placeholder="bitcoin:..." autocomplete="off"></label><label>Stratum port<input name="STRATUM_PORT" inputmode="numeric"></label><label>Dashboard port<input name="DASHBOARD_PORT" inputmode="numeric"></label><label>BSV P2P port<input name="BSV_P2P_PORT" inputmode="numeric"></label><label>Expected hashrate TH/s<input name="EXPECTED_HASHRATE_TH" inputmode="decimal"></label><label>Node cache MB<input name="BSVN_DBCACHE_MB" inputmode="numeric"></label><label>Parallel threads<input name="BSVN_PAR" inputmode="numeric"></label><label>RPC threads<input name="BSVN_RPC_THREADS" inputmode="numeric"></label><label>RPC workqueue<input name="BSVN_RPC_WORKQUEUE" inputmode="numeric"></label><label>Max connections<input name="BSVN_MAX_CONNECTIONS" inputmode="numeric"></label><label>Max mempool MB<input name="BSVN_MAX_MEMPOOL_MB" inputmode="numeric"></label><label>Pool signature<input name="POOL_SIGNATURE" autocomplete="off"></label><label>Dashboard title<input name="DASHBOARD_TITLE" autocomplete="off"></label><label>RPC user<input name="RPC_USER" autocomplete="off"></label><label>RPC password<input name="RPC_PASSWORD" autocomplete="off"></label><label>Dashboard user<input name="DASHBOARD_USER" autocomplete="off"></label><label>Dashboard password<input name="DASHBOARD_PASSWORD" autocomplete="off"></label><button type="submit">Save Settings</button></form><p class="settings-status">Changes take effect after restarting the Umbrel app.</p></div>
</section>
<section class="grid cols">
<div class="card"><div class="section-title"><div class="label">Pool Signals</div><div class="muted small">from ckpool logs</div></div><table id="pool"></table></div>
<div class="card"><div class="section-title"><div class="label">Node Health</div><div class="muted small" id="chain">Chain -</div></div><table id="node"></table></div>
</section>
<section class="grid cols">
<div class="card"><div class="section-title"><div class="label">Mempool</div><div class="muted small">BSVN</div></div><table id="mempool"></table></div>
<div class="card"><div class="section-title"><div class="label">Recent ckpool Logs</div><div class="muted small" id="logstate">latest file</div></div><pre id="logs"></pre></div>
</section>
</main>
<script>
const $=id=>document.getElementById(id);
function n(v,d=0){return v===null||v===undefined||Number.isNaN(Number(v))?'-':Number(v).toLocaleString(undefined,{maximumFractionDigits:d})}
function pct(v){return v===null||v===undefined?'-':(v*100).toLocaleString(undefined,{maximumFractionDigits:4})+'%'}
function bytes(v){if(!v)return '-'; const u=['B','KB','MB','GB']; let i=0; while(v>=1024&&i<u.length-1){v/=1024;i++} return v.toFixed(i?1:0)+' '+u[i]}
function dur(sec){if(!sec)return '-'; if(sec>31557600)return (sec/31557600).toFixed(1)+' years'; if(sec>86400)return (sec/86400).toFixed(1)+' days'; if(sec>3600)return (sec/3600).toFixed(1)+' hours'; return Math.round(sec)+' sec'}
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
  const pool = d.pool || {};
  const synced = (bc.verificationprogress || 0) > 0.999 || !bc.initialblockdownload;
  const walletReady = !!(config.BSV_MINING_ADDRESS && config.BSV_MINING_ADDRESS.length > 6);
  const stratumReady = !!(d.stratum_port || config.STRATUM_PORT);
  checklistEl.innerHTML = [
    readyItem('Node sync', synced, synced ? 'BSVN is ready for templates.' : 'Wait for BSVN to finish syncing.'),
    readyItem('Wallet set', walletReady, walletReady ? 'Use the payout address as your miner username.' : 'Set BSV_MINING_ADDRESS before mining.'),
    readyItem('Pool running', true, pool.last_line ? 'ckpool is logging live shares.' : 'ckpool is running and waiting for miners.'),
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
    const d=await statusRes.json();
    const bc=d.blockchain||{}, net=d.network||{}, mining=d.mining||{}, mem=d.mempool||{}, solo=d.solo||{}, pool=d.pool||{};
    document.title=d.title; $('title').textContent=d.title; $('updated').textContent='Updated '+new Date(d.time*1000).toLocaleTimeString(); $('port').textContent='Stratum '+d.stratum_port; $('hashrate').textContent=n(d.expected_hashrate_th,2)+' TH/s';
    const progress=(bc.verificationprogress||0)*100, synced=progress>99.9&&!bc.initialblockdownload;
    $('sync').textContent=bc.error?'RPC error':progress.toFixed(4)+'%'; $('sync').className='value big '+(synced?'ok':'warn'); $('syncbar').style.width=Math.min(100,progress)+'%';
    $('syncmeta').textContent=bc.error||((bc.initialblockdownload?'Initial block download':'Ready for templates')+' | pruned '+(bc.pruned?'yes':'no'));
    $('height').textContent=n(bc.blocks); $('headers').textContent='Headers '+n(bc.headers)+' | confirmations lag '+n(Math.max(0,(bc.headers||0)-(bc.blocks||0)));
    $('difficulty').textContent=n(mining.difficulty||bc.difficulty,2); $('networkhash').textContent='Network hash '+n((mining.networkhashps||0)/1e18,3)+' EH/s';
    $('expected').textContent=dur(solo.expected_seconds); $('p24').textContent=pct(solo.probability_24h); $('p7').textContent=pct(solo.probability_7d); $('p30').textContent=pct(solo.probability_30d);
    $('peers').textContent=n(net.connections); $('peermeta').textContent='In '+n(net.connections_in)+' | out '+n(net.connections_out);
    $('chain').textContent='Chain '+(bc.chain||'-');
    $('pool').innerHTML=rows([
      ['Configured stratum', '0.0.0.0:'+d.stratum_port],
      ['Expected hashrate', n(d.expected_hashrate_th,2)+' TH/s'],
      ['Active workers hint', pool.active_workers_hint ?? 'watch miner UI'],
      ['Accepted log hits', n(pool.accepted_hint)],
      ['Rejected log hits', n(pool.rejected_hint)],
      ['Share log hits', n(pool.share_hint)],
      ['Block log hits', n(pool.block_hint)],
      ['Last pool line', pool.last_line || 'No ckpool log line visible yet']
    ]);
    $('node').innerHTML=rows([
      ['BSVN version', net.subversion||'-'],
      ['Protocol', net.protocolversion||'-'],
      ['Uptime', dur(d.uptime)],
      ['Warnings', (bc.warnings||net.warnings||'none')],
      ['Chain work', bc.chainwork||'-'],
      ['Best block hash', bc.bestblockhash||'-'],
      ['Relay fee', net.relayfee?net.relayfee+' BSV/kB':'-'],
      ['Services', net.localservicesnames?net.localservicesnames.join(', '):'-'],
      ['Payout address', '<span class="mono">'+(d.mining_address||config.BSV_MINING_ADDRESS||'-')+'</span>']
    ]);
    $('mempool').innerHTML=rows([
      ['Transactions', n(mem.size)],
      ['Bytes', bytes(mem.bytes)],
      ['Memory usage', bytes(mem.usage)],
      ['Max mempool', bytes(mem.maxmempool)],
      ['Min relay tx fee', mem.mempoolminfee?mem.mempoolminfee+' BSV/kB':'-'],
      ['Unbroadcast', n(mem.unbroadcastcount)]
    ]);
    $('logs').textContent=(d.logs||[]).join('\n') || 'No ckpool log file visible yet.'; $('logstate').textContent=(d.logs||[]).length+' lines';
    populateSettings(config);
    renderChecklist(d, config);
  }catch(e){$('sync').textContent='Dashboard error'; $('sync').className='value big bad'; $('syncmeta').textContent=e.message}
}
load(); setInterval(load, 10000);
</script>
</body>
</html>"""


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        return

    def do_GET(self):
        if not self.authorized():
            self.send_response(401)
            self.send_header("WWW-Authenticate", 'Basic realm="BSV Solo Dashboard"')
            self.end_headers()
            self.wfile.write(b"Authentication required")
            return
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
        if not self.authorized():
            self.send_response(401)
            self.send_header("WWW-Authenticate", 'Basic realm="BSV Solo Dashboard"')
            self.end_headers()
            self.wfile.write(b"Authentication required")
            return
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

    def authorized(self):
        if not DASHBOARD_PASSWORD:
            return False
        header = self.headers.get("Authorization", "")
        if not header.startswith("Basic "):
            return False
        try:
            raw = base64.b64decode(header.split(" ", 1)[1]).decode()
        except Exception:
            return False
        return raw == f"{DASHBOARD_USER}:{DASHBOARD_PASSWORD}"


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", 8080), Handler).serve_forever()
