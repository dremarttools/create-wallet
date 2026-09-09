<?php
declare(strict_types=1);

// Dremart Wallet affiliate database settings.
// Replace these values only on your server.
define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DATABASE_NAME');
define('DB_USER', 'YOUR_DATABASE_USER');
define('DB_PASS', 'YOUR_DATABASE_PASSWORD');

// Affiliate program settings.
define('AWE_AFFILIATE_SHARE_PERCENT', 20.0);
define('AWE_BRIDGE_FEE_PERCENT', 0.5);
define('AWE_SWAP_FEE_PERCENT', 0.5);
define('AWE_MIN_WITHDRAW_USD', 5.00);
define(
    'AWE_SITE_BASE_URL',
    'https://www.dremart.com/web3/create-wallet/'
);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('awe_affiliate_session');

    session_set_cookie_params([
        'lifetime' => 60 * 60 * 24 * 30,
        'path' => '/create-wallet/',
        'secure' => (
            !empty($_SERVER['HTTPS'])
            && $_SERVER['HTTPS'] !== 'off'
        ),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . DB_HOST
        . ';dbname=' . DB_NAME
        . ';charset=utf8mb4';

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}

function json_input(): array
{
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        return [];
    }

    $data = json_decode($raw, true);

    return is_array($data) ? $data : [];
}

function respond(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function require_post(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        respond([
            'ok' => false,
            'error' => 'POST required',
        ], 405);
    }
}

function current_affiliate_id(): int
{
    return isset($_SESSION['affiliate_id'])
        ? (int) $_SESSION['affiliate_id']
        : 0;
}

function require_affiliate(): int
{
    $id = current_affiliate_id();

    if ($id <= 0) {
        respond([
            'ok' => false,
            'error' => 'Login required',
        ], 401);
    }

    return $id;
}

function make_referral_code(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    do {
        $code = '';

        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[
                random_int(0, strlen($alphabet) - 1)
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT id
             FROM affiliates
             WHERE referral_code = ?
             LIMIT 1'
        );

        $stmt->execute([$code]);
    } while ($stmt->fetch());

    return $code;
}

function clean_wallet(string $value): string
{
    $value = trim($value);

    if ($value === '' || strlen($value) > 160) {
        return '';
    }

    return $value;
}

function affiliate_by_ref(PDO $pdo, string $code): ?array
{
    $stmt = $pdo->prepare(
        "SELECT id, referral_code
         FROM affiliates
         WHERE referral_code = ?
         AND status = 'active'
         LIMIT 1"
    );

    $stmt->execute([strtoupper(trim($code))]);

    $row = $stmt->fetch();

    return $row ?: null;
}

function lifi_status(
    string $txHash,
    string $fromChain = '',
    string $toChain = '',
    string $bridge = ''
): array {
    $params = [
        'txHash' => $txHash,
    ];

    if ($fromChain !== '') {
        $params['fromChain'] = $fromChain;
    }

    if ($toChain !== '') {
        $params['toChain'] = $toChain;
    }

    if ($bridge !== '') {
        $params['bridge'] = $bridge;
    }

    $url = 'https://li.quest/v1/status?'
        . http_build_query($params);

    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
        ],
        CURLOPT_USERAGENT => 'Dremart-Wallet-Affiliate/4.0',
    ]);

    $body = curl_exec($ch);
    $code = (int) curl_getinfo(
        $ch,
        CURLINFO_RESPONSE_CODE
    );
    $error = curl_error($ch);

    curl_close($ch);

    if (
        $body === false
        || $code < 200
        || $code >= 300
    ) {
        throw new RuntimeException(
            $error ?: 'Unable to verify LI.FI transaction'
        );
    }

    $data = json_decode($body, true);

    if (!is_array($data)) {
        throw new RuntimeException(
            'Invalid LI.FI status response'
        );
    }

    return $data;
}
