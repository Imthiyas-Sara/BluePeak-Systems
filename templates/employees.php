<?php
// Handle form submissions BEFORE any output
$success = null;
$error = null;

// Flash messages (persist after redirect)
if (isset($_SESSION['flash_success'])) {
    $success = $_SESSION['flash_success'];
    unset($_SESSION['flash_success']);
}
if (isset($_SESSION['flash_error'])) {
    $error = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

$buildMonthlySalaryData = function ($month, $includeInactive = false) use ($pdo) {
    $year = date('Y', strtotime($month . '-01'));
    $month_num = date('m', strtotime($month . '-01'));

    $first_day = strtotime($year . '-' . $month_num . '-01');
    $last_day = strtotime(date('Y-m-t', $first_day));
    $working_days = 0;
    for ($date = $first_day; $date <= $last_day; $date = strtotime('+1 day', $date)) {
        $day_of_week = date('N', $date);
        if ($day_of_week <= 5) {
            $working_days++;
        }
    }

    $stmt = $pdo->prepare("SELECT employee_id, status FROM salary_payments WHERE month = ?");
    $stmt->execute([$month]);
    $paymentStatuses = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $employeeQuery = "SELECT * FROM employees";
    if (!$includeInactive) {
        $employeeQuery .= " WHERE is_active = 1";
    }
    $employeeQuery .= " ORDER BY name ASC";

    $stmt = $pdo->query($employeeQuery);
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $salaryData = [];
    foreach ($employees as $employee) {
        $rawType = strtolower(trim($employee['employee_type'] ?? ''));
        if ($rawType === '') {
            if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                $employee_type = 'monthly_paid';
            } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                $employee_type = 'daily_paid';
            } else {
                $employee_type = 'daily_paid';
            }
        } elseif (strpos($rawType, 'month') !== false) {
            $employee_type = 'monthly_paid';
        } elseif (strpos($rawType, 'day') !== false) {
            $employee_type = 'daily_paid';
        } else {
            $employee_type = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) as present_count FROM attendance WHERE employee_id = ? AND attendance_date LIKE ? AND status = 'present'");
        $stmt->execute([$employee['id'], $year . '-' . $month_num . '-%']);
        $present_days = (int)($stmt->fetch(PDO::FETCH_ASSOC)['present_count'] ?? 0);

        $final_salary = 0;
        $salary_type_label = '';
        if ($employee_type === 'daily_paid') {
            $daily_rate = (float)($employee['daily_wage'] ?? 0);
            $final_salary = $daily_rate * $present_days;
            $salary_type_label = 'Daily Rate: LKR ' . number_format($daily_rate, 2);
        } else {
            $monthly_salary = (float)($employee['monthly_salary'] ?? 0);
            if ($monthly_salary > 0 && $working_days > 0) {
                $per_day_salary = $monthly_salary / $working_days;
                $final_salary = $per_day_salary * $present_days;
            }
            $salary_type_label = 'Monthly Salary: LKR ' . number_format($monthly_salary, 2);
        }

        $salaryData[] = [
            'id' => $employee['id'],
            'uid' => $employee['uid'] ?? 'N/A',
            'name' => $employee['name'] ?? 'N/A',
            'type' => $employee_type,
            'phone' => $employee['phone'] ?? 'N/A',
            'is_active' => (int)($employee['is_active'] ?? 1),
            'created_at' => $employee['created_at'] ?? null,
            'updated_at' => $employee['updated_at'] ?? null,
            'present_days' => $present_days,
            'total_working_days' => $working_days,
            'salary_type_label' => $salary_type_label,
            'final_salary' => number_format($final_salary, 2, '.', ''),
            'payment_status' => $paymentStatuses[$employee['id']] ?? 'pending'
        ];
    }

    return [
        'salaryData' => $salaryData,
        'workingDays' => $working_days,
    ];
};

