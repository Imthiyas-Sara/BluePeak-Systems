<?php
$action = $_GET['action'] ?? 'index';
$currency = $settings['currency_symbol'] ?? 'LKR';
$demoMode = !empty($_SESSION['demo_mode']);

if ($demoMode) {
    if (!isset($_SESSION['mock_products']) || !is_array($_SESSION['mock_products'])) {
        $_SESSION['mock_products'] = [];
    }
    if (!isset($_SESSION['mock_next_product_id'])) {
        $_SESSION['mock_next_product_id'] = 1;
    }
    if (!isset($_SESSION['mock_categories']) || !is_array($_SESSION['mock_categories'])) {
        $_SESSION['mock_categories'] = [
            ['id' => 1, 'name' => 'General']
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $sku = trim($_POST['sku']);
        $name = trim($_POST['name']);
        $category_id = intval($_POST['category_id']);
        $cost_price = floatval($_POST['cost_price']);
        $selling_price = floatval($_POST['selling_price']);
        $wholesale_price = floatval($_POST['wholesale_price'] ?? $selling_price);
        $stock_quantity = intval($_POST['stock_quantity']);
        $min_stock_level = intval($_POST['min_stock_level'] ?? 10);
        $description = trim($_POST['description'] ?? '');
        
        if ($name && $sku && $selling_price > 0) {
            if ($demoMode) {
                $newId = (int) $_SESSION['mock_next_product_id']++;
                $_SESSION['mock_products'][] = [
                    'id' => $newId,
                    'sku' => $sku,
                    'name' => $name,
                    'category_id' => $category_id ?: null,
                    'cost_price' => $cost_price,
                    'selling_price' => $selling_price,
                    'wholesale_price' => $wholesale_price,
                    'stock_quantity' => $stock_quantity,
                    'min_stock_level' => $min_stock_level,
                    'description' => $description,
                    'is_active' => 1,
                    'created_at' => date('Y-m-d H:i:s')
                ];
            } else {
                $stmt = $pdo->prepare("INSERT INTO products (sku, name, category_id, cost_price, selling_price, wholesale_price, stock_quantity, min_stock_level, description) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$sku, $name, $category_id ?: null, $cost_price, $selling_price, $wholesale_price, $stock_quantity, $min_stock_level, $description]);
            }
            header("Location: ?page=products&success=1");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_GET['id'] ?? 0);
        $sku = trim($_POST['sku']);
        $name = trim($_POST['name']);
        $category_id = intval($_POST['category_id']);
        $cost_price = floatval($_POST['cost_price']);
        $selling_price = floatval($_POST['selling_price']);
        $wholesale_price = floatval($_POST['wholesale_price'] ?? $selling_price);
        $stock_quantity = intval($_POST['stock_quantity']);
        $min_stock_level = intval($_POST['min_stock_level'] ?? 10);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($name && $sku && $selling_price > 0) {
            if ($demoMode) {
                foreach ($_SESSION['mock_products'] as &$productRow) {
                    if ((int) $productRow['id'] === $id) {
                        $productRow['sku'] = $sku;
                        $productRow['name'] = $name;
                        $productRow['category_id'] = $category_id ?: null;
                        $productRow['cost_price'] = $cost_price;
                        $productRow['selling_price'] = $selling_price;
                        $productRow['wholesale_price'] = $wholesale_price;
                        $productRow['stock_quantity'] = $stock_quantity;
                        $productRow['min_stock_level'] = $min_stock_level;
                        $productRow['description'] = $description;
                        $productRow['is_active'] = $is_active;
                        break;
                    }
                }
                unset($productRow);
            } else {
                $stmt = $pdo->prepare("UPDATE products SET sku = ?, name = ?, category_id = ?, cost_price = ?, selling_price = ?, wholesale_price = ?, stock_quantity = ?, min_stock_level = ?, description = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$sku, $name, $category_id ?: null, $cost_price, $selling_price, $wholesale_price, $stock_quantity, $min_stock_level, $description, $is_active, $id]);
            }
            header("Location: ?page=products&success=2");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_GET['id'] ?? 0);
        if ($demoMode) {
            $_SESSION['mock_products'] = array_values(array_filter($_SESSION['mock_products'], function ($row) use ($id) {
                return (int) ($row['id'] ?? 0) !== $id;
            }));
        } else {
            $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        }
        header("Location: ?page=products&success=3");
        exit;
    }
}

include 'header.php';
$categories = $demoMode
    ? $_SESSION['mock_categories']
    : $pdo->query("SELECT * FROM categories ORDER BY name")->fetchAll();
$selectedCategory = intval($_GET['category'] ?? 0);
?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?= $_GET['success'] == 1 ? 'Product added successfully!' : ($_GET['success'] == 2 ? 'Product updated successfully!' : 'Product deleted!') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'index'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Stocks</h4>
    <div class="d-flex gap-2">
        <a href="?page=categories" class="btn btn-outline-secondary"><i class="bi bi-tags me-2"></i>Manage Categories</a>
        <div class="dropdown">
            <button class="btn btn-primary dropdown-toggle" type="button" id="stockReportDropdown" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <i class="bi bi-download me-2"></i>Download Report
            </button>
            <div class="dropdown-menu p-3" style="min-width: 320px; max-height: 420px; overflow-y: auto;" aria-labelledby="stockReportDropdown">
                <h6 class="mb-2">Stock Report Types</h6>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="stockReportType" id="stockReportAll" value="all" checked>
                    <label class="form-check-label" for="stockReportAll">All Stock Details</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="stockReportType" id="stockReportLow" value="low">
                    <label class="form-check-label" for="stockReportLow">Low Stock</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="stockReportType" id="stockReportOut" value="out">
                    <label class="form-check-label" for="stockReportOut">Out of Stock</label>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="radio" name="stockReportType" id="stockReportActive" value="active">
                    <label class="form-check-label" for="stockReportActive">Active Stock Items</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="radio" name="stockReportType" id="stockReportInactive" value="inactive">
                    <label class="form-check-label" for="stockReportInactive">Inactive Stock Items</label>
                </div>
                <button type="button" class="btn btn-primary w-100" onclick="downloadStockPdfReport()">
                    <i class="bi bi-file-earmark-pdf me-2"></i>Download PDF
                </button>
            </div>
        </div>
        <a href="?page=products&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Stock Item</a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2">
            <span class="fw-semibold me-2">Categories:</span>
            <a href="?page=products" class="btn btn-sm <?= $selectedCategory === 0 ? 'btn-primary' : 'btn-outline-primary' ?>">All</a>
            <?php foreach ($categories as $category): ?>
            <a href="?page=products&category=<?= $category['id'] ?>" class="btn btn-sm <?= $selectedCategory === (int) $category['id'] ? 'btn-primary' : 'btn-outline-primary' ?>">
                <?= htmlspecialchars($category['name']) ?>
            </a>
            <?php endforeach; ?>
        </div>
        <small class="text-muted d-block mt-2">Categories are available here to make stock browsing and billing faster.</small>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead class="table-dark">
                <tr><th>SKU</th><th>Stock Item</th><th>Category</th><th class="text-end">Cost</th><th class="text-end">Retail</th><th class="text-end">Event</th><th class="text-center">Stock</th><th>Status</th><th class="text-end">Actions</th></tr>
            </thead>
            <tbody>
                <?php
                if ($demoMode) {
                    $categoryMap = [];
                    foreach ($categories as $catRow) {
                        $categoryMap[(int) $catRow['id']] = $catRow['name'];
                    }

                    $products = $_SESSION['mock_products'];
                    if ($selectedCategory > 0) {
                        $products = array_values(array_filter($products, function ($row) use ($selectedCategory) {
                            return (int) ($row['category_id'] ?? 0) === $selectedCategory;
                        }));
                    }

                    foreach ($products as &$productRow) {
                        $categoryId = (int) ($productRow['category_id'] ?? 0);
                        $productRow['category_name'] = $categoryMap[$categoryId] ?? 'Uncategorized';
                    }
                    unset($productRow);
                } else {
                    $productSql = "SELECT p.*, c.name as category_name FROM products p LEFT JOIN categories c ON p.category_id = c.id";
                    if ($selectedCategory > 0) {
                        $stmt = $pdo->prepare($productSql . " WHERE p.category_id = ? ORDER BY p.name");
                        $stmt->execute([$selectedCategory]);
                        $products = $stmt->fetchAll();
                    } else {
                        $products = $pdo->query($productSql . " ORDER BY p.name")->fetchAll();
                    }
                }
                foreach ($products as $p):
                $stockClass = $p['stock_quantity'] <= 0 ? 'bg-danger' : ($p['stock_quantity'] <= $p['min_stock_level'] ? 'bg-warning text-dark' : 'bg-success');
                ?>
                <tr>
                    <td><code><?= htmlspecialchars($p['sku']) ?></code></td>
                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td><?= htmlspecialchars($p['category_name'] ?? 'Uncategorized') ?></td>
                    <td class="text-end text-muted"><?= $currency ?> <?= number_format($p['cost_price'], 2) ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($p['selling_price'], 2) ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($p['wholesale_price'], 2) ?></td>
                    <td class="text-center"><span class="badge <?= $stockClass ?>"><?= $p['stock_quantity'] ?></span></td>
                    <td><span class="badge bg-<?= $p['is_active'] ? 'success' : 'secondary' ?>"><?= $p['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                    <td class="text-end">
                        <a href="?page=products&action=edit&id=<?= $p['id'] ?>" class="btn btn-sm btn-primary"><i class="bi bi-pencil"></i></a>
                        <button type="button" class="btn btn-sm btn-danger" onclick="deleteProduct(<?= $p['id'] ?>, '<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>')"><i class="bi bi-trash"></i></button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($products)): ?><tr><td colspan="9" class="text-center text-muted py-4">No products found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none"></form>
<script src="https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jspdf-autotable@3.8.2/dist/jspdf.plugin.autotable.min.js"></script>
<script>
const stockReportRows = <?= json_encode($products, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
const stockCurrency = <?= json_encode($currency) ?>;

function deleteProduct(id, name) {
    if (confirm('Delete product "' + name + '"?')) {
        document.getElementById('deleteForm').action = '?page=products&action=delete&id=' + id;
        document.getElementById('deleteForm').submit();
    }
}

function selectedStockReportType() {
    const selected = document.querySelector('input[name="stockReportType"]:checked');
    return selected ? selected.value : 'all';
}

function filterStockRows(type) {
    return stockReportRows.filter(function(row) {
        const stock = Number(row.stock_quantity || 0);
        const minLevel = Number(row.min_stock_level || 0);
        const isActive = Number(row.is_active || 0) === 1;

        if (type === 'low') {
            return stock > 0 && stock <= minLevel;
        }
        if (type === 'out') {
            return stock <= 0;
        }
        if (type === 'active') {
            return isActive;
        }
        if (type === 'inactive') {
            return !isActive;
        }
        return true;
    });
}

function reportLabel(type) {
    if (type === 'low') {
        return 'Low Stock';
    }
    if (type === 'out') {
        return 'Out of Stock';
    }
    if (type === 'active') {
        return 'Active Stock Items';
    }
    if (type === 'inactive') {
        return 'Inactive Stock Items';
    }
    return 'All Stock Details';
}

function downloadStockPdfReport() {
    const type = selectedStockReportType();
    const rows = filterStockRows(type);

    if (!rows.length) {
        alert('No records found for the selected report type.');
        return;
    }

    const jsPDFCtor = window.jspdf && window.jspdf.jsPDF;
    if (!jsPDFCtor || typeof window.jspdf.jsPDF !== 'function') {
        alert('PDF library failed to load. Please refresh and try again.');
        return;
    }

    const doc = new jsPDFCtor({ orientation: 'landscape', unit: 'pt', format: 'a4' });
    const reportTypeLabel = reportLabel(type);
    const now = new Date();

    doc.setFontSize(15);
    doc.text('Stock Report - ' + reportTypeLabel, 40, 40);
    doc.setFontSize(10);
    doc.setTextColor(90);
    doc.text('Generated: ' + now.toLocaleString(), 40, 58);

    const body = rows.map(function(row) {
        const stock = Number(row.stock_quantity || 0);
        const minLevel = Number(row.min_stock_level || 0);
        let stockStatus = 'In Stock';

        if (stock <= 0) {
            stockStatus = 'Out of Stock';
        } else if (stock <= minLevel) {
            stockStatus = 'Low Stock';
        }

        return [
            String(row.sku || ''),
            String(row.name || ''),
            String(row.category_name || 'Uncategorized'),
            stockCurrency + ' ' + Number(row.cost_price || 0).toFixed(2),
            stockCurrency + ' ' + Number(row.selling_price || 0).toFixed(2),
            stockCurrency + ' ' + Number(row.wholesale_price || 0).toFixed(2),
            String(stock),
            stockStatus,
            Number(row.is_active || 0) === 1 ? 'Active' : 'Inactive'
        ];
    });

    doc.autoTable({
        startY: 72,
        head: [['SKU', 'Stock Item', 'Category', 'Cost', 'Retail', 'Event', 'Qty', 'Stock Level', 'Status']],
        body: body,
        styles: { fontSize: 9, cellPadding: 6 },
        headStyles: { fillColor: [33, 37, 41] },
        columnStyles: {
            3: { halign: 'right' },
            4: { halign: 'right' },
            5: { halign: 'right' },
            6: { halign: 'center' }
        }
    });

    const fileDate = now.toISOString().slice(0, 10);
    doc.save('stock-report-' + type + '-' + fileDate + '.pdf');
}
</script>

<?php elseif ($action === 'create'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-plus-circle me-2"></i>Add New Stock Item</h4>
    <a href="?page=products" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="?page=products&action=store">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">SKU / Product Code <span class="text-danger">*</span></label>
                    <input type="text" name="sku" class="form-control" required placeholder="e.g. FW-001">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Stock Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required placeholder="e.g. Sparkler 10cm">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" placeholder="Optional description">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Cost Price (<?= $currency ?>)</label>
                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0" value="0">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Selling Price (<?= $currency ?>) <span class="text-danger">*</span></label>
                    <input type="number" name="selling_price" class="form-control" step="0.01" min="0.01" required>
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Event Price (<?= $currency ?>)</label>
                    <input type="number" name="wholesale_price" class="form-control" step="0.01" min="0">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Stock Quantity</label>
                    <input type="number" name="stock_quantity" class="form-control" min="0" value="0">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Minimum Stock Level (Alert)</label>
                    <input type="number" name="min_stock_level" class="form-control" min="0" value="10">
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Save Stock Item</button>
                <a href="?page=products" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($action === 'edit'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
if ($demoMode) {
    $product = null;
    foreach ($_SESSION['mock_products'] as $productRow) {
        if ((int) ($productRow['id'] ?? 0) === $id) {
            $product = $productRow;
            break;
        }
    }
} else {
    $product = $pdo->prepare("SELECT * FROM products WHERE id = ?");
    $product->execute([$id]);
    $product = $product->fetch();
}
if (!$product) { echo '<div class="alert alert-danger">Product not found</div>'; include 'footer.php'; exit; }
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Stock Item</h4>
    <a href="?page=products" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<div class="card">
    <div class="card-body">
        <form method="POST" action="?page=products&action=update&id=<?= $product['id'] ?>">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">SKU / Product Code <span class="text-danger">*</span></label>
                    <input type="text" name="sku" class="form-control" required value="<?= htmlspecialchars($product['sku']) ?>">
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Stock Item Name <span class="text-danger">*</span></label>
                    <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($product['name']) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Category</label>
                    <select name="category_id" class="form-select">
                        <option value="">-- Select Category --</option>
                        <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] == $product['category_id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Description</label>
                    <input type="text" name="description" class="form-control" value="<?= htmlspecialchars($product['description']) ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Cost Price (<?= $currency ?>)</label>
                    <input type="number" name="cost_price" class="form-control" step="0.01" min="0" value="<?= $product['cost_price'] ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Selling Price (<?= $currency ?>) <span class="text-danger">*</span></label>
                    <input type="number" name="selling_price" class="form-control" step="0.01" min="0.01" required value="<?= $product['selling_price'] ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Event Price (<?= $currency ?>)</label>
                    <input type="number" name="wholesale_price" class="form-control" step="0.01" min="0" value="<?= $product['wholesale_price'] ?>">
                </div>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label class="form-label">Stock Quantity</label>
                    <input type="number" name="stock_quantity" class="form-control" min="0" value="<?= $product['stock_quantity'] ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Minimum Stock Level (Alert)</label>
                    <input type="number" name="min_stock_level" class="form-control" min="0" value="<?= $product['min_stock_level'] ?>">
                </div>
                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <div class="form-check form-switch mt-2">
                        <input type="checkbox" name="is_active" class="form-check-input" id="isActive" <?= $product['is_active'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="isActive">Active</label>
                    </div>
                </div>
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Update Stock Item</button>
                <a href="?page=products" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
