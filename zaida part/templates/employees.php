<?php
include 'header.php';

$employees = [
    ['id' => 112, 'name' => 'Mithlesh Kumar Singh', 'address' => 'Kiribathgoda, Colombo', 'daily_wage' => 2000, 'phone' => '987569326'],
    ['id' => 113, 'name' => 'Suron Maherjan', 'address' => 'Nattandiya, Puttalam', 'daily_wage' => 2000, 'phone' => '987569326'],
    ['id' => 114, 'name' => 'Sandesh Bajracharya', 'address' => 'Borella, Colombo', 'daily_wage' => 2200, 'phone' => '987569326'],
    ['id' => 115, 'name' => 'Subin Sedhai', 'address' => 'Kaduwela, Colombo', 'daily_wage' => 2000, 'phone' => '987569326'],
    ['id' => 116, 'name' => 'Wonjala Joshi', 'address' => 'Maharagama, Colombo', 'daily_wage' => 1950, 'phone' => '987569326'],
    ['id' => 117, 'name' => 'Numa Limbu', 'address' => 'Negombo, Gampaha', 'daily_wage' => 2100, 'phone' => '987569326'],
    ['id' => 118, 'name' => 'Nimesh Sthapit', 'address' => 'Moratuwa, Colombo', 'daily_wage' => 2000, 'phone' => '987569326'],
];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Manage Employees</h4>
    <button class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>Add Employee</button>
</div>

<div class="card">
    <div class="card-body">
        <h5 class="mb-4 text-primary">Employee Details</h5>
        <div class="table-responsive">
            <table class="table align-middle table-hover">
                <thead class="table-light">
                    <tr>
                        <th>UID</th>
                        <th>Name</th>
                        <th>Address</th>
                        <th>Daily Wage</th>
                        <th>Phone Number</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($employees as $employee): ?>
                    <tr>
                        <td><?= $employee['id'] ?></td>
                        <td><?= htmlspecialchars($employee['name']) ?></td>
                        <td><?= htmlspecialchars($employee['address']) ?></td>
                        <td>LKR <?= number_format($employee['daily_wage'], 2) ?></td>
                        <td><?= htmlspecialchars($employee['phone']) ?></td>
                        <td class="text-center text-nowrap">
                            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil-square"></i></button>
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-4">
            <button class="btn btn-primary" onclick="window.print()"><i class="bi bi-download me-2"></i>Download Report</button>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