$requestAction = $_GET['action'] ?? '';
if ($requestAction === 'download_salary_report') {
    $month = $_GET['month'] ?? date('Y-m');
    $format = strtolower($_GET['format'] ?? 'pdf');
    if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
        $month = date('Y-m');
    }

    $reportData = $buildMonthlySalaryData($month, true);
    $salaryData = $reportData['salaryData'];

    if ($format === 'csv') {
        $filename = 'employee-salary-report-' . $month . '.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Employee UID', 'Employee Name', 'Employee Type', 'Calculated Salary', 'Payment Status']);
        foreach ($salaryData as $row) {
            fputcsv($out, [
                $row['uid'],
                $row['name'],
                $row['type'] === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid',
                'LKR ' . number_format((float)$row['final_salary'], 2),
                ucfirst($row['payment_status'])
            ]);
        }
        fclose($out);
        exit;
    }

    $monthStart = $month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));

    $totalEmployees = count($salaryData);
    $newEmployees = 0;
    $employeesLeft = 0;
    $paidEmployees = 0;
    $pendingEmployees = 0;
    $totalSalaryPaid = 0.0;
    $totalSalaryPending = 0.0;

    foreach ($salaryData as $row) {
        if (!empty($row['created_at'])) {
            $created = substr((string)$row['created_at'], 0, 10);
            if ($created >= $monthStart && $created <= $monthEnd) {
                $newEmployees++;
            }
        }

        if ((int)($row['is_active'] ?? 1) === 0 && !empty($row['updated_at'])) {
            $updated = substr((string)$row['updated_at'], 0, 10);
            if ($updated >= $monthStart && $updated <= $monthEnd) {
                $employeesLeft++;
            }
        }

        $salaryAmount = (float)($row['final_salary'] ?? 0);
        if (($row['payment_status'] ?? 'pending') === 'paid') {
            $paidEmployees++;
            $totalSalaryPaid += $salaryAmount;
        } else {
            $pendingEmployees++;
            $totalSalaryPending += $salaryAmount;
        }
    }

    $pdfEscape = function ($text) {
        $text = (string)$text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        return $text;
    };

    $drawText = function ($x, $y, $text, $size = 10, $font = '/F1') use ($pdfEscape) {
        return "BT\n{$font} {$size} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    $lines = [];
    $lines[] = ['text' => 'EMPLOYEE MONTHLY PAYROLL REPORT', 'size' => 14, 'font' => '/F2'];
    $lines[] = ['text' => 'Month: ' . $month . '    Generated: ' . date('Y-m-d H:i:s'), 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => str_repeat('-', 110), 'size' => 8, 'font' => '/F1'];

    $lines[] = ['text' => 'SECTION A - EMPLOYEE DETAILS', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'UID        Name                          Type      Phone         Status', 'size' => 9, 'font' => '/F2'];
    foreach ($salaryData as $row) {
        $uid = str_pad(substr((string)$row['uid'], 0, 10), 10);
        $name = str_pad(substr((string)$row['name'], 0, 28), 30);
        $typeLabel = (($row['type'] ?? 'daily_paid') === 'daily_paid') ? 'Daily' : 'Monthly';
        $type = str_pad($typeLabel, 9);
        $phone = str_pad(substr((string)($row['phone'] ?? 'N/A'), 0, 12), 12);
        $status = ((int)($row['is_active'] ?? 1) === 1) ? 'Active' : 'Inactive';
        $lines[] = ['text' => $uid . '  ' . $name . '  ' . $type . '  ' . $phone . '  ' . $status, 'size' => 8, 'font' => '/F3'];
    }

    $lines[] = ['text' => ' ', 'size' => 8, 'font' => '/F1'];
    $lines[] = ['text' => 'SECTION B - ATTENDANCE & SALARY DETAILS', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'UID        Present/Work   Salary Type                    Final Salary      Payment', 'size' => 9, 'font' => '/F2'];
    foreach ($salaryData as $row) {
        $uid = str_pad(substr((string)$row['uid'], 0, 10), 10);
        $attendance = str_pad((string)$row['present_days'] . '/' . (string)$row['total_working_days'], 13);
        $salaryType = str_pad(substr((string)$row['salary_type_label'], 0, 30), 30);
        $finalSalary = str_pad('LKR ' . number_format((float)$row['final_salary'], 2), 15);
        $payment = ucfirst((string)($row['payment_status'] ?? 'pending'));
        $lines[] = ['text' => $uid . '  ' . $attendance . '  ' . $salaryType . '  ' . $finalSalary . '  ' . $payment, 'size' => 8, 'font' => '/F3'];
    }

    $lines[] = ['text' => ' ', 'size' => 8, 'font' => '/F1'];
    $lines[] = ['text' => 'SECTION C - MONTHLY SUMMARY', 'size' => 11, 'font' => '/F2'];
    $lines[] = ['text' => 'Total Employees             : ' . $totalEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'New Employees Joined        : ' . $newEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Employees Who Left          : ' . $employeesLeft, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Number of Paid Employees    : ' . $paidEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Number of Pending Payments  : ' . $pendingEmployees, 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Total Salary Paid           : LKR ' . number_format($totalSalaryPaid, 2), 'size' => 9, 'font' => '/F1'];
    $lines[] = ['text' => 'Total Salary Pending        : LKR ' . number_format($totalSalaryPending, 2), 'size' => 9, 'font' => '/F1'];

    $linesPerPage = 52;
    $linePages = array_chunk($lines, $linesPerPage);
    if (empty($linePages)) {
        $linePages = [[]];
    }

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';

    $pageRefs = [];
    $nextObj = 6;

    foreach ($linePages as $pageIndex => $pageLines) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageRefs[] = $pageObj . ' 0 R';

        $content = '';
        $y = 805;
        foreach ($pageLines as $line) {
            $content .= $drawText(40, $y, $line['text'], $line['size'], $line['font']);
            $y -= 13;
        }

        $content .= $drawText(40, 30, 'Page ' . ($pageIndex + 1) . ' of ' . count($linePages), 8, '/F1');

        $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
    }

    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
    ksort($objects);

    $maxObj = max(array_keys($objects));
    $pdf = "%PDF-1.4\n";
    $offsets = [];
    for ($i = 1; $i <= $maxObj; $i++) {
        if (!isset($objects[$i])) {
            continue;
        }
        $offsets[$i] = strlen($pdf);
        $pdf .= $i . " 0 obj\n" . $objects[$i] . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n0 " . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $off) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF";

    $filename = 'employee-monthly-payroll-report-' . $month . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename=' . $filename);
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Handle JSON requests
    $contentType = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    if (strpos($contentType, 'application/json') !== false) {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);
        $_POST = array_merge($_POST, $data);
    }
    
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' || $_POST['action'] === 'edit') {
            // Add or Edit Employee
            $uid = $_POST['uid'] ?? '';
            $name = $_POST['name'] ?? '';
            $address = $_POST['address'] ?? '';
            $employee_type = $_POST['employee_type'] ?? 'daily_paid';
            $phone = $_POST['phone'] ?? '';
            $daily_wage = $_POST['daily_wage'] ?? 0;
            $monthly_salary = $_POST['monthly_salary'] ?? 0;
            
            // Normalize and validate type & wage values
            $employee_type = strpos(strtolower($employee_type), 'month') !== false ? 'monthly_paid' : 'daily_paid';
            $daily_wage = is_numeric($daily_wage) ? floatval($daily_wage) : 0;
            $monthly_salary = is_numeric($monthly_salary) ? floatval($monthly_salary) : 0;

            if (!$name || !$phone) {
                $error = "Name and Phone are required fields!";
            } elseif ($employee_type === 'daily_paid' && $daily_wage <= 0) {
                $error = "Please provide a valid Daily Rate / Amount.";
            } elseif ($employee_type === 'monthly_paid' && $monthly_salary <= 0) {
                $error = "Please provide a valid Monthly Salary.";
            } else {
                if ($_POST['action'] === 'add') {
                    // Generate UID if not provided
                    if (!$uid) {
                        $stmt = $pdo->query("SELECT MAX(CAST(SUBSTRING(uid, 5) AS UNSIGNED)) as max_id FROM employees");
                        $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
                        $uid = 'EMP-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
                    }

                    $stmt = $pdo->prepare("INSERT INTO employees (uid, name, address, employee_type, phone, daily_wage, monthly_salary, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->execute([$uid, $name, $address, $employee_type, $phone, $daily_wage, $monthly_salary]);
                    $_SESSION['flash_success'] = "Employee added successfully!";
                } else {
                    // Edit Employee
                    $employee_id = $_POST['employee_id'] ?? '';
                    $stmt = $pdo->prepare("UPDATE employees SET name = ?, address = ?, employee_type = ?, phone = ?, daily_wage = ?, monthly_salary = ? WHERE id = ?");
                    $stmt->execute([$name, $address, $employee_type, $phone, $daily_wage, $monthly_salary, $employee_id]);
                    $_SESSION['flash_success'] = "Employee updated successfully!";
                }

                // Redirect to prevent duplicate submission on page refresh
                header("Location: ?page=employees");
                exit;
            }
        } elseif ($_POST['action'] === 'delete') {
            // Soft Delete Employee - Mark as inactive instead of permanent deletion
            $employee_id = $_POST['employee_id'] ?? '';
            $stmt = $pdo->prepare("UPDATE employees SET is_active = 0 WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            $_SESSION['flash_success'] = "Employee moved to past employees list!";
            header("Location: ?page=employees");
            exit;
        } elseif ($_POST['action'] === 'reactivate') {
            // Reactivate Employee
            $employee_id = $_POST['employee_id'] ?? '';
            $stmt = $pdo->prepare("UPDATE employees SET is_active = 1 WHERE id = ?");
            $stmt->execute([$employee_id]);
            
            $_SESSION['flash_success'] = "Employee reactivated successfully!";
            header("Location: ?page=employees");
            exit;
        } elseif ($_POST['action'] === 'save_attendance') {
            // Save Attendance
            $attendance_data = $_POST['attendance'] ?? [];
            
            if (empty($attendance_data)) {
                echo json_encode(['success' => false, 'message' => 'No attendance data provided']);
                exit;
            }
            
            try {
                $pdo->beginTransaction();
                
                foreach ($attendance_data as $record) {
                    // Validate that employee exists
                    $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
                    $stmt->execute([$record['employee_id']]);
                    if (!$stmt->fetch()) {
                        throw new Exception("Employee ID {$record['employee_id']} does not exist");
                    }
                    
                    $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) 
                                          VALUES (?, ?, ?) 
                                          ON DUPLICATE KEY UPDATE status = VALUES(status)");
                    $stmt->execute([$record['employee_id'], $record['date'], $record['status']]);
                }
                
                $pdo->commit();
                echo json_encode(['success' => true, 'message' => 'Attendance saved successfully']);
            } catch (Exception $e) {
                $pdo->rollBack();
                echo json_encode(['success' => false, 'message' => 'Error saving attendance: ' . $e->getMessage()]);
            }
            exit;
        } elseif ($_POST['action'] === 'generate_report') {
            // Generate Monthly Report (existing report logic)
            $month = $_POST['month'] ?? '';
            
            if (empty($month)) {
                echo json_encode(['success' => false, 'message' => 'Month not specified']);
                exit;
            }
            
            try {
                // Parse month (YYYY-MM)
                $year = date('Y', strtotime($month . '-01'));
                $month_num = date('m', strtotime($month . '-01'));
                
                // Calculate total working days in the month (Mon-Fri)
                $first_day = strtotime($year . '-' . $month_num . '-01');
                $last_day = strtotime(date('Y-m-t', $first_day));
                
                $working_days = 0;
                for ($date = $first_day; $date <= $last_day; $date = strtotime('+1 day', $date)) {
                    $day_of_week = date('N', $date); // 1=Monday, 7=Sunday
                    if ($day_of_week <= 5) { // Monday to Friday
                        $working_days++;
                    }
                }
                
                // Get all employees (active and inactive)
                $stmt = $pdo->query("SELECT * FROM employees ORDER BY name ASC");
                $all_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $report = [];
                
                foreach ($all_employees as $employee) {
                    // Determine correct employee type using the same logic as the UI
                    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                    if ($rawType === '') {
                        // Determine type from salary values when stored type is missing
                        if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                            $employee_type = 'monthly_paid';
                        } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                            $employee_type = 'daily_paid';
                        } else {
                            $employee_type = 'daily_paid';
                        }
                    } elseif (strpos($rawType, 'month') !== false) {
                        $employee_type = 'monthly_paid';
                    } elseif (strpos($rawType, 'day') !== false) {
                        $employee_type = 'daily_paid';
                    } else {
                        // fallback based on stored enum
                        $employee_type = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                    }
                    
                    // Count present days for the month
                    $stmt = $pdo->prepare("SELECT COUNT(*) as present_count FROM attendance 
                                          WHERE employee_id = ? AND attendance_date LIKE ? AND status = 'present'");
                    $stmt->execute([$employee['id'], $year . '-' . $month_num . '-%']);
                    $present_days = $stmt->fetch(PDO::FETCH_ASSOC)['present_count'];
                    
                    // Calculate salary based on correct type
                    $final_salary = 0;
                    $salary_info = '';
                    
                    if ($employee_type === 'daily_paid') {
                        $daily_rate = (float)($employee['daily_wage'] ?? 0);
                        $final_salary = $daily_rate * $present_days;
                        $salary_info = 'LKR ' . number_format($daily_rate, 2) . ' / day';
                    } else {
                        // Monthly paid: Monthly Salary / Working Days * Present Days
                        $monthly_salary = (float)($employee['monthly_salary'] ?? 0);
                        if ($monthly_salary > 0 && $working_days > 0) {
                            $per_day_salary = $monthly_salary / $working_days;
                            $final_salary = $per_day_salary * $present_days;
                        }
                        $salary_info = 'LKR ' . number_format($monthly_salary, 2) . ' / month';
                    }
                    
                    $report[] = [
                        'id' => $employee['id'],
                        'uid' => $employee['uid'],
                        'name' => $employee['name'],
                        'type' => $employee_type,
                        'salary_info' => $salary_info,
                        'present_days' => $present_days,
                        'total_working_days' => $working_days,
                        'final_salary' => number_format($final_salary, 2, '.', '')
                    ];
                }

                echo json_encode(['success' => true, 'report' => $report]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error generating report: ' . $e->getMessage()]);
            }
            exit;
        } elseif ($_POST['action'] === 'load_salary_data') {
            // Load salary data for salary view modal
            $month = $_POST['month'] ?? '';
            if (empty($month)) {
                echo json_encode(['success' => false, 'message' => 'Month not specified']);
                exit;
            }

            try {
                $reportData = $buildMonthlySalaryData($month);
                echo json_encode(['success' => true, 'salaryData' => $reportData['salaryData']]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading salary data: ' . $e->getMessage()]);
            }
            exit;
        } elseif ($_POST['action'] === 'set_payment_status') {
            $employeeId = $_POST['employee_id'] ?? '';
            $month = $_POST['month'] ?? '';
            $status = $_POST['status'] ?? '';
            
            if (!$employeeId || !$month || !in_array($status, ['paid', 'pending'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }
            
            try {
                $stmt = $pdo->prepare("INSERT INTO salary_payments (employee_id, month, status) VALUES (?, ?, ?) 
                                      ON DUPLICATE KEY UPDATE status = VALUES(status)");
                $stmt->execute([$employeeId, $month, $status]);
                echo json_encode(['success' => true]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating payment status: ' . $e->getMessage()]);
            }
            exit;
        } elseif ($_POST['action'] === 'update_single_attendance') {
            $employeeId = $_POST['employee_id'] ?? '';
            $attendanceDate = $_POST['attendance_date'] ?? '';
            $status = $_POST['status'] ?? '';

            if (!$employeeId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $attendanceDate) || !in_array($status, ['present', 'absent'])) {
                echo json_encode(['success' => false, 'message' => 'Invalid request']);
                exit;
            }

            try {
                $stmt = $pdo->prepare("SELECT id FROM employees WHERE id = ?");
                $stmt->execute([$employeeId]);
                if (!$stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Employee not found']);
                    exit;
                }

                $stmt = $pdo->prepare("INSERT INTO attendance (employee_id, attendance_date, status) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE status = VALUES(status)");
                $stmt->execute([$employeeId, $attendanceDate, $status]);

                echo json_encode(['success' => true, 'message' => 'Attendance updated successfully']);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error updating attendance: ' . $e->getMessage()]);
            }
            exit;
        }elseif ($_POST['action'] === 'load_attendance') {
            // Load Attendance for Date
            $date = $_POST['date'] ?? '';
            
            if (empty($date)) {
                echo json_encode(['success' => false, 'message' => 'Date not specified']);
                exit;
            }
            
            try {
                // Only load attendance for existing employees
                $stmt = $pdo->prepare("SELECT a.employee_id, a.status FROM attendance a 
                                      INNER JOIN employees e ON a.employee_id = e.id 
                                      WHERE a.attendance_date = ?");
                $stmt->execute([$date]);
                $attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'attendance' => $attendance_records]);
            } catch (Exception $e) {
                echo json_encode(['success' => false, 'message' => 'Error loading attendance: ' . $e->getMessage()]);
            }
            exit;
        }
    }
}

// Now include header AFTER POST processing
include 'header.php';

// Ensure is_active column exists
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN is_active TINYINT(1) DEFAULT 1");
} catch (Exception $e) {
    // Column already exists
}

