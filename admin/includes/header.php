<?php
// admin/includes/header.php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

// Ensure session exists
if (!isset($_SESSION['user_id'])) {
  $inNestedDir = basename(dirname($_SERVER['PHP_SELF'])) === 'invoice';
  header('Location: ' . ($inNestedDir ? '../../index.php' : '../index.php'));
  exit;
}

// Fallback helper declarations if not loaded in config/db.php
if (!function_exists('h')) {
  function h($str)
  {
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('setting')) {
  function setting($pdo, $key, $default = '')
  {
    if (!$pdo)
      return $default;
    try {
      $stmt = $pdo->prepare("SELECT setting_value FROM system_settings WHERE setting_key = ? LIMIT 1");
      $stmt->execute([$key]);
      $val = $stmt->fetchColumn();
      return ($val !== false && $val !== null) ? $val : $default;
    } catch (Exception $e) {
      return $default;
    }
  }
}

$companyName = isset($pdo) ? setting($pdo, 'company_name', 'AccountsBase') : 'AccountsBase';
$themeColor = isset($pdo) ? setting($pdo, 'theme_color', '#2563eb') : '#2563eb';
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="<?= h($themeColor) ?>">
  <title><?= h($companyName) ?> · Accounting</title>
  <script src="https://cdn.tailwindcss.com"></script>
  <style>
    :root {
      --brand:
        <?= h($themeColor) ?>
      ;
    }

    .brand-bg {
      background-color: var(--brand);
    }

    .brand-text {
      color: var(--brand);
    }

    .nav-active {
      background-color: var(--brand);
      color: #ffffff;
    }

    .app-scroll {
      scrollbar-width: thin;
    }

    .money {
      font-variant-numeric: tabular-nums;
    }

    @media (max-width: 1023px) {
      #sidebar {
        position: fixed;
        z-index: 40;
        transform: translateX(-100%);
        transition: transform 0.2s ease-in-out;
      }

      #sidebar.open {
        transform: translateX(0);
      }
    }
  </style>
</head>

<body class="bg-slate-100 font-sans flex min-h-screen overflow-hidden text-slate-800">
  <div id="sidebarOverlay" class="fixed inset-0 bg-slate-950/50 z-30 hidden lg:hidden"></div>