<?php
$currency = $settings['currency_symbol'] ?? 'LKR';
$dateFrom = $_GET['from'] ?? date('Y-m-01');
$dateTo = $_GET['to'] ?? date('Y-m-d');
$type = $_GET['type'] ?? 'all';

include 'header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Sales Reports</h4>
</div>

<!-- Filters -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="page" value="reports">
            <div class="col-md-3">
                <label class="form-label">From Date</label>
                <input type="date" name="from" class="form-control" value="<?= $dateFrom ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">To Date</label>
                <input type="date" name="to" class="form-control" value="<?= $dateTo ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Type</label>
                <select name="type" class="form-select">
                    <option value="all" <?= $type == 'all' ? 'selected' : '' ?>>All</option>
                    <option value="retail" <?= $type == 'retail' ? 'selected' : '' ?>>Retail</option>
                    <option value="wholesale" <?= $type == 'wholesale' ? 'selected' : '' ?>>Wholesale</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-filter me-2"></i>Apply Filters</button>
            </div>
        </form>
    </div>
</div>

<?php
// Build query
$where = "WHERE DATE(created_at) BETWEEN :from AND :to";
$params = ['from' => $dateFrom, 'to' => $dateTo];
if ($type !== 'all') {
    $where .= " AND type = :type";
    $params['type'] = $type;
}