// Ensure uid column exists and has values
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN uid VARCHAR(50) UNIQUE");
} catch (Exception $e) {
    // Column already exists
}

// Ensure employee_type, daily_wage, and monthly_salary columns exist
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN employee_type ENUM('daily_paid', 'monthly_paid') DEFAULT 'daily_paid'");
} catch (Exception $e) {
    // Column already exists
}
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN daily_wage DECIMAL(10,2) DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}
try {
    $pdo->exec("ALTER TABLE employees ADD COLUMN monthly_salary DECIMAL(10,2) DEFAULT 0");
} catch (Exception $e) {
    // Column already exists
}

// Normalize employee_type values and ensure default
try {
    // If the column is empty (legacy rows), infer from salary fields.
    $pdo->exec("UPDATE employees SET employee_type = 'monthly_paid' WHERE (employee_type = '' OR employee_type IS NULL) AND (monthly_salary > 0)");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE (employee_type = '' OR employee_type IS NULL) AND (daily_wage > 0)");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE employee_type IS NULL OR TRIM(employee_type) = ''");

    // Normalize string variants
    $pdo->exec("UPDATE employees SET employee_type = 'monthly_paid' WHERE LOWER(TRIM(employee_type)) LIKE '%month%'");
    $pdo->exec("UPDATE employees SET employee_type = 'daily_paid' WHERE LOWER(TRIM(employee_type)) LIKE '%day%'");
} catch (Exception $e) {
    // ignore
}

