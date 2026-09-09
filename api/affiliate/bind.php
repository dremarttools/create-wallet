<?php
require_once __DIR__ . '/../../includes/config.php';
require_post();
$ref = strtoupper(trim((string)($_COOKIE['awe_ref'] ?? '')));
if ($ref === '') respond(['ok'=>true,'bound'=>0]);
$pdo = db();
$affiliate = affiliate_by_ref($pdo, $ref);
if (!$affiliate) respond(['ok'=>true,'bound'=>0]);
$data = json_input();
$addresses = is_array($data['addresses'] ?? null) ? $data['addresses'] : [];
$bound = 0;
$stmt = $pdo->prepare('INSERT IGNORE INTO affiliate_referrals (affiliate_id,wallet_address,chain_family) VALUES (?,?,?)');
foreach ($addresses as $item) {
    if (!is_array($item)) continue;
    $wallet = clean_wallet((string)($item['address'] ?? ''));
    $family = substr(trim((string)($item['family'] ?? 'wallet')), 0, 30);
    if ($wallet === '') continue;
    $stmt->execute([(int)$affiliate['id'],$wallet,$family]);
    $bound += $stmt->rowCount() > 0 ? 1 : 0;
}
respond(['ok'=>true,'bound'=>$bound]);
