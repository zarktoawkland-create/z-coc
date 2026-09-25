<?php
require_once __DIR__ . '/api_bootstrap.php';

app_send_cors_headers('GET, POST, OPTIONS');

$conn = app_db();
$action = $_GET['action'] ?? '';
$cloudConfig = app_config()['cloud'] ?? [];
$maxSyncBytes = max(1024 * 1024, (int)($cloudConfig['max_sync_bytes'] ?? (16 * 1024 * 1024)));
$authConfig = app_config()['auth'] ?? [];
$sessionTtlDays = min(90, max(1, (int)($authConfig['session_ttl_days'] ?? 30)));
$maxSessionsPerUser = min(32, max(1, (int)($authConfig['max_sessions_per_user'] ?? 8)));

function ensure_auth_schema($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS users (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        email VARCHAR(255) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        user_uuid VARCHAR(64) NOT NULL UNIQUE,
        auth_token VARCHAR(128) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $conn->query("CREATE TABLE IF NOT EXISTS user_data (
        user_id VARCHAR(64) NOT NULL,
        settings LONGTEXT NULL,
        investigators LONGTEXT NULL,
        saves LONGTEXT NULL,
        tavern_novels LONGTEXT NULL,
        revision BIGINT UNSIGNED NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // New sessions allow several devices to stay signed in. The legacy
    // users.auth_token column remains for backwards compatibility and is
    // gradually retired as clients receive the new session token.
    $conn->query("CREATE TABLE IF NOT EXISTS user_sessions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_uuid VARCHAR(64) NOT NULL,
        token_hash CHAR(64) NOT NULL,
        device_id VARCHAR(80) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        revoked_at TIMESTAMP NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_user_sessions_token (token_hash),
        KEY idx_user_sessions_user (user_uuid, revoked_at, expires_at),
        KEY idx_user_sessions_expiry (expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $table = 'users';
    $authColumn = 'auth_token';
    $revisionColumn = 'revision';
    $stmt->bind_param('ss', $table, $authColumn);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);
    if ((int)($row['n'] ?? 0) === 0) {
        $conn->query("ALTER TABLE users ADD COLUMN auth_token VARCHAR(128) NULL");
    }

    $stmt = $conn->prepare("SELECT COUNT(*) AS n FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $userDataTable = 'user_data';
    $stmt->bind_param('ss', $userDataTable, $revisionColumn);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);
    if ((int)($row['n'] ?? 0) === 0) {
        $conn->query("ALTER TABLE user_data ADD COLUMN revision BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER tavern_novels");
    }
}

function sanitize_cloud_settings($settings) {
    if (!is_array($settings)) return [];

    if (isset($settings['api']) && is_array($settings['api'])) {
        unset($settings['api']['customApiKey'], $settings['api']['imageGenApiKey']);
        if (isset($settings['api']['apiPresets']) && is_array($settings['api']['apiPresets'])) {
            foreach ($settings['api']['apiPresets'] as &$preset) {
                if (is_array($preset)) unset($preset['key']);
            }
            unset($preset);
        }
    }

    return $settings;
}

function json_column($value) {
    if ($value === null || $value === '') return null;
    $decoded = json_decode($value, true);
    return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
}

function list_json_column($value, $field) {
    if ($value === null || $value === '') return [];
    $decoded = json_decode($value, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        app_json_response(['status' => 'error', 'message' => 'Stored ' . $field . ' data is invalid'], 500);
    }
    return $decoded;
}

function encode_json_or_fail($value, $field) {
    $encoded = json_encode($value, JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        app_json_response(['status' => 'error', 'message' => $field . ' contains invalid text'], 400);
    }
    return $encoded;
}

function require_cloud_list($value, $field) {
    if (!is_array($value)) {
        app_json_response(['status' => 'error', 'message' => $field . ' must be an array'], 400);
    }
    return $value;
}

function hash_auth_token($token) {
    return hash('sha256', (string)$token);
}

function issue_auth_session($conn, $userId, $deviceId, $ttlDays, $maxSessions) {
    $token = bin2hex(random_bytes(32));
    $tokenHash = hash_auth_token($token);
    $deviceId = trim((string)$deviceId);
    if ($deviceId === '' || strlen($deviceId) > 80) $deviceId = 'web';

    // Keep a bounded number of active sessions per account. This is best
    // effort so a legacy database without the new table can still log in.
    $stmt = @$conn->prepare("DELETE FROM user_sessions WHERE user_uuid = ? AND (revoked_at IS NOT NULL OR expires_at <= NOW())");
    if ($stmt) {
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $stmt->close();
    }
    $stmt = @$conn->prepare("SELECT id FROM user_sessions WHERE user_uuid = ? AND revoked_at IS NULL AND expires_at > NOW() ORDER BY last_seen_at DESC LIMIT 18446744073709551615 OFFSET ?");
    if ($stmt) {
        $offset = max(0, (int)$maxSessions - 1);
        $stmt->bind_param('si', $userId, $offset);
        $stmt->execute();
        $oldest = app_stmt_fetch_assoc($stmt);
        $stmt->close();
        if ($oldest) {
            $delete = $conn->prepare("DELETE FROM user_sessions WHERE user_uuid = ? AND id <= ?");
            if ($delete) {
                $oldestId = (int)$oldest['id'];
                $delete->bind_param('si', $userId, $oldestId);
                $delete->execute();
                $delete->close();
            }
        }
    }

    $stmt = @$conn->prepare("INSERT INTO user_sessions (user_uuid, token_hash, device_id, expires_at) VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))");
    if ($stmt) {
        $stmt->bind_param('sssi', $userId, $tokenHash, $deviceId, $ttlDays);
        if (!$stmt->execute()) {
            $stmt->close();
            return ['token' => $token, 'stored' => false];
        }
        $stmt->close();
        return ['token' => $token, 'stored' => true];
    }
    return ['token' => $token, 'stored' => false];
}

function require_user_token($conn, $userId) {
    $token = app_get_bearer_token();
    if ($token === '' || strlen($token) > 128) {
        app_json_response(['status' => 'error', 'message' => 'Login required'], 401);
    }

    $tokenHash = hash_auth_token($token);
    $stmt = @$conn->prepare("SELECT id FROM user_sessions WHERE user_uuid = ? AND token_hash = ? AND revoked_at IS NULL AND expires_at > NOW() LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('ss', $userId, $tokenHash);
        $stmt->execute();
        $session = app_stmt_fetch_assoc($stmt);
        $stmt->close();
        if ($session) {
            $touch = $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE id = ?");
            if ($touch) {
                $sessionId = (int)$session['id'];
                $touch->bind_param('i', $sessionId);
                $touch->execute();
                $touch->close();
            }
            return $token;
        }
    }

    // Compatibility path for tokens issued before user_sessions existed.
    $stmt = $conn->prepare("SELECT auth_token FROM users WHERE user_uuid = ? AND (auth_token = ? OR auth_token = ?) LIMIT 1");
    $stmt->bind_param('sss', $userId, $tokenHash, $token);
    $stmt->execute();
    $user = app_stmt_fetch_assoc($stmt);
    if (!$user) {
        app_json_response(['status' => 'error', 'message' => 'Login expired'], 401);
    }
    if (hash_equals((string)$user['auth_token'], $token)) {
        $migrate = $conn->prepare("UPDATE users SET auth_token = ? WHERE user_uuid = ?");
        $migrate->bind_param('ss', $tokenHash, $userId);
        $migrate->execute();
    }
    return $token;
}

function authenticated_user($conn) {
    $token = app_get_bearer_token();
    if ($token === '' || strlen($token) > 128) {
        app_json_response(['status' => 'error', 'message' => 'Login required'], 401);
    }
    $tokenHash = hash_auth_token($token);
    $stmt = @$conn->prepare("SELECT u.user_uuid, u.email, u.created_at FROM user_sessions s INNER JOIN users u ON u.user_uuid = s.user_uuid WHERE s.token_hash = ? AND s.revoked_at IS NULL AND s.expires_at > NOW() LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $tokenHash);
        $stmt->execute();
        $user = app_stmt_fetch_assoc($stmt);
        $stmt->close();
        if ($user) {
            $touch = $conn->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE token_hash = ?");
            if ($touch) {
                $touch->bind_param('s', $tokenHash);
                $touch->execute();
                $touch->close();
            }
            return [$user, $tokenHash];
        }
    }

    $stmt = $conn->prepare("SELECT user_uuid, email, created_at FROM users WHERE auth_token = ? OR auth_token = ? LIMIT 1");
    $stmt->bind_param('ss', $tokenHash, $token);
    $stmt->execute();
    $user = app_stmt_fetch_assoc($stmt);
    if (!$user) app_json_response(['status' => 'error', 'message' => 'Login expired'], 401);
    if (hash_equals((string)$user['auth_token'], $token)) {
        $migrate = $conn->prepare("UPDATE users SET auth_token = ? WHERE user_uuid = ?");
        $migrate->bind_param('ss', $tokenHash, $user['user_uuid']);
        $migrate->execute();
    }
    return [$user, $tokenHash];
}

ensure_auth_schema($conn);
app_run_periodically('auth_sessions_cleanup_v1', 1800, function () use ($conn) {
    return (bool)$conn->query("DELETE FROM user_sessions WHERE revoked_at IS NOT NULL OR expires_at <= NOW()");
});

if ($action === 'register') {
    app_require_method('POST');
    app_rate_limit($conn, 'auth', 20, 300);
    app_require_content_length(64 * 1024);
    $data = app_read_json_body();
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        app_json_response(['status' => 'error', 'message' => '邮箱格式不正确'], 400);
    }
    if (strlen($password) < 8 || strlen($password) > 4096) {
        app_json_response(['status' => 'error', 'message' => '密码需要 8 至 4096 位'], 400);
    }

    $hashedPass = password_hash($password, PASSWORD_DEFAULT);
    $userId = bin2hex(random_bytes(16));

    // Keep the legacy token column untouched when the sidecar session table
    // is available, so the original website backend can keep its own sessions.
    $stmt = $conn->prepare("INSERT INTO users (email, password, user_uuid) VALUES (?, ?, ?)");
    $stmt->bind_param('sss', $email, $hashedPass, $userId);
    if (!$stmt->execute()) {
        if ((int)$stmt->errno === 1062) {
            app_json_response(['status' => 'error', 'message' => '邮箱已被注册'], 409);
        }
        app_json_response(['status' => 'error', 'message' => '注册暂时不可用'], 500);
    }

    // Create the multi-device session only after the user row exists.
    $session = issue_auth_session($conn, $userId, $data['device_id'] ?? 'web', $sessionTtlDays, $maxSessionsPerUser);
    $authToken = $session['token'];
    if (empty($session['stored'])) {
        $authTokenHash = hash_auth_token($authToken);
        $stmt = $conn->prepare("UPDATE users SET auth_token = ? WHERE user_uuid = ?");
        $stmt->bind_param('ss', $authTokenHash, $userId);
        $stmt->execute();
    }

    app_json_response(['status' => 'success', 'user_id' => $userId, 'auth_token' => $authToken]);
}

if ($action === 'login') {
    app_require_method('POST');
    app_rate_limit($conn, 'auth', 30, 300);
    app_require_content_length(64 * 1024);
    $data = app_read_json_body();
    $email = trim((string)($data['email'] ?? ''));
    $password = (string)($data['password'] ?? '');

    if (strlen($email) > 254 || strlen($password) > 4096) {
        app_json_response(['status' => 'error', 'message' => '账号或密码错误'], 400);
    }

    $stmt = $conn->prepare("SELECT password, user_uuid FROM users WHERE email = ? LIMIT 1");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $user = app_stmt_fetch_assoc($stmt);

    if (!$user || !password_verify($password, $user['password'])) {
        app_json_response(['status' => 'error', 'message' => '账号或密码错误'], 401);
    }

    $session = issue_auth_session($conn, $user['user_uuid'], $data['device_id'] ?? 'web', $sessionTtlDays, $maxSessionsPerUser);
    $authToken = $session['token'];
    if (empty($session['stored'])) {
        $authTokenHash = hash_auth_token($authToken);
        $stmt = $conn->prepare("UPDATE users SET auth_token = ? WHERE user_uuid = ?");
        $stmt->bind_param('ss', $authTokenHash, $user['user_uuid']);
        if (!$stmt->execute()) {
            app_json_response(['status' => 'error', 'message' => '登录暂时不可用'], 500);
        }
    }

    app_json_response(['status' => 'success', 'user_id' => $user['user_uuid'], 'auth_token' => $authToken]);
}

if ($action === 'me') {
    app_require_method('GET');
    app_rate_limit($conn, 'auth_me', 240, 300);
    [$user] = authenticated_user($conn);
    app_json_response([
        'status' => 'success',
        'user' => [
            'id' => $user['user_uuid'],
            'email' => $user['email'],
            'created_at' => $user['created_at'],
        ],
    ]);
}

if ($action === 'pull') {
    app_require_method('GET');
    app_rate_limit($conn, 'cloud_pull', 240, 300);
    $userId = app_normalize_user_id($_GET['user_id'] ?? '');
    require_user_token($conn, $userId);

    $stmt = $conn->prepare("SELECT settings, investigators, saves, tavern_novels, revision, updated_at FROM user_data WHERE user_id = ? LIMIT 1");
    $stmt->bind_param('s', $userId);
    $stmt->execute();
    $row = app_stmt_fetch_assoc($stmt);
    if (!$row) app_json_response(null);

    $settings = sanitize_cloud_settings(json_column($row['settings']));
    $revision = (int)($row['revision'] ?? 0);
    $updatedAt = $row['updated_at'] ?? null;
    $etag = '"' . sha1($userId . '|' . $revision . '|' . (string)$updatedAt) . '"';
    header('ETag: ' . $etag);
    app_json_response([
        'settings' => $settings,
        'investigators' => list_json_column($row['investigators'], 'investigators'),
        'saves' => list_json_column($row['saves'], 'saves'),
        'tavern_novels' => list_json_column($row['tavern_novels'], 'tavern_novels'),
        'revision' => $revision,
        'updated_at' => $updatedAt,
    ]);
}

if ($action === 'sync') {
    app_require_method('POST');
    app_rate_limit($conn, 'cloud_sync', 120, 300);
    app_require_content_length($maxSyncBytes);
    $data = app_read_json_body();
    $userId = app_normalize_user_id($data['user_id'] ?? '');
    require_user_token($conn, $userId);

    $settings = sanitize_cloud_settings($data['settings'] ?? []);
    $investigators = require_cloud_list($data['investigators'] ?? [], 'investigators');
    $saves = require_cloud_list($data['saves'] ?? [], 'saves');
    $tavernNovels = require_cloud_list($data['tavern_novels'] ?? [], 'tavern_novels');
    $baseRevision = filter_var($data['base_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
    if ($baseRevision === false) {
        app_json_response(['status' => 'error', 'message' => 'base_revision is required'], 400);
    }

    $settingsJson = encode_json_or_fail($settings, 'settings');
    $investigatorsJson = encode_json_or_fail($investigators, 'investigators');
    $savesJson = encode_json_or_fail($saves, 'saves');
    $tavernNovelsJson = encode_json_or_fail($tavernNovels, 'tavern_novels');

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT revision, updated_at FROM user_data WHERE user_id = ? FOR UPDATE");
        $stmt->bind_param('s', $userId);
        $stmt->execute();
        $current = app_stmt_fetch_assoc($stmt);
        $currentRevision = (int)($current['revision'] ?? 0);

        if ((int)$baseRevision !== $currentRevision) {
            $conn->rollback();
            app_json_response([
                'status' => 'conflict',
                'message' => 'Cloud data changed on another device',
                'revision' => $currentRevision,
                'updated_at' => $current['updated_at'] ?? null,
            ], 409);
        }

        $nextRevision = $currentRevision + 1;
        if ($current) {
            $stmt = $conn->prepare("UPDATE user_data SET settings = ?, investigators = ?, saves = ?, tavern_novels = ?, revision = ? WHERE user_id = ?");
            $stmt->bind_param('ssssis', $settingsJson, $investigatorsJson, $savesJson, $tavernNovelsJson, $nextRevision, $userId);
        } else {
            $stmt = $conn->prepare("INSERT INTO user_data (user_id, settings, investigators, saves, tavern_novels, revision) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sssssi', $userId, $settingsJson, $investigatorsJson, $savesJson, $tavernNovelsJson, $nextRevision);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Cloud write failed');
        }
        $conn->commit();
        app_json_response(['status' => 'success', 'revision' => $nextRevision]);
    } catch (Throwable $error) {
        $conn->rollback();
        app_json_response(['status' => 'error', 'message' => '云端数据保存失败'], 500);
    }
}

if ($action === 'logout') {
    app_require_method('POST');
    app_rate_limit($conn, 'auth_logout', 60, 300);
    app_require_content_length(64 * 1024);
    $data = app_read_json_body();
    $userId = app_normalize_user_id($data['user_id'] ?? '');
    require_user_token($conn, $userId);

    $tokenHash = hash_auth_token(app_get_bearer_token());
    $sessionStmt = @$conn->prepare("UPDATE user_sessions SET revoked_at = NOW() WHERE user_uuid = ? AND token_hash = ? AND revoked_at IS NULL");
    if ($sessionStmt) {
        $sessionStmt->bind_param('ss', $userId, $tokenHash);
        $sessionStmt->execute();
        $sessionStmt->close();
    }
    $stmt = $conn->prepare("UPDATE users SET auth_token = NULL WHERE user_uuid = ? AND auth_token = ?");
    $stmt->bind_param('ss', $userId, $tokenHash);
    if (!$stmt->execute()) {
        app_json_response(['status' => 'error', 'message' => '退出登录失败'], 500);
    }
    app_json_response(['status' => 'success']);
}

app_json_response(['status' => 'error', 'message' => 'Unknown action'], 404);
?>
