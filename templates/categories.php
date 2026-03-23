<?php
$action = $_GET['action'] ?? 'index';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'store') {
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        
        if ($name) {
            $stmt = $pdo->prepare("INSERT INTO categories (name, description) VALUES (?, ?)");
            $stmt->execute([$name, $description]);
            header("Location: ?page=categories&success=1");
            exit;
        }
    } elseif ($action === 'update') {
        $id = intval($_GET['id'] ?? 0);
        $name = trim($_POST['name']);
        $description = trim($_POST['description'] ?? '');
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        if ($name) {
            $stmt = $pdo->prepare("UPDATE categories SET name = ?, description = ?, is_active = ? WHERE id = ?");
            $stmt->execute([$name, $description, $is_active, $id]);
            header("Location: ?page=categories&success=2");
            exit;
        }
    } elseif ($action === 'delete') {
        $id = intval($_GET['id'] ?? 0);
        $pdo->prepare("UPDATE products SET category_id = NULL WHERE category_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        header("Location: ?page=categories&success=3");
        exit;
    }
}

include 'header.php';
?>

<?php if (isset($_GET['success'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="bi bi-check-circle me-2"></i>
    <?= $_GET['success'] == 1 ? 'Category added successfully!' : ($_GET['success'] == 2 ? 'Category updated successfully!' : 'Category deleted!') ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if ($action === 'index'): ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div class="d-flex align-items-center gap-2">
        <a href="?page=products" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
        <h4 class="mb-0"><i class="bi bi-tags me-2"></i>Categories</h4>
    </div>
    <button class="btn btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#addCategoryModal"><i class="bi bi-plus-lg me-2"></i>Add Category</button>
</div>

<div class="row">
    <?php
    $categories = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products WHERE category_id = c.id) as product_count FROM categories c ORDER BY c.name")->fetchAll();
    foreach ($categories as $cat):
    ?>
    <div class="col-md-4 col-lg-3 mb-4">
        <div class="card h-100 <?= !$cat['is_active'] ? 'border-secondary' : '' ?>">
            <div class="card-body text-center">
                <div class="display-4 mb-3"><i class="bi bi-folder<?= $cat['is_active'] ? '-fill text-primary' : ' text-secondary' ?>"></i></div>
                <h5 class="card-title"><?= htmlspecialchars($cat['name']) ?></h5>
                <p class="text-muted small"><?= htmlspecialchars($cat['description'] ?: 'No description') ?></p>
                <span class="badge bg-info"><?= $cat['product_count'] ?> Products</span>
                <?php if (!$cat['is_active']): ?><span class="badge bg-secondary ms-1">Inactive</span><?php endif; ?>
            </div>
            <div class="card-footer bg-transparent border-top-0 d-flex justify-content-center gap-2">
                <a href="?page=categories&action=edit&id=<?= $cat['id'] ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
                <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteCategory(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name'], ENT_QUOTES) ?>', <?= $cat['product_count'] ?>)"><i class="bi bi-trash me-1"></i>Delete</button>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    
    <?php if (empty($categories)): ?>
    <div class="col-12">
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-tags display-1 text-muted"></i>
                <p class="mt-3 mb-0 text-muted">No categories found. Add your first category to organize products.</p>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Add Category Modal -->
<div class="modal fade" id="addCategoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="?page=categories&action=store">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Add Category</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Sparklers, Rockets, Ground Chakras">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3" placeholder="Optional description"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Add Category</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display:none"></form>
<script>
function deleteCategory(id, name, count) {
    let msg = 'Delete category "' + name + '"?';
    if (count > 0) msg += '\n\nWARNING: ' + count + ' product(s) in this category will become uncategorized.';
    if (confirm(msg)) {
        document.getElementById('deleteForm').action = '?page=categories&action=delete&id=' + id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php elseif ($action === 'edit'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$category = $pdo->prepare("SELECT * FROM categories WHERE id = ?");
$category->execute([$id]);
$category = $category->fetch();
if (!$category) { echo '<div class="alert alert-danger">Category not found</div>'; include 'footer.php'; exit; }

$productCount = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
$productCount->execute([$id]);
$productCount = $productCount->fetchColumn();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-pencil me-2"></i>Edit Category</h4>
    <a href="?page=categories" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body">
                <form method="POST" action="?page=categories&action=update&id=<?= $category['id'] ?>">
                    <div class="mb-3">
                        <label class="form-label">Category Name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required value="<?= htmlspecialchars($category['name']) ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($category['description']) ?></textarea>
                    </div>
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input type="checkbox" name="is_active" class="form-check-input" id="isActive" <?= $category['is_active'] ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-2"></i>Update Category</button>
                        <a href="?page=categories" class="btn btn-secondary">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header"><i class="bi bi-info-circle me-2"></i>Category Info</div>
            <div class="card-body">
                <p><strong>Products in this category:</strong> <span class="badge bg-info"><?= $productCount ?></span></p>
                <p><strong>Created:</strong> <?= date('d M Y H:i', strtotime($category['created_at'])) ?></p>
                <?php if ($productCount > 0): ?>
                <a href="?page=products" class="btn btn-outline-primary btn-sm"><i class="bi bi-eye me-2"></i>View Products</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'footer.php'; ?>
