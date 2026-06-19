const statusEl = document.querySelector("#status");
const hashrateEl = document.querySelector("#hashrate");
const workersEl = document.querySelector("#workers");
const nodeSyncEl = document.querySelector("#node-sync");
const nodeBlockEl = document.querySelector("#node-block");
const highestBlockEl = document.querySelector("#highest-block");
const peersEl = document.querySelector("#peers");
const nodeStatusEl = document.querySelector("#node-status");
const syncBarEl = document.querySelector("#sync-bar");
const blocksEl = document.querySelector("#blocks");
const luckEl = document.querySelector("#luck");
const blockListEl = document.querySelector("#block-list");
const settingsForm = document.querySelector("#settings-form");
const settingsStatusEl = document.querySelector("#settings-status");

function formatHashrate(value) {
  if (!value || Number.isNaN(Number(value))) return "--";
  const units = ["H/s", "KH/s", "MH/s", "GH/s", "TH/s"];
  let rate = Number(value);
  let idx = 0;
  while (rate >= 1000 && idx < units.length - 1) {
    rate /= 1000;
    idx += 1;
  }
  return `${rate.toFixed(rate >= 100 ? 0 : 2)} ${units[idx]}`;
}

async function getJson(path) {
  const res = await fetch(path, { cache: "no-store" });
  if (!res.ok) throw new Error(`${path} returned ${res.status}`);
  return res.json();
}

async function rpc(method) {
  const res = await fetch("/api/geth", {
    method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ jsonrpc: "2.0", id: Date.now(), method, params: [] }),
  });
  if (!res.ok) throw new Error(`${method} returned ${res.status}`);
  const payload = await res.json();
  if (payload.error) throw new Error(payload.error.message || method);
  return payload.result;
}

function hexToNumber(value) {
  if (!value) return 0;
  return Number.parseInt(value, 16);
}

function formatNumber(value) {
  return Number(value || 0).toLocaleString();
}

function firstArray(value) {
  if (Array.isArray(value)) return value;
  if (value && Array.isArray(value.data)) return value.data;
  if (value && Array.isArray(value.result)) return value.result;
  return [];
}

async function refreshNode() {
  const [syncing, blockHex, peersHex] = await Promise.all([
    rpc("eth_syncing"),
    rpc("eth_blockNumber"),
    rpc("net_peerCount"),
  ]);

  const block = hexToNumber(blockHex);
  const peers = hexToNumber(peersHex);
  const isSyncing = syncing && syncing !== false;
  const current = isSyncing ? hexToNumber(syncing.currentBlock) : block;
  const highest = isSyncing ? hexToNumber(syncing.highestBlock) : block;
  const pct = highest > 0 ? Math.min(100, (current / highest) * 100) : 0;

  nodeSyncEl.textContent = isSyncing ? `${pct.toFixed(2)}%` : "100%";
  nodeBlockEl.textContent = formatNumber(current);
  highestBlockEl.textContent = formatNumber(highest);
  peersEl.textContent = formatNumber(peers);
  syncBarEl.style.width = `${isSyncing ? pct : 100}%`;
  nodeStatusEl.textContent = isSyncing
    ? `CoreGeth is syncing block ${formatNumber(current)} of ${formatNumber(highest)}. Wait for 100% before mining.`
    : `CoreGeth is synced at block ${formatNumber(block)} with ${formatNumber(peers)} peers.`;
}

async function refresh() {
  try {
    const [stats, blockStats] = await Promise.all([
      getJson("/api/stats"),
      getJson("/api/blocks"),
    ]);
    const blocks = [
      ...firstArray(blockStats.candidates),
      ...firstArray(blockStats.immature),
      ...firstArray(blockStats.matured),
    ];
    const luck = Array.isArray(blockStats.luck) ? blockStats.luck[0] : blockStats.luck;

    hashrateEl.textContent = formatHashrate(stats.hashrate);
    workersEl.textContent = stats.minersTotal ?? "--";
    blocksEl.textContent = (blockStats.candidatesTotal || 0) + (blockStats.immatureTotal || 0) + (blockStats.maturedTotal || 0);
    luckEl.textContent = luck ? `${Number(luck).toFixed(2)}%` : "--";

    if (blocks.length) {
      blockListEl.innerHTML = blocks.slice(0, 8).map((block) => {
        const height = block.height || block.blockHeight || "--";
        const status = block.status || block.type || "found";
        return `<div class="row"><span>Block ${height}</span><strong>${status}</strong></div>`;
      }).join("");
    }

    statusEl.textContent = "Online";
    statusEl.className = "pill ok";
  }
  catch (err) {
    statusEl.textContent = "API offline";
    statusEl.className = "pill bad";
  }

  try {
    await refreshNode();
  }
  catch (err) {
    nodeSyncEl.textContent = "--";
    nodeBlockEl.textContent = "--";
    highestBlockEl.textContent = "--";
    peersEl.textContent = "--";
    syncBarEl.style.width = "0%";
    nodeStatusEl.textContent = "CoreGeth RPC is not reachable yet.";
  }
}

refresh();
setInterval(refresh, 5000);

async function loadSettings() {
  try {
    const config = await getJson("/settings-api/config");
    for (const [key, value] of Object.entries(config)) {
      const input = settingsForm.elements.namedItem(key);
      if (input) input.value = value;
    }
  }
  catch (err) {
    settingsStatusEl.textContent = "Settings API is offline.";
  }
}

settingsForm.addEventListener("submit", async (event) => {
  event.preventDefault();
  const payload = {};
  for (const element of settingsForm.elements) {
    if (element.name) payload[element.name] = element.value.trim();
  }
  try {
    const res = await fetch("/settings-api/config", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    if (!res.ok) throw new Error(`Save failed with ${res.status}`);
    settingsStatusEl.textContent = "Saved. Restart the app in Umbrel to apply node and pool changes.";
  }
  catch (err) {
    settingsStatusEl.textContent = "Could not save settings.";
  }
});

loadSettings();
