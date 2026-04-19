<?php
$currency = $settings['currency_symbol'] ?? 'LKR';

$todayBills = ['retail_count' => 0, 'event_count' => 0, 'retail_total' => 0, 'event_total' => 0, 'count' => 0, 'total' => 0];
$monthBills = ['retail_count' => 0, 'event_count' => 0, 'retail_total' => 0, 'event_total' => 0, 'count' => 0, 'total' => 0];
$totalProducts = 0;
$lowStock = 0;
$recentBills = [];
$lowStockItems = [];

if ($pdo) {
    try {
        // Get today's stats
        $today = date('Y-m-d');
        $todayBillsStmt = $pdo->query("SELECT 
            SUM(CASE WHEN type = 'retail' THEN 1 ELSE 0 END) as retail_count,
            SUM(CASE WHEN type IN ('wholesale', 'event') THEN 1 ELSE 0 END) as event_count,
            COALESCE(SUM(CASE WHEN type = 'retail' THEN total_amount ELSE 0 END), 0) as retail_total,
            COALESCE(SUM(CASE WHEN type IN ('wholesale', 'event') THEN total_amount ELSE 0 END), 0) as event_total,
            SUM(CASE WHEN type IN ('retail', 'wholesale', 'event') THEN 1 ELSE 0 END) as count,
            COALESCE(SUM(CASE WHEN type IN ('retail', 'wholesale', 'event') THEN total_amount ELSE 0 END), 0) as total
            FROM bills 
            WHERE DATE(created_at) = '$today'");
        if ($todayBillsStmt) {
            $todayBills = $todayBillsStmt->fetch(PDO::FETCH_ASSOC) ?: $todayBills;
        }

        $monthBillsStmt = $pdo->query("SELECT 
            SUM(CASE WHEN type = 'retail' THEN 1 ELSE 0 END) as retail_count,
            SUM(CASE WHEN type IN ('wholesale', 'event') THEN 1 ELSE 0 END) as event_count,
            COALESCE(SUM(CASE WHEN type = 'retail' THEN total_amount ELSE 0 END), 0) as retail_total,
            COALESCE(SUM(CASE WHEN type IN ('wholesale', 'event') THEN total_amount ELSE 0 END), 0) as event_total,
            SUM(CASE WHEN type IN ('retail', 'wholesale', 'event') THEN 1 ELSE 0 END) as count,
            COALESCE(SUM(CASE WHEN type IN ('retail', 'wholesale', 'event') THEN total_amount ELSE 0 END), 0) as total
            FROM bills 
            WHERE MONTH(created_at) = MONTH(CURRENT_DATE()) AND YEAR(created_at) = YEAR(CURRENT_DATE())");
        if ($monthBillsStmt) {
            $monthBills = $monthBillsStmt->fetch(PDO::FETCH_ASSOC) ?: $monthBills;
        }

        $totalProducts = (int) ($pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1")->fetchColumn() ?: 0);
        $lowStock = (int) ($pdo->query("SELECT COUNT(*) FROM products WHERE stock_quantity <= min_stock_level AND is_active = 1")->fetchColumn() ?: 0);

        // Recent bills
        $recentBillsStmt = $pdo->query("SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id ORDER BY b.created_at DESC LIMIT 5");
        if ($recentBillsStmt) {
            $recentBills = $recentBillsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        $lowStockItemsStmt = $pdo->query("SELECT sku, name, stock_quantity, min_stock_level FROM products WHERE is_active = 1 AND stock_quantity <= min_stock_level ORDER BY stock_quantity ASC, name ASC LIMIT 100");
        if ($lowStockItemsStmt) {
            $lowStockItems = $lowStockItemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
    } catch (PDOException $e) {
        $_SESSION['demo_mode'] = true;
        $pdo = null;
    }
}

include 'header.php';
?>

<div class="row mb-4">
    <div class="col-md-3">
        <div class="card stat-card">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-calendar-day me-2"></i>Today's Sales</h6>
                <h3 class="mb-0"><?= $currency ?> <?= number_format((float) ($todayBills['total'] ?? 0), 2) ?></h3>
                <small class="text-muted"><?= (int) ($todayBills['count'] ?? 0) ?> bills</small>
                <div><small class="text-muted">Retail <?= (int) ($todayBills['retail_count'] ?? 0) ?> + Event <?= (int) ($todayBills['event_count'] ?? 0) ?></small></div>
                <div class="mt-2 d-flex gap-2">
                    <a href="?page=retail&period=today" class="btn btn-sm btn-outline-primary">Retail</a>
                    <a href="?page=event&period=today" class="btn btn-sm btn-outline-success">Events</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card success">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-calendar-month me-2"></i>Monthly Sales</h6>
                <h3 class="mb-0"><?= $currency ?> <?= number_format((float) ($monthBills['total'] ?? 0), 2) ?></h3>
                <small class="text-muted"><?= (int) ($monthBills['count'] ?? 0) ?> bills</small>
                <div><small class="text-muted">Retail <?= (int) ($monthBills['retail_count'] ?? 0) ?> + Event <?= (int) ($monthBills['event_count'] ?? 0) ?></small></div>
                <div class="mt-2 d-flex gap-2">
                    <a href="?page=retail&period=month" class="btn btn-sm btn-outline-primary">Retail</a>
                    <a href="?page=event&period=month" class="btn btn-sm btn-outline-success">Events</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card info">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-box-seam me-2"></i>Stocks</h6>
                <h3 class="mb-0"><?= $totalProducts ?></h3>
                <small class="text-muted">Active items</small>
                <div class="mt-2">
                    <a href="?page=products&stock_filter=active" class="btn btn-sm btn-outline-info">View More</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card stat-card warning">
            <div class="card-body">
                <h6 class="text-muted"><i class="bi bi-exclamation-triangle me-2"></i>Low Stock</h6>
                <h3 class="mb-0"><?= $lowStock ?></h3>
                <small class="text-danger">Need restock</small>
                <div class="mt-2">
                    <a href="?page=products&stock_filter=low" class="btn btn-sm btn-outline-warning">View More</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-8">
        <div class="card">
            <div class="card-header">
                <span><i class="bi bi-receipt me-2"></i>Recent Bills</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Bill No</th><th>Customer</th><th>Type</th><th class="text-end">Amount</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentBills as $bill): ?>
                        <?php
                            $billType = $bill['type'] ?? '';
                            $billViewPage = $billType === 'retail' ? 'retail' : 'event';
                            $billViewUrl = '?page=' . $billViewPage . '&action=view&id=' . intval($bill['id'] ?? 0);
                        ?>
                        <tr class="recent-bill-row" style="cursor:pointer" onclick="window.location.href='<?= htmlspecialchars($billViewUrl, ENT_QUOTES) ?>'">
                            <td><strong><?= htmlspecialchars($bill['bill_number']) ?></strong></td>
                            <td><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></td>
                            <td><span class="badge bg-<?= $bill['type'] == 'retail' ? 'primary' : 'success' ?>"><?= $bill['type'] == 'wholesale' ? 'Event' : 'Retail' ?></span></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></td>
                            <td><span class="badge bg-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($bill['payment_status']) ?></span></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($recentBills)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No bills yet</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                <div class="p-3 border-top bg-light-subtle d-flex justify-content-end">
                    <a href="?page=retail#topSellingSummaryCard" class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-bar-chart-line me-2"></i>Top Selling Items Summary
                    </a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card">
            <div class="card-header"><i class="bi bi-lightning me-2"></i>Quick Actions</div>
            <div class="card-body">
                <div class="d-grid gap-2">
                    <a href="?page=retail&action=create" class="btn btn-primary"><i class="bi bi-cart-plus me-2"></i>New Retail Bill</a>
                    <a href="?page=event&action=create" class="btn btn-success"><i class="bi bi-calendar-event me-2"></i>New Event Bill</a>
                    <a href="?page=event#eventCalendarWidget" class="btn btn-outline-success"><i class="bi bi-calendar3 me-2"></i>View Event Calander</a>
                    <a href="?page=products&action=create" class="btn btn-outline-primary"><i class="bi bi-plus-lg me-2"></i>Add Stock Item</a>
                    <button type="button" class="btn btn-outline-secondary" onclick="downloadDashboardPdfReport()"><i class="bi bi-file-earmark-pdf me-2"></i>Generate Dashboard PDF</button>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
const dashboardReportData = {
    companyName: <?= json_encode($settings['company_name'] ?? 'Sri Ram Fire Works') ?>,
    currency: <?= json_encode($currency) ?>,
    todaySales: {
        total: <?= json_encode((float) ($todayBills['total'] ?? 0)) ?>,
        count: <?= json_encode((int) ($todayBills['count'] ?? 0)) ?>
    },
    monthlySales: {
        total: <?= json_encode((float) ($monthBills['total'] ?? 0)) ?>,
        count: <?= json_encode((int) ($monthBills['count'] ?? 0)) ?>
    },
    stocks: {
        activeItems: <?= json_encode((int) $totalProducts) ?>,
        lowStockItems: <?= json_encode((int) $lowStock) ?>
    },
    recentBills: <?= json_encode(array_map(function ($bill) {
        return [
            'bill_number' => $bill['bill_number'] ?? '',
            'created_at' => $bill['created_at'] ?? '',
            'customer_name' => $bill['customer_name'] ?? 'Walk-in',
            'type' => $bill['type'] ?? '',
            'total_amount' => floatval($bill['total_amount'] ?? 0),
            'payment_status' => $bill['payment_status'] ?? 'pending'
        ];
    }, $recentBills), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    lowStockDetails: <?= json_encode(array_map(function ($item) {
        return [
            'sku' => $item['sku'] ?? '',
            'name' => $item['name'] ?? '',
            'stock_quantity' => intval($item['stock_quantity'] ?? 0),
            'min_stock_level' => intval($item['min_stock_level'] ?? 0)
        ];
    }, $lowStockItems), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>,
    generatedAt: <?= json_encode(date('Y-m-d H:i:s')) ?>
};

function downloadDashboardPdfReport() {
    if (!window.jspdf || typeof window.jspdf.jsPDF !== 'function') {
        alert('PDF library failed to load. Please refresh and try again.');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF('p', 'pt', 'a4');

    doc.setFont('helvetica', 'bold');
    doc.setFontSize(16);
    doc.text(`${dashboardReportData.companyName} - Dashboard Report`, 40, 40);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(10);
    doc.text(`Generated: ${dashboardReportData.generatedAt}`, 40, 58);

    const summaryRows = [
        ['Today Sales', `${dashboardReportData.currency} ${dashboardReportData.todaySales.total.toFixed(2)}`, `${dashboardReportData.todaySales.count} bills`],
        ['Monthly Sales', `${dashboardReportData.currency} ${dashboardReportData.monthlySales.total.toFixed(2)}`, `${dashboardReportData.monthlySales.count} bills`],
        ['Active Stock Items', `${dashboardReportData.stocks.activeItems}`, 'items'],
        ['Low Stock Items', `${dashboardReportData.stocks.lowStockItems}`, 'need restock']
    ];

    doc.autoTable({
        startY: 72,
        head: [['Metric', 'Value', 'Details']],
        body: summaryRows,
        theme: 'grid',
        styles: { fontSize: 10 },
        headStyles: { fillColor: [33, 150, 243] }
    });

    const recentBillsRows = dashboardReportData.recentBills.map((bill) => {
        const billType = bill.type === 'wholesale' ? 'Event' : 'Retail';
        const dateText = bill.created_at ? new Date(bill.created_at).toLocaleString() : '-';
        return [
            bill.bill_number || '-',
            dateText,
            bill.customer_name || 'Walk-in',
            billType,
            `${dashboardReportData.currency} ${Number(bill.total_amount || 0).toFixed(2)}`,
            (bill.payment_status || 'pending').toUpperCase()
        ];
    });

    doc.autoTable({
        startY: doc.lastAutoTable.finalY + 18,
        head: [['Recent Bills', 'Date', 'Customer', 'Type', 'Amount', 'Status']],
        body: recentBillsRows.length ? recentBillsRows : [['No recent bills', '-', '-', '-', '-', '-']],
        theme: 'striped',
        styles: { fontSize: 9 },
        headStyles: { fillColor: [76, 175, 80] }
    });

    const lowStockRows = dashboardReportData.lowStockDetails.map((item) => {
        return [
            item.sku || '-',
            item.name || '-',
            String(item.stock_quantity ?? 0),
            String(item.min_stock_level ?? 0),
            String((item.min_stock_level ?? 0) - (item.stock_quantity ?? 0))
        ];
    });

    doc.autoTable({
        startY: doc.lastAutoTable.finalY + 18,
        head: [['Low Stock Details', 'Item Name', 'Current', 'Min Level', 'Shortage']],
        body: lowStockRows.length ? lowStockRows : [['-', 'No low stock items', '-', '-', '-']],
        theme: 'striped',
        styles: { fontSize: 9 },
        headStyles: { fillColor: [255, 152, 0] }
    });

    const stamp = new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
    doc.save(`dashboard-report-${stamp}.pdf`);
}
</script>

<?php include 'footer.php'; ?>
