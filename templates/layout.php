<?php
declare(strict_types=1);

$documentTitle = (string) ($title ?? 'ระบบจัดการหอพัก');
$pageId = (string) ($page ?? 'public-home');
$csrf = (string) ($csrfToken ?? '');
$appTimezoneName = (string) ($appTimezone ?? 'Asia/Bangkok');
$currentUser = is_array($user ?? null) ? $user : [];
$userRole = (string) ($currentUser['role'] ?? 'guest');
$assetUrl = static function (string $path): string {
    $file = dirname(__DIR__) . '/public' . $path;
    $version = is_file($file) ? (string) filemtime($file) : '1';
    return $path . '?v=' . rawurlencode($version);
};

if (!isset($contentTemplate) || !is_string($contentTemplate) || !is_file($contentTemplate)) {
    throw new RuntimeException('Content template is missing or invalid.');
}
?>
<!doctype html>
<html lang="th">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="referrer" content="same-origin">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <meta name="app-timezone" content="<?= e($appTimezoneName) ?>">
  <title><?= e($documentTitle) ?></title>
  <link rel="stylesheet" href="<?= e($assetUrl('/assets/css/app.css')) ?>">
</head>
<body data-page="<?= e($pageId) ?>" data-user-role="<?= e($userRole) ?>">
  <a class="skip-link" href="#main-content">ข้ามไปยังเนื้อหาหลัก</a>
  <?php require $contentTemplate; ?>

  <div class="toast-region" id="toast-region" role="status" aria-live="polite" aria-atomic="true"></div>
  <noscript>
    <div class="noscript-notice">หน้านี้ต้องใช้ JavaScript เพื่อโหลดข้อมูลและส่งแบบฟอร์มอย่างปลอดภัย</div>
  </noscript>
  <script src="<?= e($assetUrl('/assets/js/vendor/qrcode.min.js')) ?>" defer></script>
  <script src="<?= e($assetUrl('/assets/js/app.js')) ?>" defer></script>
</body>
</html>