// Generate UIDs for employees that don't have one
$stmt = $pdo->query("SELECT id FROM employees WHERE uid IS NULL OR uid = ''");
$missingUids = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($missingUids as $row) {
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING(uid, 5) AS UNSIGNED)) as max_id FROM employees WHERE uid IS NOT NULL");
    $stmt->execute();
    $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'] ?? 0;
    $newUid = 'EMP-' . str_pad($maxId + 1, 3, '0', STR_PAD_LEFT);
    
    $updateStmt = $pdo->prepare("UPDATE employees SET uid = ? WHERE id = ?");
    $updateStmt->execute([$newUid, $row['id']]);
}

// Get all active employees
$stmt = $pdo->query("SELECT * FROM employees WHERE is_active = 1 ORDER BY name ASC");
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fix missing monthly_salary values for existing monthly-paid employees (legacy data)
foreach ($employees as &$employee) {
    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
    $typeKey = (strpos($rawType, 'month') !== false) ? 'monthly_paid' : ((strpos($rawType, 'day') !== false) ? 'daily_paid' : $rawType);

    if ($typeKey === 'monthly_paid' && (float)($employee['monthly_salary'] ?? 0) <= 0 && (float)($employee['daily_wage'] ?? 0) > 0) {
        // If monthly salary is missing but daily_wage contains the intended value, migrate it.
        $employee['monthly_salary'] = $employee['daily_wage'];
        $stmt = $pdo->prepare("UPDATE employees SET monthly_salary = ? WHERE id = ?");
        $stmt->execute([$employee['monthly_salary'], $employee['id']]);
    }
}
unset($employee);

// Get all past/inactive employees
$stmt = $pdo->query("SELECT * FROM employees WHERE is_active = 0 ORDER BY name ASC");
$pastEmployees = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($pastEmployees as &$employee) {
    $rawType = strtolower(trim($employee['employee_type'] ?? ''));
    $typeKey = (strpos($rawType, 'month') !== false) ? 'monthly_paid' : ((strpos($rawType, 'day') !== false) ? 'daily_paid' : $rawType);

    if ($typeKey === 'monthly_paid' && (float)($employee['monthly_salary'] ?? 0) <= 0 && (float)($employee['daily_wage'] ?? 0) > 0) {
        $employee['monthly_salary'] = $employee['daily_wage'];
        $stmt = $pdo->prepare("UPDATE employees SET monthly_salary = ? WHERE id = ?");
        $stmt->execute([$employee['monthly_salary'], $employee['id']]);
    }
}
unset($employee);

// Combine all employees for search
$allEmployees = array_merge($employees, $pastEmployees);

// Get employee for edit (if editing)
$editEmployee = null;
$editEmployeeId = $_GET['edit_id'] ?? null;
if ($editEmployeeId) {
    $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
    $stmt->execute([$editEmployeeId]);
    $editEmployee = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<?php if (isset($success)): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($success) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Manage Employees</h4>
    <div>
        <button class="btn btn-primary me-2" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="resetForm()">
            <i class="bi bi-plus-lg me-2"></i>Add Employee
        </button>
        <button class="btn btn-info me-2" data-bs-toggle="modal" data-bs-target="#attendanceModal">
            <i class="bi bi-calendar-check me-2"></i>Mark Attendance
        </button>
        <button class="btn btn-outline-secondary me-2" data-bs-toggle="modal" data-bs-target="#salaryModal">
            <i class="bi bi-currency-dollar me-2"></i>View Employee Salary
        </button>
        <button class="btn btn-secondary me-2" data-bs-toggle="modal" data-bs-target="#viewDetailsModal">
            <i class="bi bi-eye me-2"></i>View Employee Details
        </button>
        <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#pastEmployeesModal">
            <i class="bi bi-archive me-2"></i>View Past Employees
        </button>
    </div>
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
                        <th>Type</th>
                        <th>Phone Number</th>
                        <th>Daily Wage / Monthly Salary</th>
                        <th class="text-center">Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">No employees found. <a href="#" onclick="document.querySelector('[data-bs-target=\"#employeeModal\"]').click()">Add one now</a></td>
                    </tr>
                    <?php else: ?>
                        <?php foreach ($employees as $employee): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></strong></td>
                            <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                            <td><?= htmlspecialchars($employee['address'] ?? 'N/A') ?></td>
                            <?php
                                $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                                if ($rawType === '') {
                                    // Determine type from salary values when stored type is missing
                                    if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                        $typeKey = 'monthly_paid';
                                    } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                        $typeKey = 'daily_paid';
                                    } else {
                                        $typeKey = 'daily_paid';
                                    }
                                } elseif (strpos($rawType, 'month') !== false) {
                                    $typeKey = 'monthly_paid';
                                } elseif (strpos($rawType, 'day') !== false) {
                                    $typeKey = 'daily_paid';
                                } else {
                                    // fallback based on stored enum
                                    $typeKey = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                }
                                $typeLabel = $typeKey === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';

                                // Determine display amount based on type
                                if ($typeKey === 'daily_paid') {
                                    $displayAmount = number_format($employee['daily_wage'] ?? 0, 2);
                                    $displaySuffix = '/ day';
                                } else {
                                    // If monthly is missing, use daily_wage as backup (legacy data)
                                    $monthly = (float)($employee['monthly_salary'] ?? 0);
                                    if ($monthly <= 0) {
                                        $monthly = (float)($employee['daily_wage'] ?? 0);
                                    }
                                    $displayAmount = number_format($monthly, 2);
                                    $displaySuffix = '/ month';
                                }
                            ?>
                            <td>
                                <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                            <td>
                                <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                    <?= htmlspecialchars($typeLabel) ?>
                                </span>
                                <div class="mt-1">
                                    LKR <?= $displayAmount ?> <?= $displaySuffix ?>
                                </div>
                            </td>
                            <td class="text-center text-nowrap">
                                <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#employeeModal" onclick="editEmployee(<?= $employee['id'] ?>)">
                                    <i class="bi bi-pencil-square"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger" onclick="deleteEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['name'] ?? 'Employee') ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="text-end mt-4">
            <button class="btn btn-primary" onclick="downloadSalaryReport('pdf')"><i class="bi bi-download me-2"></i>Download Report</button>
        </div>
    </div>
</div>

