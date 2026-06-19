<?php
header('Content-Type: application/json');
header('Cache-Control: no-store');

if (!file_exists(__DIR__ . '/../config.php')) {
    http_response_code(503);
    echo json_encode(['error' => 'Karashare is not installed. Run install.php first.']);
    exit;
}

require __DIR__ . '/../config.php';

$storage = defined('KARASHARE_STORAGE') ? KARASHARE_STORAGE : (__DIR__ . '/../storage');
$sessionDir = $storage . '/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0755, true);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = $input['action'] ?? $_GET['action'] ?? '';
$code = preg_replace('/[^A-Z0-9-]/', '', strtoupper($input['code'] ?? $_GET['code'] ?? ''));
$ttl = defined('KARASHARE_SESSION_TTL') ? KARASHARE_SESSION_TTL : 7200;

cleanup($sessionDir, $ttl);

try {
    if ($action === 'create') {
        $code = createCode($sessionDir);
        $phrase = (string)($input['phrase'] ?? '');
        $payload = [
            'code' => $code,
            'created' => time(),
            'updated' => time(),
            'phraseHash' => $phrase === '' ? '' : password_hash($phrase, PASSWORD_DEFAULT),
            'label' => trim((string)($input['label'] ?? '')),
            'nextSeq' => 1,
            'sender' => [],
            'receiver' => [],
            'messages' => []
        ];
        writeSession($sessionDir, $code, $payload);
        echo json_encode(['ok' => true, 'code' => $code]);
        exit;
    }

    if ($code === '' || !sessionExists($sessionDir, $code)) {
        http_response_code(404);
        echo json_encode(['error' => 'Session not found or expired.']);
        exit;
    }

    $session = readSession($sessionDir, $code);

    if (($session['phraseHash'] ?? '') !== '') {
        $phrase = (string)($input['phrase'] ?? '');
        if (!password_verify($phrase, $session['phraseHash'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Access phrase is incorrect.']);
            exit;
        }
    }

    if ($action === 'signal') {
        $role = ($input['role'] ?? '') === 'receiver' ? 'receiver' : 'sender';
        $type = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['type'] ?? 'message'));
        $data = $input['data'] ?? null;
        appendSignal($sessionDir, $code, [
            'id' => bin2hex(random_bytes(8)),
            'role' => $role,
            'type' => $type,
            'data' => $data,
            'time' => time()
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'poll') {
        $since = (int)($input['since'] ?? $_GET['since'] ?? 0);
        $role = ($input['role'] ?? $_GET['role'] ?? '') === 'receiver' ? 'receiver' : 'sender';
        $messages = array_values(array_filter($session['messages'] ?? [], function ($message) use ($role, $since) {
            return ($message['role'] ?? '') !== $role && (int)($message['seq'] ?? 0) > $since;
        }));
        echo json_encode(['ok' => true, 'messages' => $messages, 'label' => $session['label'] ?? '']);
        exit;
    }

    if ($action === 'close') {
        @unlink(pathFor($sessionDir, $code));
        echo json_encode(['ok' => true]);
        exit;
    }

    http_response_code(400);
    echo json_encode(['error' => 'Unknown action.']);
} catch (Throwable $error) {
    http_response_code(500);
    echo json_encode(['error' => 'Server error.', 'detail' => $error->getMessage()]);
}

function createCode($dir) {
    do {
        $code = 'KARA-' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    } while (sessionExists($dir, $code));
    return $code;
}

function pathFor($dir, $code) {
    return $dir . '/' . $code . '.json';
}

function sessionExists($dir, $code) {
    return $code !== '' && file_exists(pathFor($dir, $code));
}

function readSession($dir, $code) {
    $data = json_decode(file_get_contents(pathFor($dir, $code)), true);
    return is_array($data) ? $data : [];
}

function writeSession($dir, $code, $payload) {
    file_put_contents(pathFor($dir, $code), json_encode($payload), LOCK_EX);
}

function appendSignal($dir, $code, $message) {
    $path = pathFor($dir, $code);
    $handle = fopen($path, 'c+');
    if (!$handle) {
        throw new RuntimeException('Could not open session.');
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not lock session.');
        }
        rewind($handle);
        $raw = stream_get_contents($handle);
        $session = json_decode($raw, true);
        if (!is_array($session)) {
            $session = [];
        }
        $seq = (int)($session['nextSeq'] ?? 1);
        $message['seq'] = $seq;
        $session['messages'][] = $message;
        $session['nextSeq'] = $seq + 1;
        $session['updated'] = time();
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($session));
        fflush($handle);
        flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }
}

function cleanup($dir, $ttl) {
    foreach (glob($dir . '/*.json') ?: [] as $file) {
        if (time() - filemtime($file) > $ttl) {
            @unlink($file);
        }
    }
}