// Summary stats
$summaryStmt = $pdo->prepare("SELECT 
    COUNT(*) as total_bills,
    SUM(total_amount) as total_sales,
    SUM(discount_amount) as total_discount,
    SUM(tax_amount) as total_tax,
    SUM(paid_amount) as total_paid,
    SUM(total_amount - paid_amount) as total_due
    FROM bills $where");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

// Daily sales
$dailyStmt = $pdo->prepare("SELECT DATE(created_at) as date, SUM(total_amount) as total, COUNT(*) as count 
    FROM bills $where GROUP BY DATE(created_at) ORDER BY date");
$dailyStmt->execute($params);
$dailySales = $dailyStmt->fetchAll();

// Top products
$topProductsStmt = $pdo->prepare("SELECT p.name, SUM(bi.quantity) as qty, SUM(bi.total) as total
    FROM bill_items bi
    JOIN products p ON bi.product_id = p.id
    JOIN bills b ON bi.bill_id = b.id
    $where
    GROUP BY bi.product_id, p.name
    ORDER BY total DESC LIMIT 10");
$topProductsStmt->execute($params);
$topProducts = $topProductsStmt->fetchAll();

// Payment status breakdown
$statusStmt = $pdo->prepare("SELECT payment_status, COUNT(*) as count, SUM(total_amount) as total 
    FROM bills $where GROUP BY payment_status");
$statusStmt->execute($params);
$statusBreakdown = $statusStmt->fetchAll();
?>

<!-- Summary Cards -->
<div class="row mb-4">
    <div class="col-md-2">
        <div class="card bg-primary text-white">
            <div class="card-body text-center">
                <h4><?= $summary['total_bills'] ?? 0 ?></h4>
                <small>Total Bills</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-success text-white">
            <div class="card-body text-center">
                <h5><?= $currency ?> <?= number_format($summary['total_sales'] ?? 0, 2) ?></h5>
                <small>Total Sales</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-warning text-dark">
            <div class="card-body text-center">
                <h5><?= $currency ?> <?= number_format($summary['total_discount'] ?? 0, 2) ?></h5>
                <small>Discounts</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-info text-white">
            <div class="card-body text-center">
                <h5><?= $currency ?> <?= number_format($summary['total_tax'] ?? 0, 2) ?></h5>
                <small>Tax Collected</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-secondary text-white">
            <div class="card-body text-center">
                <h5><?= $currency ?> <?= number_format($summary['total_paid'] ?? 0, 2) ?></h5>
                <small>Amount Received</small>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card bg-danger text-white">
            <div class="card-body text-center">
                <h5><?= $currency ?> <?= number_format($summary['total_due'] ?? 0, 2) ?></h5>
                <small>Outstanding</small>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Daily Sales Chart -->
    <div class="col-lg-8 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-bar-chart me-2"></i>Daily Sales</div>
            <div class="card-body">
                <canvas id="dailySalesChart" height="200"></canvas>
            </div>
        </div>
    </div>

    <!-- Payment Status -->
    <div class="col-lg-4 mb-4">
        <div class="card h-100">
            <div class="card-header"><i class="bi bi-pie-chart me-2"></i>Payment Status</div>
            <div class="card-body">
                <canvas id="statusChart" height="200"></canvas>
                <div class="mt-3">
                    <?php foreach ($statusBreakdown as $s): ?>
                    <div class="d-flex justify-content-between mb-1">
                        <span class="badge bg-<?= $s['payment_status'] == 'paid' ? 'success' : ($s['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($s['payment_status']) ?></span>
                        <span><?= $s['count'] ?> bills - <?= $currency ?> <?= number_format($s['total'], 2) ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Top Products -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-trophy me-2"></i>Top Selling Products</div>
            <div class="card-body p-0">
                <table class="table mb-0">
                    <thead class="table-dark"><tr><th>#</th><th>Product</th><th class="text-center">Qty Sold</th><th class="text-end">Revenue</th></tr></thead>
                    <tbody>
                        <?php foreach ($topProducts as $i => $p): ?>
                        <tr>
                            <td><span class="badge bg-<?= $i < 3 ? 'warning' : 'secondary' ?>"><?= $i + 1 ?></span></td>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td class="text-center"><?= $p['qty'] ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($p['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($topProducts)): ?><tr><td colspan="4" class="text-center text-muted py-3">No data available</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Daily Breakdown -->
    <div class="col-lg-6 mb-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-calendar3 me-2"></i>Daily Breakdown</div>
            <div class="card-body p-0" style="max-height: 400px; overflow-y: auto;">
                <table class="table mb-0">
                    <thead class="table-dark sticky-top"><tr><th>Date</th><th class="text-center">Bills</th><th class="text-end">Sales</th></tr></thead>
                    <tbody>
                        <?php foreach ($dailySales as $d): ?>
                        <tr>
                            <td><?= date('D, d M Y', strtotime($d['date'])) ?></td>
                            <td class="text-center"><?= $d['count'] ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($d['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($dailySales)): ?><tr><td colspan="3" class="text-center text-muted py-3">No sales in this period</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- Recent Bills -->
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-receipt me-2"></i>Recent Bills</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-striped mb-0">
            <thead class="table-dark">
                <tr><th>Bill No</th><th>Date</th><th>Customer</th><th>Type</th><th class="text-end">Total</th><th class="text-end">Paid</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
                <?php
                $billsStmt = $pdo->prepare("SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id $where ORDER BY b.created_at DESC LIMIT 50");
                $billsStmt->execute($params);
                $bills = $billsStmt->fetchAll();
                foreach ($bills as $b):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($b['bill_number']) ?></strong></td>
                    <td><?= date('d M Y H:i', strtotime($b['created_at'])) ?></td>
                    <td><?= htmlspecialchars($b['customer_name'] ?? 'Walk-in') ?></td>
                    <td><span class="badge bg-<?= $b['type'] == 'wholesale' ? 'success' : 'primary' ?>"><?= ucfirst($b['type']) ?></span></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($b['total_amount'], 2) ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($b['paid_amount'], 2) ?></td>
                    <td><span class="badge bg-<?= $b['payment_status'] == 'paid' ? 'success' : ($b['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($b['payment_status']) ?></span></td>
                    <td><a href="?page=<?= $b['type'] ?>&action=view&id=<?= $b['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a></td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?><tr><td colspan="8" class="text-center text-muted py-4">No bills found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
// Daily Sales Chart
const dailyLabels = <?= json_encode(array_column($dailySales, 'date')) ?>;
const dailyData = <?= json_encode(array_map(fn($d) => floatval($d['total']), $dailySales)) ?>;

new Chart(document.getElementById('dailySalesChart'), {
    type: 'bar',
    data: {
        labels: dailyLabels.map(d => new Date(d).toLocaleDateString('en-US', {month: 'short', day: 'numeric'})),
        datasets: [{
            label: 'Sales (<?= $currency ?>)',
            data: dailyData,
            backgroundColor: 'rgba(54, 162, 235, 0.8)',
            borderColor: 'rgba(54, 162, 235, 1)',
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true } }
    }
});

// Payment Status Chart
const statusLabels = <?= json_encode(array_map(fn($s) => ucfirst($s['payment_status']), $statusBreakdown)) ?>;
const statusData = <?= json_encode(array_map(fn($s) => floatval($s['total']), $statusBreakdown)) ?>;
const statusColors = <?= json_encode(array_map(fn($s) => $s['payment_status'] == 'paid' ? '#198754' : ($s['payment_status'] == 'partial' ? '#ffc107' : '#dc3545'), $statusBreakdown)) ?>;

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: statusLabels,
        datasets: [{
            data: statusData,
            backgroundColor: statusColors
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { position: 'bottom' } }
    }
});
</script>

<?php include 'footer.php'; ?>
