<?php
$pageTitleMap = [
    'dashboard' => 'Dashboard',
    'retail' => 'Retail Billing',
    'event' => 'Event Billing',
    'products' => 'Stocks',
    'suppliers' => 'Suppliers',
    'employees' => 'Employees',
    'categories' => 'Categories',
    'customers' => 'Customers',
    'reports' => 'Reports',
    'settings' => 'Settings',
];
$currentPageTitle = $pageTitleMap[$page] ?? ucfirst($page);
$currentAction = $_GET['action'] ?? 'index';
$showReportButton = in_array($page, ['retail', 'event', 'products', 'suppliers', 'employees']) && $currentAction === 'index';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $settings['company_name'] ?? 'Sri Ram Fire Works' ?> - Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --primary-color: #4f46e5; --sidebar-width: 250px; }
        body { background-color: #f4f7ff; color: #1f2937; }
        .sidebar { width: var(--sidebar-width); min-height: 100vh; background: #ffffff; border-right: 1px solid #e5e7eb; position: fixed; left: 0; top: 0; z-index: 100; display: flex; flex-direction: column; }
        .sidebar .brand { padding: 28px 20px 20px; color: #243b7b; border-bottom: 1px solid #eef2ff; }
        .sidebar .brand i { color: var(--primary-color); font-size: 1.8rem; }
        .sidebar .brand small { color: #94a3b8 !important; }
        .sidebar .nav-link { color: #94a3b8; padding: 12px 20px; margin: 2px 12px; border-radius: 10px; border-left: 3px solid transparent; font-weight: 500; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: var(--primary-color); background: #eef2ff; border-left-color: var(--primary-color); }
        .sidebar .nav-link i { width: 25px; }
        .sidebar .logout-link { margin-top: auto; margin-bottom: 20px; color: #ef4444 !important; }
        .sidebar .logout-link:hover { background: #fee2e2; border-left-color: #ef4444; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .top-bar { background: white; padding: 15px 20px; margin: -20px -20px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.06); }
        .card { border: none; box-shadow: 0 2px 10px rgba(15,23,42,0.08); }
        .stat-card { border-left: 4px solid var(--primary-color); }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.warning { border-left-color: #ffc107; }
        @media print {
            .sidebar, .top-bar .btn, .logout-link, .btn, .no-print { display: none !important; }
            .main-content { margin-left: 0; padding: 0; }
            .top-bar { margin: 0 0 20px; box-shadow: none; padding-left: 0; }
            body { background: #fff; }
            .card { box-shadow: none; border: 1px solid #e5e7eb; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="brand d-flex align-items-center">
            <i class="bi bi-stars me-2"></i>
            <div>
                <h6 class="mb-0">Sri Ram Fire Works</h6>
                <small class="text-muted">Billing System</small>
            </div>
        </div>
        <nav class="nav flex-column mt-3 flex-grow-1">
            <a href="?page=dashboard" class="nav-link <?= $page == 'dashboard' ? 'active' : '' ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
            <a href="?page=retail" class="nav-link <?= $page == 'retail' ? 'active' : '' ?>"><i class="bi bi-cart me-2"></i>Retail Billing</a>
            <a href="?page=event" class="nav-link <?= in_array($page, ['event', 'wholesale']) ? 'active' : '' ?>"><i class="bi bi-calendar-event me-2"></i>Event Billing</a>
            <a href="?page=products" class="nav-link <?= $page == 'products' ? 'active' : '' ?>"><i class="bi bi-box-seam me-2"></i>Stocks</a>
            <a href="?page=suppliers" class="nav-link <?= $page == 'suppliers' ? 'active' : '' ?>"><i class="bi bi-clipboard2-check me-2"></i>Suppliers</a>
            <a href="?page=employees" class="nav-link <?= $page == 'employees' ? 'active' : '' ?>"><i class="bi bi-person-workspace me-2"></i>Employees</a>
            <a href="?page=settings" class="nav-link <?= $page == 'settings' ? 'active' : '' ?>"><i class="bi bi-gear me-2"></i>Settings</a>
            <hr class="my-3 mx-3 border-secondary">
            <a href="?logout=1" class="nav-link logout-link"><i class="bi bi-box-arrow-left me-2"></i>Logout</a>
        </nav>
    </div>
    
    <div class="main-content">
        <?php if (!in_array($page, ['retail', 'event', 'products', 'categories', 'customers', 'suppliers', 'employees', 'settings'])): ?>
        <div class="top-bar d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= htmlspecialchars($currentPageTitle) ?></h5>
            <div class="d-flex align-items-center gap-3">
                <?php if ($showReportButton): ?>
                <button class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-download me-2"></i>Download Report</button>
                <?php endif; ?>
                <span class="me-3"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                <span class="badge bg-primary"><?= ucfirst($_SESSION['user_role'] ?? 'admin') ?></span>
            </div>
        </div>
        <?php endif; ?>
