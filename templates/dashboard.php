<?php
$currency = $settings['currency_symbol'] ?? 'LKR';

// Get today's stats
$today = date('Y-m-d');
$todayBills = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM bills WHERE DATE(created_at) = '$today'")->fetch();
$monthBills = $pdo->query("SELECT COUNT(*) as count, COALESCE(SUM(total_amount), 0) as total FROM bills WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())")->fetch();
$totalProducts = $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn();
$lowStock = $pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_level AND is_active = 1")->fetchColumn();

// Recent bills
$recentBills = $pdo->query("SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id ORDER BY b.created_at DESC LIMIT 5")->fetchAll();

include 'header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-calendar-day me-2"></i>Today's Sales</h6>
                <h3 class="mb-0"><?= $currency ?> <?= number_format($todayBills['total'], 2) ?></h3>
                <small class="text-muted"><?= $todayBills['count'] ?> bills</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-calendar-month me-2"></i>Monthly Sales</h6>
                <h3 class="mb-0"><?= $currency ?> <?= number_format($monthBills['total'], 2) ?></h3>
                <small class="text-muted"><?= $monthBills['count'] ?> bills</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-box-seam me-2"></i>Products</h6>
                <h3 class="mb-0"><?= $totalProducts ?></h3>
                <small class="text-muted">Active products</small>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock</h6>
                <h3 class="mb-0"><?= $lowStock ?></h3>
                <small class="text-danger">Need restock</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-receipt me-2"></i>Recent Bills</span>
                <a href="?page=reports" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Bill No</th><th>Customer</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBills as $bill): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($bill['bill_number']) ?></strong></td>
                            <td><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></td>
                            <td><span class="badge bg-<?= $bill['type'] == 'retail' ? 'primary' : 'success' ?>"><?= ucfirst($bill['type']) ?></span></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></td>
                            <td><span class="badge bg-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($bill['payment_status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentBills)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No bills yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>Quick Actions</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="?page=retail&action=create" class="btn btn-primary"><i class="bi bi-cart-plus me-2"></i>New Retail Bill</a>
                    <a href="?page=wholesale&action=create" class="btn btn-success"><i class="bi bi-truck me-2"></i>New Wholesale Bill</a>
                    <a href="?page=products&action=create" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-2"></i>Add Product</a>
                    <a href="?page=customers&action=create" class="btn btn-outline-secondary"><i class="bi bi-person-plus me-2"></i>Add Customer</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