<!-- Add/Edit Employee Modal -->
<div class="modal fade" id="employeeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="employeeModalTitle">Add Employee</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" id="employeeForm">
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="employee_id" id="employeeId" value="">
                    
                    <div class="mb-3">
                        <label for="uid" class="form-label">Auto-Generated UID</label>
                        <input type="text" class="form-control" id="uid" name="uid" readonly>
                        <small class="text-muted">Will be auto-generated on add</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="address" class="form-label">Address</label>
                        <textarea class="form-control" id="address" name="address" rows="2"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="employee_type" class="form-label">Employee Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="employee_type" name="employee_type" onchange="updateWageFields()" required>
                            <option value="daily_paid">Daily Paid</option>
                            <option value="monthly_paid">Monthly Paid</option>
                        </select>
                    </div>
                    
                    <div class="mb-3" id="daily_wage_field">
                        <label for="daily_wage" class="form-label">Daily Rate / Amount (LKR) <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="daily_wage" name="daily_wage" step="0.01" min="0">
                    </div>
                    
                    <div class="mb-3" id="monthly_salary_field" style="display: none;">
                        <label for="monthly_salary" class="form-label">Monthly Salary (LKR)</label>
                        <input type="number" class="form-control" id="monthly_salary" name="monthly_salary" step="0.01" min="0">
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">Phone Number <span class="text-danger">*</span></label>
                        <input type="tel" class="form-control" id="phone" name="phone" required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">Add Employee</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Mark Attendance Modal -->
