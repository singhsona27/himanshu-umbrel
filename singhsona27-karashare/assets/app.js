(function () {
  const state = {
    mode: 'send',
    files: [],
    note: '',
    code: '',
    phrase: '',
    role: '',
    pc: null,
    channel: null,
    pendingCandidates: [],
    outgoingCandidates: [],
    canSignalCandidates: false,
    pollTimer: null,
    lastMessage: 0,
    receiveBuffers: new Map(),
    incoming: new Map()
  };

  const $ = (selector) => document.querySelector(selector);
  const installed = document.body.dataset.installed === 'true';
  const apiUrl = 'api/signaling.php';
  const chunkSize = 64 * 1024;

  const els = {
    tabs: document.querySelectorAll('.mode-tab'),
    panels: document.querySelectorAll('.panel'),
    drop: $('.drop-zone'),
    fileInput: $('#fileInput'),
    noteInput: $('#noteInput'),
    passwordInput: $('#passwordInput'),
    labelInput: $('#labelInput'),
    startShare: $('#startShare'),
    shareCard: $('#shareCard'),
    shareCode: $('#shareCode'),
    shareLink: $('#shareLink'),
    copyLink: $('#copyLink'),
    qrCode: $('#qrCode'),
    receiveCode: $('#receiveCode'),
    receivePassword: $('#receivePassword'),
    joinShare: $('#joinShare'),
    status: $('#statusText'),
    fileList: $('#fileList')
  };

  function setStatus(message, isError) {
    els.status.textContent = message;
    els.status.style.color = isError ? 'var(--red)' : '';
  }

  function switchMode(mode) {
    state.mode = mode;
    els.tabs.forEach((tab) => tab.classList.toggle('active', tab.dataset.mode === mode));
    els.panels.forEach((panel) => panel.classList.toggle('active', panel.dataset.panel === mode));
  }

  function prettyBytes(bytes) {
    if (!bytes) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const power = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    return `${(bytes / Math.pow(1024, power)).toFixed(power ? 1 : 0)} ${units[power]}`;
  }

  function renderFiles() {
    els.fileList.innerHTML = '';
    state.files.forEach((file) => {
      const row = document.createElement('div');
      row.className = 'file-row';
      row.innerHTML = `<span>${escapeHtml(file.webkitRelativePath || file.name)}</span><strong>${prettyBytes(file.size)}</strong>`;
      els.fileList.appendChild(row);
    });
  }

  function escapeHtml(value) {
    return String(value).replace(/[&<>"']/g, (char) => ({
      '&': '&amp;',
      '<': '&lt;',
      '>': '&gt;',
      '"': '&quot;',
      "'": '&#39;'
    }[char]));
  }

  function setFiles(fileList) {
    state.files = Array.from(fileList || []);
    renderFiles();
    setStatus(state.files.length ? `${state.files.length} item(s) staged for direct transfer.` : 'Ready to create a private route.');
  }

  async function post(action, payload) {
    const response = await fetch(apiUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action, ...payload })
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      throw new Error(data.error || 'Karashare server rejected the request.');
    }
    return data;
  }

  function makePeer(role) {
    const pc = new RTCPeerConnection({
      iceServers: Array.isArray(window.KARASHARE_ICE_SERVERS) ? window.KARASHARE_ICE_SERVERS : [{ urls: 'stun:stun.l.google.com:19302' }]
    });

    pc.onicecandidate = ({ candidate }) => {
      if (candidate) queueLocalCandidate(candidate);
    };
    pc.onconnectionstatechange = () => {
      setStatus(`Connection state: ${pc.connectionState}.`);
      if (['failed', 'disconnected'].includes(pc.connectionState)) {
        setStatus('Connection could not complete. If sender and receiver are on different mobile/office networks, add a TURN server in config.php.', true);
      }
    };

    state.pc = pc;
    state.role = role;
    state.pendingCandidates = [];
    state.outgoingCandidates = [];
    state.canSignalCandidates = false;
    state.lastMessage = 0;
    return pc;
  }

  function queueLocalCandidate(candidate) {
    const payload = typeof candidate.toJSON === 'function' ? candidate.toJSON() : candidate;
    if (!state.canSignalCandidates) {
      state.outgoingCandidates.push(payload);
      return;
    }
    signal('candidate', payload).catch((error) => setStatus(error.message, true));
  }

  async function flushLocalCandidates() {
    state.canSignalCandidates = true;
    while (state.outgoingCandidates.length) {
      await signal('candidate', state.outgoingCandidates.shift());
    }
  }

  async function signal(type, data) {
    await post('signal', {
      code: state.code,
      phrase: state.phrase,
      role: state.role,
      type,
      data
    });
  }

  async function poll() {
    try {
      const data = await post('poll', {
        code: state.code,
        phrase: state.phrase,
        role: state.role,
        since: state.lastMessage
      });
      for (const message of data.messages || []) {
        state.lastMessage = Math.max(state.lastMessage, Number(message.seq || 0));
        await handleSignal(message.type, message.data);
      }
    } catch (error) {
      setStatus(error.message, true);
      stopPolling();
    }
  }

  function startPolling() {
    stopPolling();
    state.pollTimer = setInterval(poll, 1200);
    poll();
  }

  function stopPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    state.pollTimer = null;
  }

  async function handleSignal(type, data) {
    if (!state.pc) return;
    if (type === 'offer') {
      await state.pc.setRemoteDescription(new RTCSessionDescription(data));
      await flushPendingCandidates();
      const answer = await state.pc.createAnswer();
      await state.pc.setLocalDescription(answer);
      await signal('answer', answer);
      await flushLocalCandidates();
      setStatus('Offer accepted. Creating encrypted return route...');
    }
    if (type === 'answer') {
      await state.pc.setRemoteDescription(new RTCSessionDescription(data));
      await flushPendingCandidates();
      await flushLocalCandidates();
      setStatus('Receiver connected. Ready to stream files.');
    }
    if (type === 'candidate') {
      if (state.pc.remoteDescription) {
        await state.pc.addIceCandidate(new RTCIceCandidate(data));
      } else {
        state.pendingCandidates.push(data);
      }
    }
  }

  async function flushPendingCandidates() {
    while (state.pendingCandidates.length) {
      const candidate = state.pendingCandidates.shift();
      if (state.pc.remoteDescription) {
        await state.pc.addIceCandidate(new RTCIceCandidate(candidate));
      } else {
        state.pendingCandidates.unshift(candidate);
        return;
      }
    }
  }

  async function startShare() {
    if (!installed) {
      setStatus('Run install.php after upload before creating live transfer links.', true);
      return;
    }
    state.note = els.noteInput.value.trim();
    if (!state.files.length && !state.note) {
      setStatus('Add at least one file or secure note first.', true);
      return;
    }

    state.phrase = els.passwordInput.value;
    const created = await post('create', {
      phrase: state.phrase,
      label: els.labelInput.value
    });
    state.code = created.code;

    const pc = makePeer('sender');
    const channel = pc.createDataChannel('karashare', { ordered: true });
    setupChannel(channel);
    const offer = await pc.createOffer();
    await pc.setLocalDescription(offer);
    await signal('offer', offer);
    await flushLocalCandidates();
    startPolling();

    const link = `${location.origin}${location.pathname}?receive=${encodeURIComponent(state.code)}`;
    els.shareCode.textContent = state.code;
    els.shareLink.textContent = link;
    els.shareCard.classList.remove('hidden');
    drawQr(link);
    setStatus('Secure invite created. Keep this tab open while the receiver joins.');
  }

  async function joinShare() {
    if (!installed) {
      setStatus('Run install.php after upload before joining transfers.', true);
      return;
    }
    state.code = els.receiveCode.value.trim().toUpperCase();
    state.phrase = els.receivePassword.value;
    if (!state.code) {
      setStatus('Enter an invite code first.', true);
      return;
    }

    const pc = makePeer('receiver');
    pc.ondatachannel = (event) => setupChannel(event.channel);
    startPolling();
    setStatus('Looking for the sender. Keep this tab open.');
  }

  function setupChannel(channel) {
    state.channel = channel;
    channel.binaryType = 'arraybuffer';
    channel.onopen = () => {
      setStatus(state.role === 'sender' ? 'Receiver is online. Streaming can begin.' : 'Encrypted route open. Waiting for payload...');
      if (state.role === 'sender') sendPayloads();
    };
    channel.onmessage = (event) => receiveMessage(event.data);
    channel.onclose = () => setStatus('Transfer route closed.');
  }

  async function sendPayloads() {
    if (state.note) {
      state.channel.send(JSON.stringify({ type: 'note', value: state.note }));
    }
    for (let index = 0; index < state.files.length; index += 1) {
      await sendFile(state.files[index], index);
    }
    state.channel.send(JSON.stringify({ type: 'done' }));
    setStatus('All payloads sent.');
  }

  function waitForBuffer() {
    return new Promise((resolve) => {
      const check = () => {
        if (!state.channel || state.channel.bufferedAmount < 4 * chunkSize) resolve();
        else setTimeout(check, 80);
      };
      check();
    });
  }

  async function sendFile(file, id) {
    const name = file.webkitRelativePath || file.name;
    state.channel.send(JSON.stringify({ type: 'file-start', id, name, size: file.size, mime: file.type }));
    let offset = 0;
    while (offset < file.size) {
      const slice = file.slice(offset, offset + chunkSize);
      state.channel.send(await slice.arrayBuffer());
      offset += chunkSize;
      setStatus(`Sending ${name}: ${Math.min(100, Math.round((offset / file.size) * 100))}%`);
      await waitForBuffer();
    }
    state.channel.send(JSON.stringify({ type: 'file-end', id }));
  }

  function receiveMessage(data) {
    if (typeof data === 'string') {
      const message = JSON.parse(data);
      if (message.type === 'note') addDownload('secure-note.txt', new Blob([message.value], { type: 'text/plain' }));
      if (message.type === 'file-start') {
        state.incoming.set(message.id, message);
        state.receiveBuffers.set(message.id, []);
        setStatus(`Receiving ${message.name}...`);
      }
      if (message.type === 'file-end') {
        const meta = state.incoming.get(message.id);
        const blob = new Blob(state.receiveBuffers.get(message.id), { type: meta.mime || 'application/octet-stream' });
        addDownload(meta.name, blob);
        state.receiveBuffers.delete(message.id);
        state.incoming.delete(message.id);
        setStatus(`${meta.name} received.`);
      }
      if (message.type === 'done') setStatus('Transfer complete. Downloads are ready below.');
      return;
    }

    const keys = Array.from(state.receiveBuffers.keys());
    const active = keys.length ? keys[keys.length - 1] : undefined;
    if (active !== undefined) {
      state.receiveBuffers.get(active).push(data);
    }
  }

  function addDownload(name, blob) {
    const row = document.createElement('div');
    row.className = 'file-row';
    const url = URL.createObjectURL(blob);
    row.innerHTML = `<span>${escapeHtml(name)}</span><a download="${escapeHtml(name)}" href="${url}">Download</a>`;
    els.fileList.appendChild(row);
  }

  async function copyLink() {
    const link = els.shareLink.textContent;
    await navigator.clipboard.writeText(link);
    setStatus('Invite link copied.');
  }

  function drawQr(text) {
    els.qrCode.innerHTML = '';
    const QR = window.QRCode || (typeof QRCode !== 'undefined' ? QRCode : null);
    if (!QR) {
      els.qrCode.textContent = 'QR unavailable';
      return;
    }
    new QR(els.qrCode, {
      text,
      width: 148,
      height: 148,
      colorDark: '#06110f',
      colorLight: '#ffffff',
      correctLevel: QR.CorrectLevel.M
    });
  }

  els.tabs.forEach((tab) => tab.addEventListener('click', () => switchMode(tab.dataset.mode)));
  els.fileInput.addEventListener('change', (event) => setFiles(event.target.files));
  els.drop.addEventListener('dragover', (event) => {
    event.preventDefault();
    els.drop.classList.add('dragover');
  });
  els.drop.addEventListener('dragleave', () => els.drop.classList.remove('dragover'));
  els.drop.addEventListener('drop', (event) => {
    event.preventDefault();
    els.drop.classList.remove('dragover');
    setFiles(event.dataTransfer.files);
  });
  els.startShare.addEventListener('click', () => startShare().catch((error) => setStatus(error.message, true)));
  els.joinShare.addEventListener('click', () => joinShare().catch((error) => setStatus(error.message, true)));
  els.copyLink.addEventListener('click', () => copyLink().catch(() => setStatus('Could not copy link.', true)));

  const params = new URLSearchParams(location.search);
  if (params.has('receive')) {
    switchMode('receive');
    els.receiveCode.value = params.get('receive') || '';
  }
}());
