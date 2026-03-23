<?php
$action = $_GET['action'] ?? 'index';
$currency = $settings['currency_symbol'] ?? 'LKR';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $customer_id = $_POST['customer_id'] ?: null;
        $items = $_POST['items'] ?? [];
        $discount = floatval($_POST['discount_amount'] ?? 0);
        $paid = 0;
        $payment_method = 'cash';
        
        if (!empty($items)) {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
            }
            $tax = ($subtotal - $discount) * (floatval($settings['tax_percentage'] ?? 0) / 100);
            $total = $subtotal - $discount + $tax;
            $paid = $total;
            $status = 'paid';
            
            $lastBill = $pdo->query("SELECT bill_number FROM bills WHERE type = 'retail' ORDER BY id DESC LIMIT 1")->fetch();
            $nextNum = $lastBill ? intval(substr($lastBill['bill_number'], -6)) + 1 : 1;
            $billNumber = ($settings['invoice_prefix'] ?? 'SRF') . '-R-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);
            
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO bills (bill_number, type, customer_id, user_id, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, payment_method) VALUES (?, 'retail', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$billNumber, $customer_id, $_SESSION['user_id'], $subtotal, $discount, $tax, $total, $paid, $status, $payment_method]);
                $billId = $pdo->lastInsertId();
                
                $itemStmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");
                $stockStmt = $pdo->prepare("UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?");
                
                foreach ($items as $item) {
                    $itemTotal = ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
                    $itemStmt->execute([$billId, $item['product_id'], $item['quantity'], $item['price'], $item['discount'] ?? 0, $itemTotal]);
                    $stockStmt->execute([$item['quantity'], $item['product_id']]);
                }
                
                $pdo->commit();
                header("Location: ?page=retail&action=view&id=$billId&success=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = "Error: " . $e->getMessage();
            }
        }
    } elseif ($action === 'download') {
        // Get filter parameters
        $reportType = isset($_GET['report_type']) && $_GET['report_type'] === 'paid' ? 'paid' : 'all';
        $dateFrom = isset($_GET['date_from']) && $_GET['date_from'] !== '' ? $_GET['date_from'] : null;
        $dateTo = isset($_GET['date_to']) && $_GET['date_to'] !== '' ? $_GET['date_to'] : null;
        $priceFrom = isset($_GET['price_from']) && $_GET['price_from'] !== '' ? floatval($_GET['price_from']) : null;
        $priceTo = isset($_GET['price_to']) && $_GET['price_to'] !== '' ? floatval($_GET['price_to']) : null;
        
        // Build query
        $query = "SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.type = 'retail'";
        $params = [];
        
        // Apply report type filter
        if ($reportType === 'paid') {
            $query .= " AND b.payment_status = 'paid'";
        }
        
        // Apply date filters
        if ($dateFrom) {
            $query .= " AND DATE(b.created_at) >= ?";
            $params[] = $dateFrom;
        }
        if ($dateTo) {
            $query .= " AND DATE(b.created_at) <= ?";
            $params[] = $dateTo;
        }
        
        // Apply price filters
        if ($priceFrom !== null) {
            $query .= " AND b.total_amount >= ?";
            $params[] = $priceFrom;
        }
        if ($priceTo !== null) {
            $query .= " AND b.total_amount <= ?";
            $params[] = $priceTo;
        }
        
        $query .= " ORDER BY b.created_at DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $bills = $stmt->fetchAll();
        
        // Calculate totals
        $totalAmount = 0;
        $totalPaid = 0;
        $totalBalance = 0;
        foreach ($bills as $bill) {
            $totalAmount += floatval($bill['total_amount']);
            $totalPaid += floatval($bill['paid_amount']);
            $totalBalance += floatval($bill['total_amount']) - floatval($bill['paid_amount']);
        }
        
        // Generate HTML for PDF conversion
        ob_start();
        ?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Retail Bills Report</title>
<style>
* { margin: 0; padding: 0; }
body { font-family: Arial, sans-serif; padding: 20px; background: white; }
.header { text-align: center; margin-bottom: 20px; border-bottom: 2px solid #333; padding-bottom: 15px; }
.header h1 { font-size: 24px; margin-bottom: 5px; }
.header p { color: #666; font-size: 12px; }
.company-info { text-align: center; font-size: 11px; color: #666; margin-bottom: 15px; }
.filters { background: #f5f5f5; padding: 10px; margin-bottom: 15px; border-left: 4px solid #4f46e5; font-size: 12px; }
.filters strong { display: block; margin-bottom: 5px; }
table { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
thead { background-color: #4f46e5; color: white; }
th { padding: 10px; text-align: left; font-weight: bold; border: 1px solid #333; }
td { padding: 10px; border: 1px solid #ddd; }
tbody tr:nth-child(even) { background: #f9f9f9; }
.text-end { text-align: right; }
.summary-row { background: #f0f0f0; font-weight: bold; }
.footer { margin-top: 20px; text-align: center; font-size: 11px; color: #999; border-top: 1px solid #ddd; padding-top: 10px; }
@media print { body { padding: 0; } }
</style>
</head>
<body>
<div class="header">
<h1>Retail Bills Report</h1>
<p>Generated on <?= date('d M Y H:i:s') ?></p>
</div>

<div class="company-info">
<?= htmlspecialchars($settings['company_name'] ?? 'Sri Ram Fire Works') ?><br>
<?= htmlspecialchars($settings['company_address'] ?? '') ?><br>
<?= htmlspecialchars($settings['company_phone'] ?? '') ?>
</div>

<div class="filters">
<strong>Report Filters:</strong>
Report Type: <?= $reportType === 'paid' ? 'Paid Bills Only' : 'All Records' ?><br>
<?php if ($dateFrom || $dateTo): ?>
Date Range: <?= $dateFrom ?: 'Any' ?> to <?= $dateTo ?: 'Any' ?><br>
<?php endif; ?>
<?php if ($priceFrom !== null || $priceTo !== null): ?>
Price Range: <?= $settings['currency_symbol'] ?? 'LKR' ?> <?= $priceFrom !== null ? number_format($priceFrom, 2) : '0' ?> to <?= $priceTo !== null ? number_format($priceTo, 2) : 'Unlimited' ?>
<?php endif; ?>
</div>

<table>
<thead>
<tr>
<th>Bill No</th>
<th>Date</th>
<th>Customer</th>
<th class="text-end">Total</th>
<th class="text-end">Paid</th>
<th class="text-end">Balance</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<?php foreach ($bills as $bill): 
    $balance = floatval($bill['total_amount']) - floatval($bill['paid_amount']);
    $status = $bill['payment_status'] == 'paid' ? 'Paid' : ($bill['payment_status'] == 'partial' ? 'Partial' : 'Pending');
?>
<tr>
<td><?= htmlspecialchars($bill['bill_number']) ?></td>
<td><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></td>
<td><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($bill['total_amount'], 2) ?></td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($bill['paid_amount'], 2) ?></td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($balance, 2) ?></td>
<td><?= $status ?></td>
</tr>
<?php endforeach; ?>
</tbody>
<tfoot>
<tr class="summary-row">
<td colspan="3">TOTAL</td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($totalAmount, 2) ?></td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($totalPaid, 2) ?></td>
<td class="text-end"><?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?> <?= number_format($totalBalance, 2) ?></td>
<td></td>
</tr>
</tfoot>
</table>

<div class="footer">
<p>This is a computer-generated report.<br>
<?= htmlspecialchars($settings['company_name'] ?? 'Sri Ram Fire Works') ?></p>
</div>

<script>
window.print();
</script>
</body>
</html>
        <?php
        $html = ob_get_clean();
        
        // Send as HTML with print dialog
        header('Content-Type: text/html; charset=utf-8');
        header('Content-Disposition: inline; filename="retail_bills_' . date('Y-m-d_H-i-s') . '.html"');
        echo $html;
        exit;
    }
}

include 'header.php';
?>

<?php if ($action === 'index'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Retail Bills</h4>
    <div class="d-flex gap-2">
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-2"></i>Download Report
            </button>
            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 360px;">
                <form method="GET" action="?page=retail&action=download" id="reportFilterForm">
                    <input type="hidden" name="page" value="retail">
                    <input type="hidden" name="action" value="download">
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Report Type</label>
                        <div class="border rounded p-2">
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="rt_all" name="report_type" value="all" checked>
                                <label class="form-check-label" for="rt_all">All Records</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" id="rt_paid" name="report_type" value="paid">
                                <label class="form-check-label" for="rt_paid">Paid Bills Only</label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Date Range</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="date" name="date_from" class="form-control form-control-sm" placeholder="From Date">
                            </div>
                            <div class="col-6">
                                <input type="date" name="date_to" class="form-control form-control-sm" placeholder="To Date">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label mb-2">Price Range (<?= htmlspecialchars($settings['currency_symbol'] ?? 'LKR') ?>)</label>
                        <div class="row g-2">
                            <div class="col-6">
                                <input type="number" name="price_from" class="form-control form-control-sm" placeholder="Min Price" step="0.01" min="0">
                            </div>
                            <div class="col-6">
                                <input type="number" name="price_to" class="form-control form-control-sm" placeholder="Max Price" step="0.01" min="0">
                            </div>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-file-earmark-pdf me-2"></i>Download PDF</button>
                </form>
            </div>
        </div>
        <a href="?page=retail&action=create" class="btn btn-primary btn-lg"><i class="bi bi-plus-lg me-2"></i>New Retail Bill</a>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead><tr><th>Bill No</th><th>Date</th><th>Customer</th><th class="text-end">Total</th><th class="text-end">Paid</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php
                $bills = $pdo->query("SELECT b.*, c.name as customer_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.type = 'retail' ORDER BY b.created_at DESC LIMIT 50")->fetchAll();
                foreach ($bills as $bill):
                ?>
                <tr>
                    <td><strong><?= htmlspecialchars($bill['bill_number']) ?></strong></td>
                    <td><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></td>
                    <td><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></td>
                    <td><span class="badge bg-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($bill['payment_status']) ?></span></td>
                    <td class="text-end">
                        <a href="?page=retail&action=view&id=<?= $bill['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                        <a href="?page=retail&action=edit&id=<?= $bill['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="?page=retail&action=print&id=<?= $bill['id'] ?>" class="btn btn-sm btn-secondary" target="_blank"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?><tr><td colspan="7" class="text-center text-muted py-4">No bills found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($action === 'create'): ?>
<?php
$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 AND stock_quantity > 0 ORDER BY name")->fetchAll();
$customers = $pdo->query("SELECT * FROM customers WHERE is_active = 1 ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">New Retail Bill</h4>
    <a href="?page=retail" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<form method="POST" action="?page=retail&action=store" id="billForm">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-grid me-2"></i>Select Category First</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label">Category</label>
                            <select class="form-select" id="categorySelect">
                                <option value="">-- Select Category --</option>
                                <?php foreach ($categories as $category): ?>
                                <option value="<?= $category['id'] ?>"><?= htmlspecialchars($category['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">Product</label>
                            <select class="form-select" id="productSelect" disabled>
                                <option value="">-- Select a category first --</option>
                                <?php foreach ($products as $p): ?>
                                <option value="<?= $p['id'] ?>" data-id="<?= $p['id'] ?>" data-category="<?= $p['category_id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-price="<?= $p['selling_price'] ?>" data-stock="<?= $p['stock_quantity'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (<?= $p['sku'] ?>) - Stock: <?= $p['stock_quantity'] ?> - <?= $currency ?> <?= number_format($p['selling_price'], 2) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-list-ul me-2"></i>Bill Items</div>
                <div class="card-body p-0">
                    <table class="table table-bordered mb-0" id="itemsTable">
                        <thead class="table-dark"><tr><th>#</th><th>Product</th><th style="width:80px">Stock</th><th style="width:80px">Qty</th><th style="width:100px">Price</th><th style="width:80px">Disc.</th><th style="width:100px">Total</th><th style="width:50px"></th></tr></thead>
                        <tbody id="itemsBody"><tr id="noItemsRow"><td colspan="8" class="text-center text-muted py-4">No items added</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-calculator me-2"></i>Bill Summary</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Customer (Optional)</label>
                        <select name="customer_id" class="form-select">
                            <option value="">Walk-in Customer</option>
                            <?php foreach ($customers as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> - <?= htmlspecialchars($c['phone']) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span id="subtotal"><?= $currency ?> 0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 align-items-center">
                        <span>Discount:</span>
                        <div class="input-group" style="width:120px"><span class="input-group-text"><?= $currency ?></span><input type="number" name="discount_amount" id="discountAmount" class="form-control form-control-sm" value="0" min="0" step="0.01"></div>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span>Tax (<?= $settings['tax_percentage'] ?? 0 ?>%):</span><span id="taxAmount"><?= $currency ?> 0.00</span></div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary" id="grandTotal"><?= $currency ?> 0.00</strong></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Payment</div>
                <div class="card-body">
                    <div class="alert alert-light border mb-0">
                        <strong>Cash Only</strong><br>
                        This bill will be saved as fully paid in cash.
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="saveBillBtn" disabled><i class="bi bi-check-lg me-2"></i>Save Bill</button>
            </div>
        </div>
    </div>
</form>

<script>
const currency = '<?= $currency ?>';
const taxRate = <?= $settings['tax_percentage'] ?? 0 ?>;
let items = [];
let itemIndex = 0;

const categorySelect = document.getElementById('categorySelect');
const productSelect = document.getElementById('productSelect');
const productOptions = Array.from(productSelect.querySelectorAll('option[data-id]')).map(option => ({
    id: option.value,
    category: option.dataset.category || '',
    name: option.dataset.name,
    sku: option.dataset.sku,
    price: parseFloat(option.dataset.price),
    stock: parseInt(option.dataset.stock, 10),
    label: option.textContent.trim()
}));

categorySelect.addEventListener('change', function () {
    const categoryId = this.value;
    productSelect.innerHTML = '';

    const placeholder = document.createElement('option');
    placeholder.value = '';
    placeholder.textContent = categoryId ? '-- Select Product --' : '-- Select a category first --';
    productSelect.appendChild(placeholder);
    productSelect.disabled = !categoryId;

    if (!categoryId) {
        return;
    }

    productOptions
        .filter(product => product.category === categoryId)
        .forEach(product => {
            const option = document.createElement('option');
            option.value = product.id;
            option.dataset.name = product.name;
            option.dataset.sku = product.sku;
            option.dataset.price = product.price;
            option.dataset.stock = product.stock;
            option.textContent = product.label;
            productSelect.appendChild(option);
        });
});

productSelect.addEventListener('change', function() {
    if (this.value) {
        const opt = this.selectedOptions[0];
        addItem({ id: this.value, name: opt.dataset.name, sku: opt.dataset.sku, price: parseFloat(opt.dataset.price), stock: parseInt(opt.dataset.stock, 10) });
        this.value = '';
    }
});

function addItem(product) {
    const existing = items.findIndex(i => i.product_id == product.id);
    if (existing >= 0) {
        const row = document.querySelector(`tr[data-index="${existing}"]`);
        const qtyInput = row.querySelector('.qty-input');
        if (parseInt(qtyInput.value) < product.stock) { qtyInput.value = parseInt(qtyInput.value) + 1; items[existing].quantity++; updateRowTotal(existing); }
        return;
    }

    document.getElementById('noItemsRow').style.display = 'none';
    const item = { index: itemIndex, product_id: product.id, name: product.name, sku: product.sku, price: product.price, stock: product.stock, quantity: 1, discount: 0 };
    items.push(item);

    const row = document.createElement('tr');
    row.dataset.index = itemIndex;
    row.innerHTML = `<td>${itemIndex + 1}</td><td><strong>${product.name}</strong><br><small class="text-muted">${product.sku}</small><input type="hidden" name="items[${itemIndex}][product_id]" value="${product.id}"></td><td><span class="badge bg-secondary">${product.stock}</span></td><td><input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm qty-input" value="1" min="1" max="${product.stock}" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][price]" class="form-control form-control-sm price-input" value="${product.price.toFixed(2)}" min="0" step="0.01" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][discount]" class="form-control form-control-sm discount-input" value="0" min="0" step="0.01" data-index="${itemIndex}"></td><td class="row-total">${currency} ${product.price.toFixed(2)}</td><td><button type="button" class="btn btn-sm btn-danger remove-item" data-index="${itemIndex}"><i class="bi bi-trash"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(row);
    itemIndex++;
    updateTotals();
}

document.getElementById('itemsBody').addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('price-input') || e.target.classList.contains('discount-input')) {
        updateRowTotal(parseInt(e.target.dataset.index));
    }
});

document.getElementById('itemsBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        const index = parseInt(e.target.closest('.remove-item').dataset.index);
        document.querySelector(`tr[data-index="${index}"]`).remove();
        items = items.filter(i => i.index !== index);
        if (items.length === 0) document.getElementById('noItemsRow').style.display = '';
        updateTotals();
    }
});

function updateRowTotal(index) {
    const row = document.querySelector(`tr[data-index="${index}"]`);
    const qty = parseInt(row.querySelector('.qty-input').value) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
    row.querySelector('.row-total').textContent = `${currency} ${((qty * price) - discount).toFixed(2)}`;
    const idx = items.findIndex(i => i.index === index);
    if (idx >= 0) { items[idx].quantity = qty; items[idx].price = price; items[idx].discount = discount; }
    updateTotals();
}

function updateTotals() {
    let subtotal = 0;
    items.forEach(item => { subtotal += (item.quantity * item.price) - item.discount; });
    const discount = parseFloat(document.getElementById('discountAmount').value) || 0;
    const tax = ((subtotal - discount) * taxRate) / 100;
    const grandTotal = subtotal - discount + tax;
    
    document.getElementById('subtotal').textContent = `${currency} ${subtotal.toFixed(2)}`;
    document.getElementById('taxAmount').textContent = `${currency} ${tax.toFixed(2)}`;
    document.getElementById('grandTotal').textContent = `${currency} ${grandTotal.toFixed(2)}`;
    document.getElementById('saveBillBtn').disabled = items.length === 0;
}

document.getElementById('discountAmount').addEventListener('input', updateTotals);
</script>

<?php elseif ($action === 'view'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.*, c.name as customer_name, c.phone as customer_phone, u.name as user_name FROM bills b LEFT JOIN customers c ON b.customer_id = c.id LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo '<div class="alert alert-danger">Bill not found</div>'; include 'footer.php'; exit; }

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
?>

<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Bill created successfully!</div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Bill - <?= htmlspecialchars($bill['bill_number']) ?></h4>
    <div>
        <a href="?page=retail&action=print&id=<?= $bill['id'] ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-2"></i>Print</a>
        <a href="?page=retail" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
    </div>
</div>

<div class="row">
    <div class="col-lg-8">
        <div class="card mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between">
                <span><i class="bi bi-receipt me-2"></i>Bill Information</span>
                <span class="badge bg-light text-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?> fs-6"><?= ucfirst($bill['payment_status']) ?></span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3"><label class="text-muted small">Bill Number</label><p class="fw-bold"><?= htmlspecialchars($bill['bill_number']) ?></p></div>
                    <div class="col-md-3"><label class="text-muted small">Type</label><p><span class="badge bg-primary"><?= ucfirst($bill['type']) ?></span></p></div>
                    <div class="col-md-3"><label class="text-muted small">Date</label><p><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></p></div>
                    <div class="col-md-3"><label class="text-muted small">Customer</label><p><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in') ?></p></div>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><i class="bi bi-list-ul me-2"></i>Bill Items</div>
            <div class="card-body p-0">
                <table class="table table-striped mb-0">
                    <thead class="table-dark"><tr><th>#</th><th>Product</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Discount</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                        <?php foreach ($items as $i => $item): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><strong><?= htmlspecialchars($item['product_name']) ?></strong><br><small class="text-muted"><?= htmlspecialchars($item['sku']) ?></small></td>
                            <td class="text-center"><?= $item['quantity'] ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['unit_price'], 2) ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['discount'], 2) ?></td>
                            <td class="text-end"><?= $currency ?> <?= number_format($item['total'], 2) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-primary text-white"><i class="bi bi-calculator me-2"></i>Bill Summary</div>
            <div class="card-body">
                <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($bill['subtotal'], 2) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span>Discount:</span><span class="text-danger">- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span>Tax:</span><span><?= $currency ?> <?= number_format($bill['tax_amount'], 2) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></strong></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><span>Paid:</span><span class="text-success"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between"><strong>Balance:</strong><strong class="<?= ($bill['total_amount'] - $bill['paid_amount']) > 0 ? 'text-danger' : 'text-success' ?>"><?= $currency ?> <?= number_format($bill['total_amount'] - $bill['paid_amount'], 2) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'print'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.*, c.name as customer_name, c.phone as customer_phone FROM bills b LEFT JOIN customers c ON b.customer_id = c.id WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo 'Bill not found'; exit; }

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Invoice - <?= htmlspecialchars($bill['bill_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; padding: 20px; }
        .invoice { max-width: 800px; margin: 0 auto; }
        .header { text-align: center; border-bottom: 2px solid #333; padding-bottom: 15px; margin-bottom: 20px; }
        .company-name { font-size: 24px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th { background: #333; color: white; padding: 10px; text-align: left; }
        td { padding: 10px; border-bottom: 1px solid #ddd; }
        .totals { float: right; width: 300px; }
        .totals-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #eee; }
        .grand-total { font-size: 16px; font-weight: bold; border-top: 2px solid #333; }
        .footer { clear: both; margin-top: 40px; text-align: center; font-size: 10px; color: #666; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
    <div class="invoice">
        <div class="header">
            <div class="company-name"><?= htmlspecialchars($settings['company_name'] ?? 'Sri Ram Fire Works') ?></div>
            <div><?= htmlspecialchars($settings['company_address'] ?? '') ?></div>
        </div>
        
        <div style="display:flex; justify-content:space-between; margin-bottom:20px">
            <div><strong>Bill To:</strong><br><?= htmlspecialchars($bill['customer_name'] ?? 'Walk-in Customer') ?></div>
            <div style="text-align:right"><strong>Invoice:</strong> <?= htmlspecialchars($bill['bill_number']) ?><br><strong>Date:</strong> <?= date('d M Y', strtotime($bill['created_at'])) ?></div>
        </div>

        <table>
            <thead><tr><th>#</th><th>Product</th><th>Qty</th><th>Price</th><th>Discount</th><th>Total</th></tr></thead>
            <tbody>
                <?php foreach ($items as $i => $item): ?>
                <tr><td><?= $i + 1 ?></td><td><?= htmlspecialchars($item['product_name']) ?></td><td><?= $item['quantity'] ?></td><td><?= $currency ?> <?= number_format($item['unit_price'], 2) ?></td><td><?= $currency ?> <?= number_format($item['discount'], 2) ?></td><td><?= $currency ?> <?= number_format($item['total'], 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <div class="totals">
            <div class="totals-row"><span>Subtotal:</span><span><?= $currency ?> <?= number_format($bill['subtotal'], 2) ?></span></div>
            <div class="totals-row"><span>Discount:</span><span>- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Tax:</span><span><?= $currency ?> <?= number_format($bill['tax_amount'], 2) ?></span></div>
            <div class="totals-row grand-total"><span>Grand Total:</span><span><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Paid:</span><span><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Balance:</span><span><?= $currency ?> <?= number_format($bill['total_amount'] - $bill['paid_amount'], 2) ?></span></div>
        </div>

        <div class="footer"><p>Thank you for your business!</p></div>
        <div class="no-print" style="text-align:center; margin-top:20px"><button onclick="window.print()" style="padding:10px 30px; background:#333; color:white; border:none; border-radius:5px; cursor:pointer">Print Invoice</button></div>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php include 'footer.php'; ?>