<div class="modal fade" id="attendanceModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Mark Attendance & Generate Reports</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <!-- Date Selection -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label for="attendanceDate" class="form-label">Select Date</label>
                        <input type="date" class="form-control" id="attendanceDate" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-6">
                        <label for="attendanceSearch" class="form-label">Search Employees</label>
                        <input type="text" class="form-control" id="attendanceSearch" placeholder="Search by name or UID...">
                    </div>
                </div>

                <!-- Attendance Table -->
                <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                    <table class="table table-hover align-middle" id="attendanceTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>UID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Present</th>
                            </tr>
                        </thead>
                        <tbody id="attendanceBody">
                            <?php if (empty($employees)): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted">No active employees found.</td>
                            </tr>
                            <?php else: ?>
                                <?php foreach ($employees as $employee): ?>
                                <tr data-employee-id="<?= $employee['id'] ?>" data-name="<?= htmlspecialchars(strtolower($employee['name'] ?? '')) ?>" data-uid="<?= htmlspecialchars(strtolower($employee['uid'] ?? '')) ?>">
                                    <td><strong><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></strong></td>
                                    <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                                    <td>
                                        <?php
                                            $rawType = strtolower(trim($employee['employee_type'] ?? ''));
                                            if ($rawType === '') {
                                                // Determine type from salary values when stored type is missing
                                                if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                                    $typeKey = 'monthly_paid';
                                                } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                                    $typeKey = 'daily_paid';
                                                } else {
                                                    $typeKey = 'daily_paid';
                                                }
                                            } elseif (strpos($rawType, 'month') !== false) {
                                                $typeKey = 'monthly_paid';
                                            } elseif (strpos($rawType, 'day') !== false) {
                                                $typeKey = 'daily_paid';
                                            } else {
                                                // fallback based on stored enum
                                                $typeKey = ($rawType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                            }
                                            $typeLabel = $typeKey === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';
                                        ?>
                                        <span class="badge <?= $typeKey === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                            <?= htmlspecialchars($typeLabel) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="form-check">
                                            <input class="form-check-input attendance-checkbox" type="checkbox" 
                                                   id="present_<?= $employee['id'] ?>" 
                                                   data-employee-id="<?= $employee['id'] ?>" 
                                                   checked>
                                            <label class="form-check-label" for="present_<?= $employee['id'] ?>">
                                                Present
                                            </label>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Report Generation Section -->
                <div class="mt-4 border-top pt-3">
                    <h6 class="mb-3">Generate Monthly Report</h6>
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="reportMonth" class="form-label">Select Month</label>
                            <input type="month" class="form-control" id="reportMonth" value="<?= date('Y-m') ?>">
                        </div>
                        <div class="col-md-4">
                            <label for="reportSearch" class="form-label">Search Report</label>
                            <input type="text" class="form-control" id="reportSearch" placeholder="Search by name or UID...">
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="button" class="btn btn-success me-2" onclick="generateReport()">Generate Report</button>
                            <button type="button" class="btn btn-outline-primary me-2" onclick="exportReport('csv')">Export CSV</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="exportReport('pdf')">Export PDF</button>
                        </div>
                    </div>

                    <!-- Report Results -->
                    <div id="reportContainer" style="display: none;">
                        <div class="table-responsive" style="max-height: 300px; overflow-y: auto;">
                            <table class="table table-sm table-striped align-middle" id="reportTable">
                                <thead class="table-dark">
                                    <tr>
                                        <th>UID</th>
                                        <th>Name</th>
                                        <th>Type</th>
                                        <th>Salary/Rate</th>
                                        <th>Present Days</th>
                                        <th>Total Working Days</th>
                                        <th>Final Salary</th>
                                    </tr>
                                </thead>
                                <tbody id="reportBody">
                                    <!-- Report data will be populated here -->
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" onclick="saveAttendance()">Save Attendance</button>
            </div>
        </div>
    </div>
</div>

<!-- View Employee Salary Modal -->
<div class="modal fade" id="salaryModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">View Employee Salary</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label for="salaryMonth" class="form-label">Select Month</label>
                        <input type="month" class="form-control" id="salaryMonth" value="<?= date('Y-m') ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="salarySearch" class="form-label">Search Employee</label>
                        <input type="text" class="form-control" id="salarySearch" placeholder="Search by UID or name...">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <button type="button" class="btn btn-primary me-2" onclick="loadSalaryData()">Load Salaries</button>
                        <button type="button" class="btn btn-success me-2" onclick="downloadSalaryReport('pdf')">Generate Report</button>
                        <button type="button" class="btn btn-outline-primary" onclick="downloadSalaryReport('csv')">CSV</button>
                    </div>
                </div>

                <div class="table-responsive" style="max-height: 420px; overflow-y: auto;">
                    <table class="table table-hover align-middle" id="salaryTable">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Employee UID</th>
                                <th>Employee Name</th>
                                <th>Employee Type</th>
                                <th>Calculated Salary</th>
                                <th>Action</th>
                                <th>Payment Status</th>
                            </tr>
                        </thead>
                        <tbody id="salaryTableBody">
                            <tr>
                                <td colspan="6" class="text-center text-muted">Select month and click Load Salaries</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Attendance (Single Employee) Modal -->
<div class="modal fade" id="attendanceEditModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Edit Attendance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="attendanceEditEmployeeId">
                <div class="mb-3">
                    <label class="form-label">Employee</label>
                    <input type="text" id="attendanceEditEmployeeLabel" class="form-control" readonly>
                </div>
                <div class="mb-3">
                    <label for="attendanceEditDate" class="form-label">Date</label>
                    <input type="date" id="attendanceEditDate" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="mb-3">
                    <label for="attendanceEditStatus" class="form-label">Status</label>
                    <select id="attendanceEditStatus" class="form-select">
                        <option value="present">Present</option>
                        <option value="absent">Absent</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" onclick="saveAttendanceEdit()">Update Attendance</button>
            </div>
        </div>
    </div>
</div>

<!-- View Employee Details Modal -->
<div class="modal fade" id="viewDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Employee Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label for="employeeSearch" class="form-label">Search Employee</label>
                    <input type="text" class="form-control" id="employeeSearch" placeholder="Type employee name or UID..." autocomplete="off">
                    <div id="searchResults" class="list-group mt-2" style="display: none; max-height: 200px; overflow-y: auto;"></div>
                </div>
                
                <div id="employeeDetailsContainer" style="display: none;">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>UID:</strong> <span id="detailUid">-</span></p>
                            <p><strong>Name:</strong> <span id="detailName">-</span></p>
                            <p><strong>Address:</strong> <span id="detailAddress">-</span></p>
                            <p><strong>Phone:</strong> <span id="detailPhone">-</span></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Type:</strong> <span id="detailType">-</span></p>
                            <p><strong>Daily Wage:</strong> <span id="detailDailyWage">-</span></p>
                            <p><strong>Monthly Salary:</strong> <span id="detailMonthlySalary">-</span></p>
                            <p><strong>Status:</strong> <span id="detailStatus">-</span></p>
                            <p><strong>Joined:</strong> <span id="detailJoined">-</span></p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">Move to Past Employees</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Are you sure you want to move <strong id="deleteEmployeeName"></strong> to past employees?</p>
                <p class="text-muted small">This employee will be removed from the active list but their records will be preserved in the "Past Employees" section.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" onclick="confirmDelete()">Move to Past Employees</button>
            </div>
        </div>
    </div>
</div>

<!-- Past Employees Modal -->
<div class="modal fade" id="pastEmployeesModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title">Past Employees</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php if (empty($pastEmployees)): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle me-2"></i>No past employees at the moment. All employees are currently active.
                </div>
                <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>UID</th>
                                <th>Name</th>
                                <th>Address</th>
                                <th>Type</th>
                                <th>Phone</th>
                                <th>Wage/Salary</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($pastEmployees as $employee): ?>
                            <tr>
                                <td><?= htmlspecialchars($employee['uid'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($employee['name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($employee['address'] ?? 'N/A') ?></td>
                                <?php
                                    $rawPastType = strtolower(trim($employee['employee_type'] ?? ''));
                                    if ($rawPastType === '') {
                                        if ((float)($employee['monthly_salary'] ?? 0) > 0) {
                                            $pastType = 'monthly_paid';
                                        } elseif ((float)($employee['daily_wage'] ?? 0) > 0) {
                                            $pastType = 'daily_paid';
                                        } else {
                                            $pastType = 'daily_paid';
                                        }
                                    } elseif (strpos($rawPastType, 'month') !== false) {
                                        $pastType = 'monthly_paid';
                                    } elseif (strpos($rawPastType, 'day') !== false) {
                                        $pastType = 'daily_paid';
                                    } else {
                                        $pastType = ($rawPastType === 'monthly_paid') ? 'monthly_paid' : 'daily_paid';
                                    }
                                    $pastLabel = $pastType === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';

                                    if ($pastType === 'daily_paid') {
                                        $pastAmount = number_format($employee['daily_wage'] ?? 0, 2);
                                        $pastSuffix = '/ day';
                                    } else {
                                        $pastAmt = (float)($employee['monthly_salary'] ?? 0);
                                        if ($pastAmt <= 0) {
                                            $pastAmt = (float)($employee['daily_wage'] ?? 0);
                                        }
                                        $pastAmount = number_format($pastAmt, 2);
                                        $pastSuffix = '/ month';
                                    }
                                ?>
                                <td>
                                    <span class="badge <?= $pastType === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                        <?= htmlspecialchars($pastLabel) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($employee['phone'] ?? 'N/A') ?></td>
                                <td>
                                    <span class="badge <?= $pastType === 'daily_paid' ? 'bg-warning' : 'bg-info' ?>">
                                        <?= htmlspecialchars($pastLabel) ?>
                                    </span>
                                    <div class="mt-1">
                                        LKR <?= $pastAmount ?> <?= $pastSuffix ?>
                                    </div>
                                </td>
                                <td class="text-center">
                                    <button class="btn btn-sm btn-outline-success" onclick="reactivateEmployee(<?= $employee['id'] ?>, '<?= htmlspecialchars($employee['name'] ?? 'Employee') ?>')">
                                        <i class="bi bi-arrow-counterclockwise"></i> Reactivate
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
let deleteEmployeeId = null;
let employeeData = <?= json_encode($employees) ?>;
let allEmployeeData = <?= json_encode($allEmployees) ?>;
let salaryDataCache = [];

function loadSalaryData() {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    const tbody = document.getElementById('salaryTableBody');
    tbody.innerHTML = '<tr><td colspan="6" class="text-center">Loading salary details...</td></tr>';

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_salary_data',
            month: month
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">' + (data.message || 'Failed to load salary data') + '</td></tr>';
            return;
        }

        salaryDataCache = data.salaryData || [];
        renderSalaryTable();
    })
    .catch(error => {
        console.error('Error loading salary data:', error);
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error loading salary data</td></tr>';
    });
}

function renderSalaryTable() {
    const tbody = document.getElementById('salaryTableBody');
    const query = (document.getElementById('salarySearch').value || '').toLowerCase().trim();

    const filtered = salaryDataCache.filter(emp => {
        const uid = (emp.uid || '').toLowerCase();
        const name = (emp.name || '').toLowerCase();
        return uid.includes(query) || name.includes(query);
    });

    if (filtered.length === 0) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No employees found for selected month/filter</td></tr>';
        return;
    }

    tbody.innerHTML = filtered.map(emp => {
        const typeLabel = emp.type === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid';
        const typeBadge = emp.type === 'daily_paid' ? 'bg-warning' : 'bg-info';
        const status = emp.payment_status === 'paid' ? 'paid' : 'pending';
        return `
            <tr>
                <td><strong>${emp.uid || 'N/A'}</strong></td>
                <td>${emp.name || 'N/A'}</td>
                <td><span class="badge ${typeBadge}">${typeLabel}</span></td>
                <td><strong>LKR ${parseFloat(emp.final_salary || 0).toFixed(2)}</strong></td>
                <td>
                    <button type="button" class="btn btn-sm btn-outline-primary" onclick="openAttendanceEdit(${emp.id}, '${String(emp.uid || '').replace(/'/g, "\\'")}', '${String(emp.name || '').replace(/'/g, "\\'")}')">
                        <i class="bi bi-pencil-square me-1"></i>Edit Attendance
                    </button>
                </td>
                <td>
                    <select class="form-select form-select-sm" onchange="updateSalaryPaymentStatus(${emp.id}, this.value)">
                        <option value="pending" ${status === 'pending' ? 'selected' : ''}>Pending</option>
                        <option value="paid" ${status === 'paid' ? 'selected' : ''}>Paid</option>
                    </select>
                </td>
            </tr>
        `;
    }).join('');
}

function updateSalaryPaymentStatus(employeeId, status) {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'set_payment_status',
            employee_id: employeeId,
            month: month,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to update payment status');
            return;
        }

        const idx = salaryDataCache.findIndex(e => String(e.id) === String(employeeId));
        if (idx >= 0) {
            salaryDataCache[idx].payment_status = status;
        }
    })
    .catch(error => {
        console.error('Error updating payment status:', error);
        alert('Error updating payment status');
    });
}

function openAttendanceEdit(employeeId, uid, name) {
    document.getElementById('attendanceEditEmployeeId').value = employeeId;
    document.getElementById('attendanceEditEmployeeLabel').value = `${uid} - ${name}`;
    document.getElementById('attendanceEditDate').value = new Date().toISOString().slice(0, 10);
    document.getElementById('attendanceEditStatus').value = 'present';
    const modal = new bootstrap.Modal(document.getElementById('attendanceEditModal'));
    modal.show();
}

