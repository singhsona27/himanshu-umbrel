<?php
$root = __DIR__;
$storage = $root . '/storage';
$sessions = $storage . '/sessions';
$base = detectBaseUrl();
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $base = rtrim(trim($_POST['base_url'] ?? $base), '/');
    if (!is_dir($storage) && !mkdir($storage, 0755, true)) {
        $errors[] = 'Could not create storage directory.';
    }
    if (!is_dir($sessions) && !mkdir($sessions, 0755, true)) {
        $errors[] = 'Could not create session directory.';
    }
    if (!is_writable($storage) || !is_writable($sessions)) {
        $errors[] = 'Storage directories are not writable. Set storage/ and storage/sessions/ to 755 or 775.';
    }
    $config = "<?php\n";
    $config .= "define('KARASHARE_BASE_URL', " . var_export($base, true) . ");\n";
    $config .= "define('KARASHARE_STORAGE', __DIR__ . '/storage');\n";
    $config .= "define('KARASHARE_SESSION_TTL', 7200);\n";
    $config .= "define('KARASHARE_ICE_SERVERS', [\n";
    $config .= "    ['urls' => 'stun:stun.l.google.com:19302'],\n";
    $config .= "    ['urls' => 'stun:global.stun.twilio.com:3478'],\n";
    $config .= "    // For reliable mobile-data and strict-network transfers, add your TURN server here:\n";
    $config .= "    // ['urls' => 'turn:turn.example.com:3478', 'username' => 'user', 'credential' => 'pass'],\n";
    $config .= "]);\n";
    if (!$errors && file_put_contents($root . '/config.php', $config, LOCK_EX) === false) {
        $errors[] = 'Could not write config.php.';
    }
    $done = !$errors;
}

function detectBaseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'hosting.karacraft.ng';
    $path = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . ($path === '' ? '' : $path);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Install Karashare</title>
  <style>
    body{margin:0;font-family:Inter,Arial,sans-serif;background:#07100f;color:#f3fff9;display:grid;min-height:100vh;place-items:center}
    main{width:min(720px,92vw);background:#101b1b;border:1px solid #25403c;border-radius:22px;padding:32px;box-shadow:0 30px 80px #0008}
    h1{margin:0 0 10px;font-size:34px}p{color:#b7c9c4;line-height:1.6}label{display:block;margin:24px 0 8px;color:#dff8ef}
    input{width:100%;box-sizing:border-box;padding:15px 16px;border-radius:14px;border:1px solid #32534d;background:#091312;color:#fff}
    button,a.button{display:inline-block;margin-top:18px;padding:14px 20px;border:0;border-radius:14px;background:#48f5a6;color:#03100c;font-weight:800;text-decoration:none;cursor:pointer}
    .error{background:#3b141c;border:1px solid #8d3142;padding:12px 14px;border-radius:12px}.ok{background:#113d2e;border:1px solid #37b177;padding:12px 14px;border-radius:12px}
    code{background:#091312;padding:3px 7px;border-radius:7px}
  </style>
</head>
<body>
  <main>
    <h1>Install Karashare</h1>
    <p>This creates the local config and writable session store used for WebRTC signaling. File payloads are still transferred peer-to-peer.</p>
    <?php if ($errors): ?><div class="error"><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div><?php endif; ?>
    <?php if ($done): ?>
      <div class="ok">Karashare is installed. For security, delete <code>install.php</code> after confirming the homepage works.</div>
      <a class="button" href="index.php">Open Karashare</a>
    <?php else: ?>
      <form method="post">
        <label for="base_url">Site URL</label>
        <input id="base_url" name="base_url" value="<?php echo htmlspecialchars($base, ENT_QUOTES); ?>">
        <button type="submit">Install now</button>
      </form>
    <?php endif; ?>
  </main>
</body>
</html>
