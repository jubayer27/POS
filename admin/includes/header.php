<?php
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['user_id'])) {
    $inInvoice = basename(dirname($_SERVER['PHP_SELF'])) === 'invoice';
    header('Location: ' . ($inInvoice ? '../../index.php' : '../index.php'));
    exit;
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
    :root{--brand:<?= h($themeColor) ?>} .brand-bg{background:var(--brand)} .brand-text{color:var(--brand)}
    .nav-active{background:var(--brand);color:white}.app-scroll{scrollbar-width:thin}.money{font-variant-numeric:tabular-nums}
    @media(max-width:1023px){#sidebar{position:fixed;z-index:40;transform:translateX(-100%)}#sidebar.open{transform:translateX(0)}}
  </style>
</head>
<body class="bg-slate-100 font-sans flex min-h-screen overflow-hidden text-slate-800">
<div id="sidebarOverlay" class="fixed inset-0 bg-slate-950/50 z-30 hidden lg:hidden"></div>