function saveAttendanceEdit() {
    const employeeId = document.getElementById('attendanceEditEmployeeId').value;
    const attendanceDate = document.getElementById('attendanceEditDate').value;
    const status = document.getElementById('attendanceEditStatus').value;

    if (!employeeId || !attendanceDate) {
        alert('Employee and date are required');
        return;
    }

    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update_single_attendance',
            employee_id: employeeId,
            attendance_date: attendanceDate,
            status: status
        })
    })
    .then(response => response.json())
    .then(data => {
        if (!data.success) {
            alert(data.message || 'Failed to update attendance');
            return;
        }

        const modalEl = document.getElementById('attendanceEditModal');
        const modalInstance = bootstrap.Modal.getInstance(modalEl);
        if (modalInstance) {
            modalInstance.hide();
        }

        loadSalaryData();
    })
    .catch(error => {
        console.error('Error updating attendance:', error);
        alert('Error updating attendance');
    });
}

function downloadSalaryReport(format) {
    const month = document.getElementById('salaryMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }
    window.open(`?page=employees&action=download_salary_report&month=${encodeURIComponent(month)}&format=${encodeURIComponent(format)}`, '_blank');
}

function resetForm() {
    document.getElementById('employeeForm').reset();
    document.getElementById('formAction').value = 'add';
    document.getElementById('employeeId').value = '';
    document.getElementById('uid').value = '';
    document.getElementById('employeeModalTitle').textContent = 'Add Employee';
    document.getElementById('submitBtn').textContent = 'Add Employee';
    document.getElementById('employee_type').value = 'daily_paid';
    updateWageFields();
}

function editEmployee(id) {
    const employee = employeeData.find(e => e.id == id);
    if (!employee) return;
    
    document.getElementById('formAction').value = 'edit';
    document.getElementById('employeeId').value = employee.id;
    document.getElementById('uid').value = employee.uid || '';
    document.getElementById('uid').setAttribute('readonly', 'readonly');
    document.getElementById('name').value = employee.name || '';
    document.getElementById('address').value = employee.address || '';
    const rawType = (employee.employee_type || 'daily_paid').toString().toLowerCase();
    const normalizedType = rawType.includes('month') ? 'monthly_paid' : 'daily_paid';
    document.getElementById('employee_type').value = normalizedType;
    document.getElementById('daily_wage').value = employee.daily_wage || '';
    document.getElementById('monthly_salary').value = employee.monthly_salary || '';
    document.getElementById('phone').value = employee.phone || '';
    
    document.getElementById('employeeModalTitle').textContent = 'Edit Employee';
    document.getElementById('submitBtn').textContent = 'Update Employee';
    
    updateWageFields();
}

function deleteEmployee(id, name) {
    deleteEmployeeId = id;
    document.getElementById('deleteEmployeeName').textContent = name;
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    deleteModal.show();
}

function confirmDelete() {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = '<input type="hidden" name="action" value="delete"><input type="hidden" name="employee_id" value="' + deleteEmployeeId + '">';
    document.body.appendChild(form);
    form.submit();
}

function reactivateEmployee(id, name) {
    if (confirm('Are you sure you want to reactivate ' + name + '?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = '<input type="hidden" name="action" value="reactivate"><input type="hidden" name="employee_id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}

function updateWageFields() {
    const type = document.getElementById('employee_type').value || 'daily_paid';
    const dailyInput = document.getElementById('daily_wage');
    const monthlyInput = document.getElementById('monthly_salary');

    if (type === 'daily_paid') {
        document.getElementById('daily_wage_field').style.display = 'block';
        document.getElementById('monthly_salary_field').style.display = 'none';
        dailyInput.required = true;
        monthlyInput.required = false;
    } else {
        document.getElementById('daily_wage_field').style.display = 'none';
        document.getElementById('monthly_salary_field').style.display = 'block';
        dailyInput.required = false;
        monthlyInput.required = true;
    }
}

function loadEmployeeDetails() {
    const selectedId = document.getElementById('selectedEmployee').value;
    const employee = employeeData.find(e => e.id == selectedId);
    
    if (!employee) {
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    document.getElementById('detailUid').textContent = employee.uid || 'N/A';
    document.getElementById('detailName').textContent = employee.name || 'N/A';
    document.getElementById('detailAddress').textContent = employee.address || 'N/A';
    document.getElementById('detailPhone').textContent = employee.phone || 'N/A';
    document.getElementById('detailType').textContent = employee.employee_type === 'monthly_paid' ? 'Monthly Paid' : 'Daily Paid';
    document.getElementById('detailDailyWage').textContent = employee.daily_wage ? 'LKR ' + parseFloat(employee.daily_wage).toFixed(2) : 'N/A';
    document.getElementById('detailMonthlySalary').textContent = employee.monthly_salary ? 'LKR ' + parseFloat(employee.monthly_salary).toFixed(2) : 'N/A';
    document.getElementById('detailJoined').textContent = new Date(employee.created_at).toLocaleDateString();
    
    document.getElementById('employeeDetailsContainer').style.display = 'block';
}

// Search functionality for View Employee Details
document.getElementById('employeeSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const resultsDiv = document.getElementById('searchResults');
    
    if (query.length < 1) {
        resultsDiv.style.display = 'none';
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    const filtered = allEmployeeData.filter(emp => 
        (emp.name && emp.name.toLowerCase().includes(query)) || 
        (emp.uid && emp.uid.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        resultsDiv.innerHTML = '<div class="list-group-item text-muted">No employees found</div>';
        resultsDiv.style.display = 'block';
        document.getElementById('employeeDetailsContainer').style.display = 'none';
        return;
    }
    
    resultsDiv.innerHTML = filtered.slice(0, 10).map(emp => 
        `<button type="button" class="list-group-item list-group-item-action" onclick="selectEmployee(${emp.id})">
            ${emp.name || 'N/A'} (${emp.uid || 'N/A'}) - ${emp.is_active == 1 ? 'Active' : 'Inactive'}
        </button>`
    ).join('');
    
    resultsDiv.style.display = 'block';
});

function selectEmployee(id) {
    const employee = allEmployeeData.find(e => e.id == id);
    if (!employee) return;
    
    document.getElementById('employeeSearch').value = `${employee.name || 'N/A'} (${employee.uid || 'N/A'})`;
    document.getElementById('searchResults').style.display = 'none';
    
    // Load details
    document.getElementById('detailUid').textContent = employee.uid || 'N/A';
    document.getElementById('detailName').textContent = employee.name || 'N/A';
    document.getElementById('detailAddress').textContent = employee.address || 'N/A';
    document.getElementById('detailPhone').textContent = employee.phone || 'N/A';
    document.getElementById('detailType').textContent = employee.employee_type === 'monthly_paid' ? 'Monthly Paid' : 'Daily Paid';
    document.getElementById('detailDailyWage').textContent = employee.daily_wage ? 'LKR ' + parseFloat(employee.daily_wage).toFixed(2) : 'N/A';
    document.getElementById('detailMonthlySalary').textContent = employee.monthly_salary ? 'LKR ' + parseFloat(employee.monthly_salary).toFixed(2) : 'N/A';
    document.getElementById('detailStatus').textContent = employee.is_active == 1 ? 'Active' : 'Inactive';
    document.getElementById('detailJoined').textContent = employee.created_at ? new Date(employee.created_at).toLocaleDateString() : 'N/A';
    
    document.getElementById('employeeDetailsContainer').style.display = 'block';
}

// Search functionality for attendance table
document.getElementById('attendanceSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#attendanceBody tr[data-employee-id]');
    
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const uid = row.dataset.uid || '';
        const visible = name.includes(query) || uid.includes(query);
        row.style.display = visible ? '' : 'none';
    });
});

function saveAttendance() {
    const date = document.getElementById('attendanceDate').value;
    if (!date) {
        alert('Please select a date');
        return;
    }

    const checkboxes = document.querySelectorAll('.attendance-checkbox');
    const attendanceData = [];

    checkboxes.forEach(checkbox => {
        const employeeId = checkbox.dataset.employeeId;
        const status = checkbox.checked ? 'present' : 'absent';
        attendanceData.push({
            employee_id: employeeId,
            date: date,
            status: status
        });
    });

    // Send data to server
    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'save_attendance',
            attendance: attendanceData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Attendance saved successfully!');
        } else {
            alert('Error saving attendance: ' + data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('Error saving attendance');
    });
}

// Load attendance data when date changes
document.getElementById('attendanceDate').addEventListener('change', function() {
    loadAttendanceForDate(this.value);
});

function loadAttendanceForDate(date) {
    if (!date) return;
    
    // Fetch existing attendance for the date
    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'load_attendance',
            date: date
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Update checkboxes based on loaded data
            const checkboxes = document.querySelectorAll('.attendance-checkbox');
            checkboxes.forEach(checkbox => {
                const employeeId = checkbox.dataset.employeeId;
                const attendance = data.attendance.find(a => a.employee_id == employeeId);
                checkbox.checked = attendance ? attendance.status === 'present' : true; // Default to present
            });
        }
    })
    .catch(error => {
        console.error('Error loading attendance:', error);
    });
}

