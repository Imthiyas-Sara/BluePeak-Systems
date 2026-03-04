<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $settings['company_name'] ?? 'Sri Ram Fire Works' ?> - Billing System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        :root { --primary-color: #ff6b35; --sidebar-width: 250px; }
        body { background-color: #f8f9fa; }
        .sidebar { width: var(--sidebar-width); min-height: 100vh; background: linear-gradient(180deg, #2c3e50, #1a252f); position: fixed; left: 0; top: 0; z-index: 100; }
        .sidebar .brand { padding: 20px; color: white; border-bottom: 1px solid rgba(255,255,255,0.1); }
        .sidebar .brand i { color: var(--primary-color); font-size: 2rem; }
        .sidebar .nav-link { color: rgba(255,255,255,0.7); padding: 12px 20px; border-left: 3px solid transparent; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: white; background: rgba(255,255,255,0.1); border-left-color: var(--primary-color); }
        .sidebar .nav-link i { width: 25px; }
        .main-content { margin-left: var(--sidebar-width); padding: 20px; }
        .top-bar { background: white; padding: 15px 20px; margin: -20px -20px 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .card { border: none; box-shadow: 0 2px 10px rgba(0,0,0,0.08); }
        .stat-card { border-left: 4px solid var(--primary-color); }
        .stat-card.success { border-left-color: #28a745; }
        .stat-card.info { border-left-color: #17a2b8; }
        .stat-card.warning { border-left-color: #ffc107; }
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
        <nav class="nav flex-column mt-3">
            <a href="?page=dashboard" class="nav-link <?= $page == 'dashboard' ? 'active' : '' ?>"><i class="bi bi-speedometer2 me-2"></i>Dashboard</a>
            <a href="?page=retail" class="nav-link <?= $page == 'retail' ? 'active' : '' ?>"><i class="bi bi-cart me-2"></i>Retail Billing</a>
            <a href="?page=wholesale" class="nav-link <?= $page == 'wholesale' ? 'active' : '' ?>"><i class="bi bi-truck me-2"></i>Wholesale Billing</a>
            <a href="?page=products" class="nav-link <?= $page == 'products' ? 'active' : '' ?>"><i class="bi bi-box-seam me-2"></i>Products</a>
            <a href="?page=categories" class="nav-link <?= $page == 'categories' ? 'active' : '' ?>"><i class="bi bi-tags me-2"></i>Categories</a>
            <a href="?page=customers" class="nav-link <?= $page == 'customers' ? 'active' : '' ?>"><i class="bi bi-people me-2"></i>Customers</a>
            <a href="?page=reports" class="nav-link <?= $page == 'reports' ? 'active' : '' ?>"><i class="bi bi-graph-up me-2"></i>Reports</a>
            <a href="?page=settings" class="nav-link <?= $page == 'settings' ? 'active' : '' ?>"><i class="bi bi-gear me-2"></i>Settings</a>
            <hr class="my-3 mx-3 border-secondary">
            <a href="?logout=1" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i>Logout</a>
        </nav>
    </div>
    
    <div class="main-content">
        <div class="top-bar d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><?= ucfirst($page) ?></h5>
            <div class="d-flex align-items-center">
                <span class="me-3"><i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></span>
                <span class="badge bg-primary"><?= ucfirst($_SESSION['user_role'] ?? 'admin') ?></span>
            </div>
        </div>