// Search functionality for report
document.getElementById('reportSearch').addEventListener('input', function() {
    const query = this.value.toLowerCase().trim();
    const rows = document.querySelectorAll('#reportBody tr[data-employee-id]');
    
    rows.forEach(row => {
        const name = row.dataset.name || '';
        const uid = row.dataset.uid || '';
        const visible = name.includes(query) || uid.includes(query);
        row.style.display = visible ? '' : 'none';
    });
});

document.getElementById('salarySearch').addEventListener('input', function() {
    renderSalaryTable();
});

document.getElementById('salaryMonth').addEventListener('change', function() {
    if (salaryDataCache.length > 0) {
        loadSalaryData();
    }
});

document.getElementById('salaryModal').addEventListener('shown.bs.modal', function() {
    loadSalaryData();
});

// Generate monthly report
function generateReport() {
    const month = document.getElementById('reportMonth').value;
    if (!month) {
        alert('Please select a month');
        return;
    }

    // Show loading
    document.getElementById('reportContainer').style.display = 'block';
    document.getElementById('reportBody').innerHTML = '<tr><td colspan="7" class="text-center">Generating report...</td></tr>';

    // Fetch report data
    fetch('?page=employees', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'generate_report',
            month: month
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            displayReport(data.report);
        } else {
            document.getElementById('reportBody').innerHTML = '<tr><td colspan="7" class="text-center text-danger">Error generating report: ' + data.message + '</td></tr>';
        }
    })
    .catch(error => {
        console.error('Error:', error);
        document.getElementById('reportBody').innerHTML = '<tr><td colspan="6" class="text-center text-danger">Error generating report</td></tr>';
    });
}

function displayReport(reportData) {
    const tbody = document.getElementById('reportBody');
    tbody.innerHTML = '';

    if (reportData.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No data found for selected month</td></tr>';
        return;
    }

    reportData.forEach(employee => {
        const row = document.createElement('tr');
        row.setAttribute('data-employee-id', employee.id);
        row.setAttribute('data-name', employee.name.toLowerCase());
        row.setAttribute('data-uid', employee.uid.toLowerCase());
        
        row.innerHTML = `
            <td><strong>${employee.uid}</strong></td>
            <td>${employee.name}</td>
            <td>
                <span class="badge ${employee.type === 'daily_paid' ? 'bg-warning' : 'bg-info'}">
                    ${employee.type === 'daily_paid' ? 'Daily Paid' : 'Monthly Paid'}
                </span>
            </td>
            <td>${employee.salary_info}</td>
            <td>${employee.present_days}</td>
            <td>${employee.total_working_days}</td>
            <td><strong>LKR ${parseFloat(employee.final_salary).toFixed(2)}</strong></td>
        `;
        
        tbody.appendChild(row);
    });
}

function exportReport(format = 'csv') {
    const month = document.getElementById('reportMonth').value;
    const searchQuery = document.getElementById('reportSearch').value.trim();
    
    if (!month) {
        alert('Please select a month and generate report first');
        return;
    }

    // Determine visible rows (filtered rows)
    const visibleRows = Array.from(document.querySelectorAll('#reportBody tr[data-employee-id]')).filter(row => 
        row.style.display !== 'none'
    );

    if (visibleRows.length === 0) {
        alert('No data to export');
        return;
    }

    const isSingleEmployee = visibleRows.length === 1;
    const filenameBase = isSingleEmployee ?
        `employee_report_${month}_${searchQuery.replace(/[^a-zA-Z0-9]/g, '_')}` :
        `monthly_report_${month}`;

    if (format === 'csv') {
        let csv = '';
        if (isSingleEmployee) {
            csv = 'Field,Value\n';
            const cells = visibleRows[0].querySelectorAll('td');
            const headers = ['UID', 'Name', 'Type', 'Salary/Rate', 'Present Days', 'Total Working Days', 'Final Salary'];

            headers.forEach((header, index) => {
                const value = cells[index] ? cells[index].textContent.trim() : '';
                csv += `"${header}","${value.replace(/"/g, '""')}"\n`;
            });
        } else {
            csv = 'UID,Name,Type,Salary/Rate,Present Days,Total Working Days,Final Salary\n';
            visibleRows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const data = Array.from(cells).map(cell => {
                    return cell.textContent.replace(/,/g, ';').trim();
                });
                csv += data.map(field => `"${field.replace(/"/g, '""')}"`).join(',') + '\n';
            });
        }

        const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${filenameBase}.csv`;
        a.style.display = 'none';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        window.URL.revokeObjectURL(url);
        return;
    }

    // PDF Export using jsPDF + autoTable
    if (typeof window.jspdf === 'undefined' || typeof window.jspdf === 'undefined' && typeof window.jsPDF === 'undefined') {
        alert('PDF export requires jsPDF library. Please ensure it is loaded.');
        return;
    }

    const doc = new window.jspdf.jsPDF();
    const title = isSingleEmployee ? 'Employee Attendance Report' : 'Monthly Attendance Report';
    doc.setFontSize(14);
    doc.text(title, 14, 20);

    if (isSingleEmployee) {
        const cells = visibleRows[0].querySelectorAll('td');
        const headers = ['UID', 'Name', 'Type', 'Salary/Rate', 'Present Days', 'Total Working Days', 'Final Salary'];
        const data = headers.map((h, idx) => [h, cells[idx] ? cells[idx].textContent.trim() : '']);

        doc.autoTable({
            startY: 28,
            theme: 'grid',
            head: [['Field', 'Value']],
            body: data,
            styles: { fontSize: 10 }
        });
    } else {
        const rows = visibleRows.map(row => {
            const cells = row.querySelectorAll('td');
            return Array.from(cells).map(cell => cell.textContent.trim());
        });

        doc.autoTable({
            startY: 28,
            theme: 'striped',
            head: [['UID', 'Name', 'Type', 'Salary/Rate', 'Present Days', 'Total Working Days', 'Final Salary']],
            body: rows,
            styles: { fontSize: 9 }
        });
    }

    doc.save(`${filenameBase}.pdf`);
}
</script>

<?php include 'footer.php'; ?>
