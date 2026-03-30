<?php
$action = $_GET['action'] ?? 'index';
$currency = $settings['currency_symbol'] ?? 'LKR';

$formatEventBillNo = function ($billNumber) {
    if (preg_match('/-E-(\d+)$/', $billNumber, $matches)) {
        $num = ltrim($matches[1], '0');
        if ($num === '') {
            $num = '0';
        }
        return 'E-' . $num;
    }
    return $billNumber;
};

$extractEventDateFromNotes = function ($notes) {
    if (!is_string($notes) || $notes === '') {
        return null;
    }

    if (!preg_match('/Event Date:\s*([^|]+)/i', $notes, $matches)) {
        return null;
    }

    $rawEventDate = trim($matches[1]);
    if ($rawEventDate === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd-m-Y', 'm-d-Y', 'Y/m/d', 'd/m/Y', 'm/d/Y'];
    foreach ($formats as $format) {
        $dt = DateTime::createFromFormat($format, $rawEventDate);
        if ($dt && $dt->format($format) === $rawEventDate) {
            return $dt->format('Y-m-d');
        }
    }

    if (preg_match('/([0-9]{4})[-\/]([0-9]{2})[-\/]([0-9]{2})/', $rawEventDate, $parts)) {
        return $parts[1] . '-' . $parts[2] . '-' . $parts[3];
    }

    return null;
};

$extractCustomerNameFromNotes = function ($notes) {
    if (!is_string($notes) || $notes === '') {
        return '';
    }
    if (preg_match('/Customer Name:\s*([^|]+)/i', $notes, $matches)) {
        return trim($matches[1]);
    }
    return '';
};

$extractCustomerPhoneFromNotes = function ($notes) {
    if (!is_string($notes) || $notes === '') {
        return '';
    }
    if (preg_match('/Customer Phone:\s*([^|]+)/i', $notes, $matches)) {
        return trim($matches[1]);
    }
    return '';
};

$extractEventAddressFromNotes = function ($notes) {
    if (!is_string($notes) || $notes === '') {
        return '';
    }
    if (preg_match('/Event Address:\s*([^|]+)/i', $notes, $matches)) {
        return trim($matches[1]);
    }
    return '';
};

$getFilteredEventBills = function (array $params) use ($pdo, $extractEventDateFromNotes, $extractCustomerNameFromNotes, $extractCustomerPhoneFromNotes, $extractEventAddressFromNotes) {
    $allowedPaymentFilters = ['all', 'pending', 'partial', 'paid'];
    $allowedEventFilters = ['all', 'upcoming', 'today', 'past', 'no_date'];
    $allowedBalanceFilters = ['all', 'with_balance', 'cleared'];
    $allowedReportFilters = ['all', 'partial_payments', 'pending_payments', 'paid_bills', 'upcoming_events', 'today_events', 'past_events', 'with_balance', 'cleared', 'no_event_date'];

    $rawReportFilters = $params['report_filter'] ?? ['all'];
    $reportFilters = is_array($rawReportFilters) ? $rawReportFilters : [$rawReportFilters];
    $paymentFilter = $params['payment_filter'] ?? 'all';
    $eventFilter = $params['event_filter'] ?? 'all';
    $balanceFilter = $params['balance_filter'] ?? 'all';
    $billSearch = trim((string)($params['bill_search'] ?? ''));
    $eventDateFrom = trim((string)($params['event_date_from'] ?? ''));
    $eventDateTo = trim((string)($params['event_date_to'] ?? ''));
    $minTotalRaw = $params['min_total'] ?? '';
    $maxTotalRaw = $params['max_total'] ?? '';
    $minTotal = ($minTotalRaw === '' || $minTotalRaw === null) ? null : max(0, floatval($minTotalRaw));
    $maxTotal = ($maxTotalRaw === '' || $maxTotalRaw === null) ? null : max(0, floatval($maxTotalRaw));

    $reportFilters = array_values(array_unique(array_filter($reportFilters, function ($filter) use ($allowedReportFilters) {
        return in_array($filter, $allowedReportFilters, true);
    })));
    if (empty($reportFilters)) {
        $reportFilters = ['all'];
    }
    if (count($reportFilters) > 1) {
        $reportFilters = array_values(array_filter($reportFilters, function ($f) {
            return $f !== 'all';
        }));
    }
    if (empty($reportFilters)) {
        $reportFilters = ['all'];
    }

    if (!in_array($paymentFilter, $allowedPaymentFilters, true)) {
        $paymentFilter = 'all';
    }
    if (!in_array($eventFilter, $allowedEventFilters, true)) {
        $eventFilter = 'all';
    }
    if (!in_array($balanceFilter, $allowedBalanceFilters, true)) {
        $balanceFilter = 'all';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateFrom)) {
        $eventDateFrom = '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $eventDateTo)) {
        $eventDateTo = '';
    }

    $rows = $pdo->query("SELECT b.* FROM bills b WHERE b.type = 'wholesale' ORDER BY b.created_at DESC LIMIT 1000")->fetchAll();
    $today = date('Y-m-d');
    $filtered = [];

    foreach ($rows as $row) {
        $eventDate = $extractEventDateFromNotes($row['notes'] ?? '');
        $customerName = $extractCustomerNameFromNotes($row['notes'] ?? '');
        $customerPhone = $extractCustomerPhoneFromNotes($row['notes'] ?? '');
        $row['event_date'] = $eventDate;
        $row['customer_name'] = $customerName;
        $row['customer_phone'] = $customerPhone;
        $totalAmount = floatval($row['total_amount'] ?? 0);
        $paidAmount = floatval($row['paid_amount'] ?? 0);
        $balanceAmount = $totalAmount - $paidAmount;

        if ($billSearch !== '' && stripos((string)($row['bill_number'] ?? ''), $billSearch) === false) {
            continue;
        }
        if ($eventDateFrom !== '' && (!$eventDate || $eventDate < $eventDateFrom)) {
            continue;
        }
        if ($eventDateTo !== '' && (!$eventDate || $eventDate > $eventDateTo)) {
            continue;
        }
        if ($minTotal !== null && $totalAmount < $minTotal) {
            continue;
        }
        if ($maxTotal !== null && $totalAmount > $maxTotal) {
            continue;
        }

        if ($paymentFilter !== 'all' && ($row['payment_status'] ?? 'pending') !== $paymentFilter) {
            continue;
        }

        if ($balanceFilter === 'with_balance' && $balanceAmount <= 0) {
            continue;
        }
        if ($balanceFilter === 'cleared' && $balanceAmount > 0) {
            continue;
        }

        if ($eventFilter === 'upcoming' && (!$eventDate || $eventDate <= $today)) {
            continue;
        }
        if ($eventFilter === 'today' && $eventDate !== $today) {
            continue;
        }
        if ($eventFilter === 'past' && (!$eventDate || $eventDate >= $today)) {
            continue;
        }
        if ($eventFilter === 'no_date' && $eventDate) {
            continue;
        }

        if (!(count($reportFilters) === 1 && $reportFilters[0] === 'all')) {
            $reportTypeMatch = true;
            foreach ($reportFilters as $reportType) {
                if ($reportType === 'partial_payments' && ($row['payment_status'] ?? 'pending') !== 'partial') {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'pending_payments' && ($row['payment_status'] ?? 'pending') !== 'pending') {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'paid_bills' && ($row['payment_status'] ?? 'pending') !== 'paid') {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'upcoming_events' && !($eventDate && $eventDate > $today)) {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'today_events' && $eventDate !== $today) {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'past_events' && !($eventDate && $eventDate < $today)) {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'with_balance' && !($balanceAmount > 0)) {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'cleared' && !($balanceAmount <= 0)) {
                    $reportTypeMatch = false;
                    break;
                }
                if ($reportType === 'no_event_date' && $eventDate) {
                    $reportTypeMatch = false;
                    break;
                }
            }

            if (!$reportTypeMatch) {
                continue;
            }
        }

        $filtered[] = $row;
    }

    return [
        'bills' => $filtered,
        'filters' => [
            'report_filter' => $reportFilters,
            'payment_filter' => $paymentFilter,
            'event_filter' => $eventFilter,
            'balance_filter' => $balanceFilter,
            'bill_search' => $billSearch,
            'event_date_from' => $eventDateFrom,
            'event_date_to' => $eventDateTo,
            'min_total' => $minTotalRaw === null ? '' : (string)$minTotalRaw,
            'max_total' => $maxTotalRaw === null ? '' : (string)$maxTotalRaw,
        ],
    ];
};

if ($action === 'download') {
    $filterData = $getFilteredEventBills($_GET);
    $billsForExport = $filterData['bills'];

    $pdfEscape = function ($text) {
        $text = (string)$text;
        $text = str_replace('\\', '\\\\', $text);
        $text = str_replace('(', '\\(', $text);
        $text = str_replace(')', '\\)', $text);
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);
        return $text;
    };

    $trimCell = function ($text, $maxChars) {
        $text = trim((string)$text);
        if (strlen($text) <= $maxChars) {
            return $text;
        }
        return substr($text, 0, max(0, $maxChars - 3)) . '...';
    };

    $rows = [];
    $sumTotal = 0.0;
    $sumPaid = 0.0;
    $sumBalance = 0.0;

    foreach ($billsForExport as $bill) {
        $total = floatval($bill['total_amount'] ?? 0);
        $paid = floatval($bill['paid_amount'] ?? 0);
        $balance = $total - $paid;
        $sumTotal += $total;
        $sumPaid += $paid;
        $sumBalance += $balance;

        $rows[] = [
            'bill_no' => $trimCell($formatEventBillNo($bill['bill_number'] ?? ''), 14),
            'event_date' => $trimCell($bill['event_date'] ?: '-', 12),
            'customer_name' => $trimCell($bill['customer_name'] ?: '-', 24),
            'customer_phone' => $trimCell($bill['customer_phone'] ?: '-', 18),
            'total' => number_format($total, 2, '.', ','),
            'advance' => number_format($paid, 2, '.', ','),
            'balance' => number_format($balance, 2, '.', ','),
            'status' => $trimCell(ucfirst($bill['payment_status'] ?? 'pending'), 10),
        ];
    }

    $rowsPerPage = 27;
    $rowPages = array_chunk($rows, $rowsPerPage);

    $objects = [];
    $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>';
    $objects[6] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier-Bold >>';

    $pageRefs = [];
    $nextObj = 5;

    if (empty($rowPages)) {
        $rowPages = [[]];
    }

    $pageWidth = 595;
    $margin = 30;
    $tableWidth = $pageWidth - ($margin * 2);
    $columns = [
        ['key' => 'bill_no', 'label' => 'Bill No', 'width' => 55, 'align' => 'L'],
        ['key' => 'event_date', 'label' => 'Event Date', 'width' => 70, 'align' => 'L'],
        ['key' => 'customer_name', 'label' => 'Customer Name', 'width' => 105, 'align' => 'L'],
        ['key' => 'customer_phone', 'label' => 'Phone', 'width' => 80, 'align' => 'L'],
        ['key' => 'total', 'label' => 'Total', 'width' => 65, 'align' => 'R'],
        ['key' => 'advance', 'label' => 'Advance', 'width' => 65, 'align' => 'R'],
        ['key' => 'balance', 'label' => 'Balance', 'width' => 65, 'align' => 'R'],
        ['key' => 'status', 'label' => 'Status', 'width' => 30, 'align' => 'L'],
    ];

    $drawText = function ($x, $y, $text, $fontSize, $bold, $tableMode = false) use ($pdfEscape) {
        if ($tableMode) {
            $font = $bold ? '/F6' : '/F5';
        } else {
            $font = $bold ? '/F2' : '/F1';
        }
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    $drawRightText = function ($xRight, $y, $text, $fontSize, $bold, $tableMode = false) use ($pdfEscape) {
        if ($tableMode) {
            $font = $bold ? '/F6' : '/F5';
            $charWidth = $fontSize * 0.60;
        } else {
            $font = $bold ? '/F2' : '/F1';
            $charWidth = $fontSize * 0.50;
        }
        $textWidth = strlen((string)$text) * $charWidth;
        $x = $xRight - $textWidth;
        return "BT\n{$font} {$fontSize} Tf\n1 0 0 1 {$x} {$y} Tm (" . $pdfEscape($text) . ") Tj\nET\n";
    };

    foreach ($rowPages as $pageIndex => $rowPage) {
        $pageObj = $nextObj++;
        $contentObj = $nextObj++;
        $pageRefs[] = $pageObj . ' 0 R';

        $content = "";

        // Title banner
        $content .= "q\n0.13 0.35 0.75 rg\n{$margin} 785 {$tableWidth} 34 re f\nQ\n";
        $content .= $drawText($margin + 10, 798, 'Event Billing Report', 14, true);

        // Summary section (fixed grid for clean alignment)
        $summaryTop = 776;
        $summaryRowHeight = 16;
        $summaryColWidth = $tableWidth / 3;
        $summaryRows = [
            [
                'Generated: ' . date('Y-m-d H:i:s'),
                'Records: ' . count($rows),
                'Page: ' . ($pageIndex + 1) . '/' . count($rowPages),
            ],
            [
                'Total: ' . number_format($sumTotal, 2),
                'Advance: ' . number_format($sumPaid, 2),
                'Balance: ' . number_format($sumBalance, 2),
            ],
        ];

        foreach ($summaryRows as $rowIndex => $summaryRow) {
            $cellY = $summaryTop - ($rowIndex * $summaryRowHeight);
            foreach ($summaryRow as $colIndex => $cellText) {
                $cellX = $margin + ($colIndex * $summaryColWidth);
                if ($rowIndex === 0) {
                    $content .= "q\n0.97 0.98 1 rg\n{$cellX} " . ($cellY - $summaryRowHeight) . " {$summaryColWidth} {$summaryRowHeight} re f\nQ\n";
                }
                $content .= "q\n0.85 0.88 0.94 RG\n0.5 w\n{$cellX} " . ($cellY - $summaryRowHeight) . " {$summaryColWidth} {$summaryRowHeight} re S\nQ\n";
                $content .= $drawText($cellX + 6, $cellY - 11, $cellText, 8.5, false);
            }
        }

        // Header row
        $tableTop = 730;
        $headerHeight = 20;
        $rowHeight = 18;
        $content .= "q\n0.90 0.93 0.98 rg\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re f\nQ\n";
        $content .= "q\n0.75 0.80 0.90 RG\n0.8 w\n{$margin} " . ($tableTop - $headerHeight) . " {$tableWidth} {$headerHeight} re S\nQ\n";

        $x = $margin;
        foreach ($columns as $col) {
            $content .= $drawText($x + 4, $tableTop - 14, $col['label'], 9, true, true);
            $x += $col['width'];
            $content .= "q\n0.85 0.88 0.94 RG\n0.5 w\n{$x} " . ($tableTop - $headerHeight) . " m {$x} " . ($tableTop - $headerHeight - ($rowHeight * max(1, count($rowPage)))) . " l S\nQ\n";
        }

        // Data rows
        $currentY = $tableTop - $headerHeight;
        if (empty($rowPage)) {
            $content .= "q\n0.92 0.92 0.92 RG\n0.5 w\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re S\nQ\n";
            $content .= $drawText($margin + 8, $currentY - 13, 'No records found for selected filters.', 9, false, true);
            $currentY -= $rowHeight;
        } else {
            foreach ($rowPage as $i => $row) {
                if ($i % 2 === 0) {
                    $content .= "q\n0.98 0.99 1 rg\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re f\nQ\n";
                }

                $content .= "q\n0.92 0.92 0.92 RG\n0.5 w\n{$margin} " . ($currentY - $rowHeight) . " {$tableWidth} {$rowHeight} re S\nQ\n";

                $x = $margin;
                foreach ($columns as $col) {
                    $value = $row[$col['key']] ?? '';
                    if ($col['align'] === 'R') {
                        $content .= $drawRightText($x + $col['width'] - 6, $currentY - 12, $value, 9, false, true);
                    } else {
                        $content .= $drawText($x + 4, $currentY - 12, $value, 9, false, true);
                    }
                    $x += $col['width'];
                }

                $currentY -= $rowHeight;
            }
        }

        $objects[$contentObj] = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "\nendstream";
        $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F5 5 0 R /F6 6 0 R >> >> /Contents ' . $contentObj . ' 0 R >>';
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
    $pdf .= 'xref' . "\n";
    $pdf .= '0 ' . ($maxObj + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxObj; $i++) {
        $off = $offsets[$i] ?? 0;
        $pdf .= sprintf('%010d 00000 n ', $off) . "\n";
    }
    $pdf .= 'trailer' . "\n";
    $pdf .= '<< /Size ' . ($maxObj + 1) . ' /Root 1 0 R >>' . "\n";
    $pdf .= 'startxref' . "\n";
    $pdf .= $xrefOffset . "\n";
    $pdf .= "%%EOF";

    $fileName = 'event-report-' . date('Ymd-His') . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename=' . $fileName);
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'clear_before') {
        $clearBeforeDate = trim($_POST['clear_before_date'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clearBeforeDate)) {
            header('Location: ?page=event&clear_error=1');
            exit;
        }

        try {
            $idsStmt = $pdo->prepare("SELECT id FROM bills WHERE type = 'wholesale' AND DATE(created_at) < ?");
            $idsStmt->execute([$clearBeforeDate]);
            $billIds = $idsStmt->fetchAll(PDO::FETCH_COLUMN);
            $clearedCount = count($billIds);

            if ($clearedCount > 0) {
                $pdo->beginTransaction();
                $placeholders = implode(',', array_fill(0, $clearedCount, '?'));

                $deleteItemsStmt = $pdo->prepare("DELETE FROM bill_items WHERE bill_id IN ($placeholders)");
                $deleteItemsStmt->execute($billIds);

                $deleteBillsStmt = $pdo->prepare("DELETE FROM bills WHERE id IN ($placeholders)");
                $deleteBillsStmt->execute($billIds);

                $pdo->commit();
            }

            header('Location: ?page=event&cleared=' . intval($clearedCount) . '&before=' . urlencode($clearBeforeDate));
            exit;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            header('Location: ?page=event&clear_error=1');
            exit;
        }
    } elseif ($action === 'store') {
        $items = $_POST['items'] ?? [];
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $event_date = trim($_POST['event_date'] ?? '');
        $event_address = trim($_POST['event_address'] ?? '');
        $event_notes = trim($_POST['event_notes'] ?? '');

        if ($customer_name === '' || $customer_phone === '' || $event_date === '' || $event_address === '') {
            $_SESSION['error_message'] = 'Customer Name, Customer Phone, Event Date, and Event Address are required.';
            header('Location: ?page=event&action=create');
            exit();
        }

        // Validate phone number: only 10 digits
        if ($customer_phone !== '') {
            $phone_digits = preg_replace('/\D/', '', $customer_phone);
            if (strlen($phone_digits) !== 10) {
                $_SESSION['error_message'] = 'Phone number must be exactly 10 digits';
                header('Location: ?page=event&action=create');
                exit();
            }
            $customer_phone = $phone_digits;
        }
        $discountPercent = floatval($_POST['discount_percentage'] ?? 0);
        $paid = floatval($_POST['paid_amount'] ?? 0);

        if (!empty($items)) {
            $subtotal = 0;
            foreach ($items as $item) {
                $subtotal += ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
            }
            $discountPercent = max(0, min($discountPercent, 100));
            $discount = ($subtotal * $discountPercent) / 100;
            $tax = ($subtotal - $discount) * (floatval($settings['tax_percentage'] ?? 0) / 100);
            $total = $subtotal - $discount + $tax;
            $paid = max(0, min($paid, $total));
            $status = 'paid';
            $payment_method = 'cash';

            if ($paid >= $total) {
                $status = 'paid';
            } elseif ($paid > 0) {
                $status = 'partial';
            } else {
                $status = 'pending';
            }

            $notesParts = [];
            if ($customer_name !== '') {
                $notesParts[] = 'Customer Name: ' . $customer_name;
            }
            if ($customer_phone !== '') {
                $notesParts[] = 'Customer Phone: ' . $customer_phone;
            }
            if ($event_date !== '') {
                $notesParts[] = 'Event Date: ' . $event_date;
            }
            if ($event_address !== '') {
                $notesParts[] = 'Event Address: ' . $event_address;
            }
            if ($event_notes !== '') {
                $notesParts[] = 'Notes: ' . $event_notes;
            }
            $notes = implode(' | ', $notesParts);

            $lastBill = $pdo->query("SELECT bill_number FROM bills WHERE type = 'wholesale' ORDER BY id DESC LIMIT 1")->fetch();
            $nextNum = $lastBill ? intval(substr($lastBill['bill_number'], -6)) + 1 : 1;
            $billNumber = ($settings['invoice_prefix'] ?? 'SRF') . '-E-' . str_pad($nextNum, 6, '0', STR_PAD_LEFT);

            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("INSERT INTO bills (bill_number, type, customer_id, user_id, subtotal, discount_amount, tax_amount, total_amount, paid_amount, payment_status, payment_method, notes) VALUES (?, 'wholesale', NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$billNumber, $_SESSION['user_id'], $subtotal, $discount, $tax, $total, $paid, $status, $payment_method, $notes]);
                $billId = $pdo->lastInsertId();

                $itemStmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");

                foreach ($items as $item) {
                    $itemTotal = ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
                    $itemStmt->execute([$billId, $item['product_id'], $item['quantity'], $item['price'], $item['discount'] ?? 0, $itemTotal]);
                }

                $pdo->commit();
                header("Location: ?page=event&action=view&id=$billId&success=1");
                exit;
            } catch (Exception $e) {
                $pdo->rollBack();
                $error = 'Error: ' . $e->getMessage();
            }
        }
    } elseif ($action === 'update') {
        $id = intval($_GET['id'] ?? 0);
        $items = $_POST['items'] ?? [];
        $customer_name = trim($_POST['customer_name'] ?? '');
        $customer_phone = trim($_POST['customer_phone'] ?? '');
        $event_date = trim($_POST['event_date'] ?? '');
        $event_address = trim($_POST['event_address'] ?? '');
        $event_notes = trim($_POST['event_notes'] ?? '');

        if ($customer_name === '' || $customer_phone === '' || $event_date === '' || $event_address === '') {
            $_SESSION['error_message'] = 'Customer Name, Customer Phone, Event Date, and Event Address are required.';
            header('Location: ?page=event&action=edit&id=' . $id);
            exit();
        }

        // Validate phone number: only 10 digits
        if ($customer_phone !== '') {
            $phone_digits = preg_replace('/\D/', '', $customer_phone);
            if (strlen($phone_digits) !== 10) {
                $_SESSION['error_message'] = 'Phone number must be exactly 10 digits';
                header('Location: index.php?page=event&action=edit&id=' . $id);
                exit();
            }
            $customer_phone = $phone_digits;
        }
        $discountPercent = floatval($_POST['discount_percentage'] ?? 0);
        $paid = floatval($_POST['paid_amount'] ?? 0);

        if ($id > 0 && !empty($items)) {
            $existingBillStmt = $pdo->prepare("SELECT id FROM bills WHERE id = ? AND type = 'wholesale'");
            $existingBillStmt->execute([$id]);
            $existingBill = $existingBillStmt->fetch();

            if ($existingBill) {
                $subtotal = 0;
                foreach ($items as $item) {
                    $subtotal += ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
                }
                $discountPercent = max(0, min($discountPercent, 100));
                $discount = ($subtotal * $discountPercent) / 100;
                $tax = ($subtotal - $discount) * (floatval($settings['tax_percentage'] ?? 0) / 100);
                $total = $subtotal - $discount + $tax;
                $paid = max(0, min($paid, $total));
                $payment_method = 'cash';

                if ($paid >= $total) {
                    $status = 'paid';
                } elseif ($paid > 0) {
                    $status = 'partial';
                } else {
                    $status = 'pending';
                }

                $notesParts = [];
                if ($customer_name !== '') {
                    $notesParts[] = 'Customer Name: ' . $customer_name;
                }
                if ($customer_phone !== '') {
                    $notesParts[] = 'Customer Phone: ' . $customer_phone;
                }
                if ($event_date !== '') {
                    $notesParts[] = 'Event Date: ' . $event_date;
                }
                if ($event_address !== '') {
                    $notesParts[] = 'Event Address: ' . $event_address;
                }
                if ($event_notes !== '') {
                    $notesParts[] = 'Notes: ' . $event_notes;
                }
                $notes = implode(' | ', $notesParts);

                $pdo->beginTransaction();
                try {
                    $pdo->prepare("DELETE FROM bill_items WHERE bill_id = ?")->execute([$id]);

                    $itemStmt = $pdo->prepare("INSERT INTO bill_items (bill_id, product_id, quantity, unit_price, discount, total) VALUES (?, ?, ?, ?, ?, ?)");

                    foreach ($items as $item) {
                        $itemTotal = ($item['quantity'] * $item['price']) - ($item['discount'] ?? 0);
                        $itemStmt->execute([$id, $item['product_id'], $item['quantity'], $item['price'], $item['discount'] ?? 0, $itemTotal]);
                    }

                    $updateStmt = $pdo->prepare("UPDATE bills SET customer_id = NULL, subtotal = ?, discount_amount = ?, tax_amount = ?, total_amount = ?, paid_amount = ?, payment_status = ?, payment_method = ?, notes = ? WHERE id = ? AND type = 'wholesale'");
                    $updateStmt->execute([$subtotal, $discount, $tax, $total, $paid, $status, $payment_method, $notes, $id]);

                    $pdo->commit();
                    header("Location: ?page=event&action=view&id=$id&updated=1");
                    exit;
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Error: ' . $e->getMessage();
                }
            }
        }
    }
}

include 'header.php';
?>

<?php if ($action === 'index'): ?>
<?php
$filterData = $getFilteredEventBills($_GET);
$bills = $filterData['bills'];
$filters = $filterData['filters'];
$reportFilter = $filters['report_filter'];
$paymentFilter = $filters['payment_filter'];
$eventFilter = $filters['event_filter'];
$balanceFilter = $filters['balance_filter'];
$billSearch = $filters['bill_search'];
$eventDateFrom = $filters['event_date_from'];
$eventDateTo = $filters['event_date_to'];
$minTotal = $filters['min_total'];
$maxTotal = $filters['max_total'];
$period = $_GET['period'] ?? '';
if (!in_array($period, ['today', 'month'], true)) {
    $period = '';
}
if ($period === 'today') {
    $todayDate = date('Y-m-d');
    $bills = array_values(array_filter($bills, function ($bill) use ($todayDate) {
        return isset($bill['created_at']) && date('Y-m-d', strtotime($bill['created_at'])) === $todayDate;
    }));
} elseif ($period === 'month') {
    $currentYearMonth = date('Y-m');
    $bills = array_values(array_filter($bills, function ($bill) use ($currentYearMonth) {
        return isset($bill['created_at']) && date('Y-m', strtotime($bill['created_at'])) === $currentYearMonth;
    }));
}
?>
<?php if (isset($_GET['cleared'])): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle me-2"></i>
    Cleared <?= intval($_GET['cleared']) ?> event bill(s) before <?= htmlspecialchars($_GET['before'] ?? '') ?>.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (isset($_GET['clear_error'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i>
    Failed to clear event bills. Please check the date and try again.
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<style>
    .bill-toolbar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }
    .bill-toolbar-right {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    .clear-date-form {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #fff;
        border: 1px solid #f3d1d6;
        border-radius: 12px;
        padding: 8px;
    }
    .clear-date-form .form-control {
        min-width: 170px;
    }
    .clear-date-form .btn {
        white-space: nowrap;
    }
    @media (max-width: 768px) {
        .bill-toolbar-right {
            width: 100%;
        }
        .clear-date-form {
            width: 100%;
        }
        .clear-date-form .form-control,
        .clear-date-form .btn,
        .bill-toolbar-right .dropdown,
        .bill-toolbar-right > a {
            width: 100%;
        }
    }
</style>

<div class="bill-toolbar mb-4">
    <h4 class="mb-0">Event Bills</h4>
    <div class="bill-toolbar-right">
        <div class="dropdown">
            <button class="btn btn-outline-primary dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="bi bi-download me-2"></i>Download Report
            </button>
            <div class="dropdown-menu dropdown-menu-end p-3" style="min-width: 360px;">
                <form method="GET" class="row g-2">
                    <input type="hidden" name="page" value="event">
                    <input type="hidden" name="action" value="download">
                    <div class="col-12">
                        <label class="form-label mb-1">Report Types</label>
                        <div class="border rounded p-2" style="max-height: 220px; overflow-y: auto;">
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_all" name="report_filter[]" value="all" <?= in_array('all', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_all">All Records</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_partial" name="report_filter[]" value="partial_payments" <?= in_array('partial_payments', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_partial">Partial Payments</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_pending" name="report_filter[]" value="pending_payments" <?= in_array('pending_payments', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_pending">Pending Payments</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_paid" name="report_filter[]" value="paid_bills" <?= in_array('paid_bills', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_paid">Paid Bills</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_upcoming" name="report_filter[]" value="upcoming_events" <?= in_array('upcoming_events', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_upcoming">Upcoming Events</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_today" name="report_filter[]" value="today_events" <?= in_array('today_events', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_today">Today's Events</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_past" name="report_filter[]" value="past_events" <?= in_array('past_events', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_past">Past Events</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_balance" name="report_filter[]" value="with_balance" <?= in_array('with_balance', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_balance">With Balance</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" id="rf_cleared" name="report_filter[]" value="cleared" <?= in_array('cleared', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_cleared">Cleared</label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="rf_nodate" name="report_filter[]" value="no_event_date" <?= in_array('no_event_date', $reportFilter, true) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="rf_nodate">Without Event Date</label>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-file-earmark-pdf me-2"></i>Download PDF</button>
                    </div>
                </form>
            </div>
        </div>
        <a href="?page=event&action=create" class="btn btn-primary"><i class="bi bi-plus-lg me-2"></i>New Event Bill</a>
    </div>
</div>

<?php if ($period === 'today' || $period === 'month'): ?>
<div class="alert alert-info d-flex justify-content-between align-items-center">
    <span>
        <i class="bi bi-funnel me-2"></i>
        Showing <?= $period === 'today' ? "today's" : "this month's" ?> event bills
    </span>
    <a href="?page=event" class="btn btn-sm btn-outline-info">Clear Filter</a>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <table class="table table-striped table-hover">
            <thead><tr><th>Bill No</th><th>Date</th><th>Event Date</th><th>Customer Name</th><th>Phone</th><th class="text-end">Total</th><th class="text-end">Advance Paid</th><th class="text-end">Balance</th><th>Status</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
                <?php foreach ($bills as $bill): ?>
                <tr>
                    <td><strong title="<?= htmlspecialchars($bill['bill_number']) ?>"><?= htmlspecialchars($formatEventBillNo($bill['bill_number'])) ?></strong></td>
                    <td><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></td>
                    <td><?= htmlspecialchars($bill['event_date'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($bill['customer_name'] ?: '-') ?></td>
                    <td><?= htmlspecialchars($bill['customer_phone'] ?: '-') ?></td>
                    <td class="text-end"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></td>
                    <td class="text-end text-success"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></td>
                    <td class="text-end <?= ($bill['total_amount'] - $bill['paid_amount']) > 0 ? 'text-danger fw-bold' : 'text-success' ?>"><?= $currency ?> <?= number_format($bill['total_amount'] - $bill['paid_amount'], 2) ?></td>
                    <td><span class="badge bg-<?= $bill['payment_status'] == 'paid' ? 'success' : ($bill['payment_status'] == 'partial' ? 'warning' : 'danger') ?>"><?= ucfirst($bill['payment_status']) ?></span></td>
                    <td class="text-end">
                        <a href="?page=event&action=view&id=<?= $bill['id'] ?>" class="btn btn-sm btn-info"><i class="bi bi-eye"></i></a>
                        <a href="?page=event&action=edit&id=<?= $bill['id'] ?>" class="btn btn-sm btn-warning"><i class="bi bi-pencil"></i></a>
                        <a href="?page=event&action=print&id=<?= $bill['id'] ?>" class="btn btn-sm btn-secondary" target="_blank"><i class="bi bi-printer"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($bills)): ?><tr><td colspan="10" class="text-center text-muted py-4">No event bills found</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="d-flex justify-content-end mt-3">
    <form method="POST" action="?page=event&action=clear_before" class="d-flex gap-2 align-items-center" data-confirm-message="CAUTION: This will permanently delete event bills before the selected date. This action cannot be undone. Continue?" data-confirm-title="Confirm Event Bill Deletion">
        <div class="clear-date-form">
            <input type="date" name="clear_before_date" class="form-control form-control-sm" required title="Delete bills before this date">
            <button type="submit" class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-2"></i>Clear Before Date</button>
        </div>
    </form>
</div>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<?php
$isEdit = $action === 'edit';
$editBill = null;
$editItems = [];
$customer_name_value = '';
$customer_phone_value = '';
$event_date_value = '';
$event_address_value = '';
$event_notes_value = '';
$discount_percentage_value = 0;
$paid_value = 0;

if ($isEdit) {
    $editId = intval($_GET['id'] ?? 0);
    $editBillStmt = $pdo->prepare("SELECT * FROM bills WHERE id = ? AND type = 'wholesale'");
    $editBillStmt->execute([$editId]);
    $editBill = $editBillStmt->fetch();

    if (!$editBill) {
        echo '<div class="alert alert-danger">Event bill not found</div>';
        include 'footer.php';
        exit;
    }

    $editItemsStmt = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ? ORDER BY bi.id ASC");
    $editItemsStmt->execute([$editId]);
    $editItems = $editItemsStmt->fetchAll();

    $subtotalForPercent = floatval($editBill['subtotal'] ?? 0);
    $discountAmountForPercent = floatval($editBill['discount_amount'] ?? 0);
    $discount_percentage_value = $subtotalForPercent > 0 ? ($discountAmountForPercent / $subtotalForPercent) * 100 : 0;
    $paid_value = floatval($editBill['paid_amount'] ?? 0);

    if (!empty($editBill['notes'])) {
        $noteParts = explode(' | ', $editBill['notes']);
        foreach ($noteParts as $notePart) {
            if (strpos($notePart, 'Customer Name: ') === 0) {
                $customer_name_value = trim(substr($notePart, 15));
            } elseif (strpos($notePart, 'Customer Phone: ') === 0) {
                $customer_phone_value = trim(substr($notePart, 16));
            } elseif (strpos($notePart, 'Event Date: ') === 0) {
                $event_date_value = trim(substr($notePart, 12));
            } elseif (strpos($notePart, 'Event Address: ') === 0) {
                $event_address_value = trim(substr($notePart, 15));
            } elseif (strpos($notePart, 'Notes: ') === 0) {
                $event_notes_value = trim(substr($notePart, 7));
            }
        }
    }
}

$products = $pdo->query("SELECT * FROM products WHERE is_active = 1 ORDER BY name")->fetchAll();
$categories = $pdo->query("SELECT * FROM categories WHERE is_active = 1 ORDER BY name")->fetchAll();

$initialItems = [];
if ($isEdit) {
    foreach ($editItems as $i => $item) {
        $initialItems[] = [
            'product_id' => intval($item['product_id']),
            'name' => $item['product_name'],
            'sku' => $item['sku'],
            'price' => floatval($item['unit_price']),
            'quantity' => intval($item['quantity']),
            'discount' => floatval($item['discount'])
        ];
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= $isEdit ? 'Edit Event Bill' : 'New Event Bill' ?></h4>
    <a href="?page=event" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
</div>

<?php if (!empty($_SESSION['error_message'])): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error_message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['error_message']); endif; ?>

<form method="POST" action="?page=event&action=<?= $isEdit ? 'update&id=' . intval($editBill['id']) : 'store' ?>" id="billForm">
    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-calendar-event me-2"></i>Event Details</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Customer Name</label>
                            <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name" value="<?= htmlspecialchars($customer_name_value) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Customer Phone</label>
                            <input type="text" name="customer_phone" class="form-control" placeholder="Enter 10-digit phone number" pattern="\d{10}" inputmode="numeric" value="<?= htmlspecialchars($customer_phone_value) ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Event Date</label>
                            <input type="date" name="event_date" class="form-control" value="<?= htmlspecialchars($event_date_value) ?>" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Event Address</label>
                            <input type="text" name="event_address" class="form-control" placeholder="Enter event address" value="<?= htmlspecialchars($event_address_value) ?>" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Notes</label>
                            <input type="text" name="event_notes" class="form-control" placeholder="Optional notes" value="<?= htmlspecialchars($event_notes_value) ?>">
                        </div>
                    </div>
                </div>
            </div>

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
                                <option value="<?= $p['id'] ?>" data-id="<?= $p['id'] ?>" data-category="<?= $p['category_id'] ?>" data-name="<?= htmlspecialchars($p['name']) ?>" data-sku="<?= htmlspecialchars($p['sku']) ?>" data-price="<?= $p['selling_price'] ?>">
                                    <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['sku']) ?>) - <?= $currency ?> <?= number_format($p['selling_price'], 2) ?>
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
                        <thead class="table-dark"><tr><th>#</th><th>Product</th><th style="width:80px">Qty</th><th style="width:100px">Price</th><th style="width:80px">Disc.</th><th style="width:100px">Total</th><th style="width:50px"></th></tr></thead>
                        <tbody id="itemsBody"><tr id="noItemsRow"><td colspan="7" class="text-center text-muted py-4">No items added</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header bg-primary text-white"><i class="bi bi-calculator me-2"></i>Bill Summary</div>
                <div class="card-body">
                    <div class="d-flex justify-content-between mb-2"><span>Subtotal:</span><span id="subtotal"><?= $currency ?> 0.00</span></div>
                    <div class="d-flex justify-content-between mb-2 align-items-center">
                        <span>Discount (%):</span>
                        <div class="input-group" style="width:120px"><input type="number" name="discount_percentage" id="discountPercent" class="form-control form-control-sm" value="<?= number_format($discount_percentage_value, 2, '.', '') ?>" min="0" max="100" step="0.01"><span class="input-group-text">%</span></div>
                    </div>
                    <div class="d-flex justify-content-between mb-2"><span>Tax (<?= $settings['tax_percentage'] ?? 0 ?>%):</span><span id="taxAmount"><?= $currency ?> 0.00</span></div>
                    <hr>
                    <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary" id="grandTotal"><?= $currency ?> 0.00</strong></div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Payment</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Advance Payment (Cash)</label>
                        <div class="input-group">
                            <span class="input-group-text"><?= $currency ?></span>
                            <input type="number" name="paid_amount" id="paidAmount" class="form-control" value="<?= number_format($paid_value, 2, '.', '') ?>" min="0" step="0.01">
                        </div>
                        <small class="text-muted">Leave as 0 to save as pending.</small>
                    </div>
                    <div class="d-flex justify-content-between"><span>Balance Due:</span><strong class="text-danger" id="balanceDue"><?= $currency ?> 0.00</strong></div>
                    <button type="button" class="btn btn-outline-primary w-100 mt-3" id="payFullBtn"><i class="bi bi-cash me-2"></i>Pay Full Amount</button>
                </div>
            </div>

            <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary btn-lg" id="saveBillBtn" disabled><i class="bi bi-check-lg me-2"></i><?= $isEdit ? 'Update Event Bill' : 'Save Event Bill' ?></button>
            </div>
        </div>
    </div>
</form>

<script>
const currency = '<?= $currency ?>';
const taxRate = <?= $settings['tax_percentage'] ?? 0 ?>;
let items = [];
let itemIndex = 0;
const initialItems = <?= json_encode($initialItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

const categorySelect = document.getElementById('categorySelect');
const productSelect = document.getElementById('productSelect');
const productOptions = Array.from(productSelect.querySelectorAll('option[data-id]')).map(option => ({
    id: option.value,
    category: option.dataset.category || '',
    name: option.dataset.name,
    sku: option.dataset.sku,
    price: parseFloat(option.dataset.price),
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
            option.textContent = product.label;
            productSelect.appendChild(option);
        });
});

productSelect.addEventListener('change', function() {
    if (this.value) {
        const opt = this.selectedOptions[0];
        addItem({ id: this.value, name: opt.dataset.name, sku: opt.dataset.sku, price: parseFloat(opt.dataset.price) });
        this.value = '';
    }
});

function addItem(product) {
    const existing = items.findIndex(i => i.product_id == product.id);
    if (existing >= 0) {
        const row = document.querySelector(`tr[data-index="${items[existing].index}"]`);
        const qtyInput = row.querySelector('.qty-input');
        qtyInput.value = (parseInt(qtyInput.value, 10) || 0) + 1;
        items[existing].quantity++;
        updateRowTotal(items[existing].index);
        return;
    }

    document.getElementById('noItemsRow').style.display = 'none';
    const item = { index: itemIndex, product_id: product.id, name: product.name, sku: product.sku, price: product.price, quantity: 1, discount: 0 };
    items.push(item);

    const row = document.createElement('tr');
    row.dataset.index = itemIndex;
    row.innerHTML = `<td>${itemIndex + 1}</td><td><strong>${product.name}</strong><br><small class="text-muted">${product.sku}</small><input type="hidden" name="items[${itemIndex}][product_id]" value="${product.id}"></td><td><input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm qty-input" value="1" min="1" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][price]" class="form-control form-control-sm price-input" value="${product.price.toFixed(2)}" min="0" step="0.01" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][discount]" class="form-control form-control-sm discount-input" value="0" min="0" step="0.01" data-index="${itemIndex}"></td><td class="row-total">${currency} ${product.price.toFixed(2)}</td><td><button type="button" class="btn btn-sm btn-danger remove-item" data-index="${itemIndex}"><i class="bi bi-trash"></i></button></td>`;
    document.getElementById('itemsBody').appendChild(row);
    itemIndex++;
    updateTotals();
}

if (Array.isArray(initialItems) && initialItems.length > 0) {
    document.getElementById('noItemsRow').style.display = 'none';
    initialItems.forEach(function(product) {
        const item = {
            index: itemIndex,
            product_id: product.product_id,
            name: product.name,
            sku: product.sku,
            price: parseFloat(product.price),
            quantity: parseInt(product.quantity, 10),
            discount: parseFloat(product.discount)
        };
        items.push(item);

        const row = document.createElement('tr');
        row.dataset.index = itemIndex;
        row.innerHTML = `<td>${itemIndex + 1}</td><td><strong>${item.name}</strong><br><small class="text-muted">${item.sku}</small><input type="hidden" name="items[${itemIndex}][product_id]" value="${item.product_id}"></td><td><input type="number" name="items[${itemIndex}][quantity]" class="form-control form-control-sm qty-input" value="${item.quantity}" min="1" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][price]" class="form-control form-control-sm price-input" value="${item.price.toFixed(2)}" min="0" step="0.01" data-index="${itemIndex}"></td><td><input type="number" name="items[${itemIndex}][discount]" class="form-control form-control-sm discount-input" value="${item.discount.toFixed(2)}" min="0" step="0.01" data-index="${itemIndex}"></td><td class="row-total">${currency} ${((item.quantity * item.price) - item.discount).toFixed(2)}</td><td><button type="button" class="btn btn-sm btn-danger remove-item" data-index="${itemIndex}"><i class="bi bi-trash"></i></button></td>`;
        document.getElementById('itemsBody').appendChild(row);
        itemIndex++;
    });
}

document.getElementById('itemsBody').addEventListener('input', function(e) {
    if (e.target.classList.contains('qty-input') || e.target.classList.contains('price-input') || e.target.classList.contains('discount-input')) {
        updateRowTotal(parseInt(e.target.dataset.index, 10));
    }
});

document.getElementById('itemsBody').addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        const index = parseInt(e.target.closest('.remove-item').dataset.index, 10);
        document.querySelector(`tr[data-index="${index}"]`).remove();
        items = items.filter(i => i.index !== index);
        if (items.length === 0) document.getElementById('noItemsRow').style.display = '';
        updateTotals();
    }
});

function updateRowTotal(index) {
    const row = document.querySelector(`tr[data-index="${index}"]`);
    const qty = parseInt(row.querySelector('.qty-input').value, 10) || 0;
    const price = parseFloat(row.querySelector('.price-input').value) || 0;
    const discount = parseFloat(row.querySelector('.discount-input').value) || 0;
    row.querySelector('.row-total').textContent = `${currency} ${((qty * price) - discount).toFixed(2)}`;
    const idx = items.findIndex(i => i.index === index);
    if (idx >= 0) {
        items[idx].quantity = qty;
        items[idx].price = price;
        items[idx].discount = discount;
    }
    updateTotals();
}

function updateTotals() {
    let subtotal = 0;
    items.forEach(item => { subtotal += (item.quantity * item.price) - item.discount; });
    const discountPercent = Math.min(100, Math.max(0, parseFloat(document.getElementById('discountPercent').value) || 0));
    const discount = (subtotal * discountPercent) / 100;
    const tax = ((subtotal - discount) * taxRate) / 100;
    const grandTotal = subtotal - discount + tax;
    const paidInput = document.getElementById('paidAmount');
    let paidAmount = parseFloat(paidInput.value) || 0;

    if (paidAmount < 0) {
        paidAmount = 0;
    }
    if (paidAmount > grandTotal) {
        paidAmount = grandTotal;
        paidInput.value = grandTotal.toFixed(2);
    }

    const balanceDue = grandTotal - paidAmount;

    document.getElementById('subtotal').textContent = `${currency} ${subtotal.toFixed(2)}`;
    document.getElementById('taxAmount').textContent = `${currency} ${tax.toFixed(2)}`;
    document.getElementById('grandTotal').textContent = `${currency} ${grandTotal.toFixed(2)}`;
    document.getElementById('balanceDue').textContent = `${currency} ${balanceDue.toFixed(2)}`;
    document.getElementById('saveBillBtn').disabled = items.length === 0;
}

document.getElementById('discountPercent').addEventListener('input', updateTotals);
document.getElementById('paidAmount').addEventListener('input', updateTotals);
document.getElementById('payFullBtn').addEventListener('click', function() {
    let subtotal = 0;
    items.forEach(item => { subtotal += (item.quantity * item.price) - item.discount; });
    const discountPercent = Math.min(100, Math.max(0, parseFloat(document.getElementById('discountPercent').value) || 0));
    const discount = (subtotal * discountPercent) / 100;
    const tax = ((subtotal - discount) * taxRate) / 100;
    document.getElementById('paidAmount').value = (subtotal - discount + tax).toFixed(2);
    updateTotals();
});

updateTotals();
</script>

<?php elseif ($action === 'view'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.*, u.name as user_name FROM bills b LEFT JOIN users u ON b.user_id = u.id WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo '<div class="alert alert-danger">Bill not found</div>'; include 'footer.php'; exit; }

$billDiscountPercent = floatval($bill['subtotal']) > 0 ? (floatval($bill['discount_amount']) / floatval($bill['subtotal'])) * 100 : 0;
$billEventDate = $extractEventDateFromNotes($bill['notes'] ?? '') ?: '-';
$billCustomerName = $extractCustomerNameFromNotes($bill['notes'] ?? '') ?: '-';
$billCustomerPhone = $extractCustomerPhoneFromNotes($bill['notes'] ?? '') ?: '-';

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
?>

<?php if (isset($_GET['success'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Event bill created successfully!</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success"><i class="bi bi-check-circle me-2"></i>Event bill updated successfully!</div><?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">Event Bill - <?= htmlspecialchars($formatEventBillNo($bill['bill_number'])) ?></h4>
    <div>
        <a href="?page=event&action=print&id=<?= $bill['id'] ?>" class="btn btn-primary" target="_blank"><i class="bi bi-printer me-2"></i>Print</a>
        <a href="?page=event&action=edit&id=<?= $bill['id'] ?>" class="btn btn-warning"><i class="bi bi-pencil me-2"></i>Edit</a>
        <a href="?page=event" class="btn btn-secondary"><i class="bi bi-arrow-left me-2"></i>Back</a>
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
                    <div class="col-md-3"><label class="text-muted small">Type</label><p><span class="badge bg-primary">Event</span></p></div>
                    <div class="col-md-3"><label class="text-muted small">Date</label><p><?= date('d M Y H:i', strtotime($bill['created_at'])) ?></p></div>
                    <div class="col-md-3"><label class="text-muted small">Event Date</label><p><?= htmlspecialchars($billEventDate) ?></p></div>
                </div>
                <div class="row mt-1">
                    <div class="col-md-6"><label class="text-muted small">Customer Name</label><p><?= htmlspecialchars($billCustomerName) ?></p></div>
                    <div class="col-md-6"><label class="text-muted small">Customer Phone</label><p><?= htmlspecialchars($billCustomerPhone) ?></p></div>
                </div>
                <?php if (!empty($bill['notes'])): ?>
                <div class="mt-2 p-3 bg-light rounded border">
                    <label class="text-muted small">Event Details</label>
                    <div><?= htmlspecialchars($bill['notes']) ?></div>
                </div>
                <?php endif; ?>
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
                <div class="d-flex justify-content-between mb-2"><span>Discount (<?= number_format($billDiscountPercent, 2) ?>%):</span><span class="text-danger">- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between mb-2"><span>Tax:</span><span><?= $currency ?> <?= number_format($bill['tax_amount'], 2) ?></span></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><strong class="fs-5">Grand Total:</strong><strong class="fs-5 text-primary"><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></strong></div>
                <hr>
                <div class="d-flex justify-content-between mb-2"><span>Advance Paid:</span><span class="text-success"><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
                <div class="d-flex justify-content-between"><strong>Balance Due:</strong><strong class="<?= ($bill['total_amount'] - $bill['paid_amount']) > 0 ? 'text-danger' : 'text-success' ?>"><?= $currency ?> <?= number_format($bill['total_amount'] - $bill['paid_amount'], 2) ?></strong></div>
            </div>
        </div>
    </div>
</div>

<?php elseif ($action === 'print'): ?>
<?php
$id = intval($_GET['id'] ?? 0);
$bill = $pdo->prepare("SELECT b.* FROM bills b WHERE b.id = ?");
$bill->execute([$id]);
$bill = $bill->fetch();
if (!$bill) { echo 'Bill not found'; exit; }

$billDiscountPercent = floatval($bill['subtotal']) > 0 ? (floatval($bill['discount_amount']) / floatval($bill['subtotal'])) * 100 : 0;
$billEventDate = $extractEventDateFromNotes($bill['notes'] ?? '') ?: '-';
$billCustomerName = $extractCustomerNameFromNotes($bill['notes'] ?? '') ?: '-';
$billCustomerPhone = $extractCustomerPhoneFromNotes($bill['notes'] ?? '') ?: '-';
$billEventAddress = $extractEventAddressFromNotes($bill['notes'] ?? '') ?: '-';

$items = $pdo->prepare("SELECT bi.*, p.name as product_name, p.sku FROM bill_items bi LEFT JOIN products p ON bi.product_id = p.id WHERE bi.bill_id = ?");
$items->execute([$id]);
$items = $items->fetchAll();
$brandLogoPath = 'WhatsApp Image 2026-03-30 at 21.39.04.jpeg';
$brandLogoSrc = str_replace(' ', '%20', $brandLogoPath);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Event Invoice - <?= htmlspecialchars($bill['bill_number']) ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; padding: 20px; }
        .invoice { max-width: 800px; margin: 0 auto; }
        .header { border-bottom: 2px solid #333; padding-bottom: 12px; margin-bottom: 20px; }
        .letterhead { display: grid; grid-template-columns: 130px 1fr 130px; column-gap: 16px; align-items: center; }
        .logo-wrap img { width: 130px; height: 130px; object-fit: cover; border-radius: 50%; border: 1px solid #444; }
        .logo-spacer { width: 130px; height: 130px; visibility: hidden; }
        .brand-block { flex: 1; text-align: center; }
        .brand-title { font-size: 40px; font-weight: 800; letter-spacing: 1px; line-height: 1.05; }
        .brand-line { font-size: 16px; line-height: 1.3; margin-top: 3px; }
        .reg-line { margin-top: 8px; font-weight: 600; text-align: center; }
        .invoice-tag { margin-top: 10px; display: inline-block; background: #333; color: #fff; padding: 4px 14px; font-weight: bold; }
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
            <div class="letterhead">
                <div class="logo-wrap"><img src="<?= htmlspecialchars($brandLogoSrc) ?>" alt="Sriram Fireworks Logo"></div>
                <div class="brand-block">
                    <div class="brand-title">SRIRAM FIREWORKS</div>
                    <div class="brand-line">No. 319, Galmankada, Kimbulapitiya.</div>
                    <div class="brand-line">Prop : K.S.S.K. Fernando. Tel : 077 877 92 71</div>
                    <div class="brand-line reg-line">Reg : No WAA/4327</div>
                </div>
                <div class="logo-spacer" aria-hidden="true"></div>
            </div>
            <div style="text-align:center"><span class="invoice-tag">EVENT INVOICE</span></div>
        </div>

        <div style="display:flex; justify-content:space-between; margin-bottom:20px">
            <div><strong>Event Date:</strong> <?= htmlspecialchars($billEventDate) ?><br><strong>Customer:</strong> <?= htmlspecialchars($billCustomerName) ?><br><strong>Phone:</strong> <?= htmlspecialchars($billCustomerPhone) ?><br><strong>Address:</strong> <?= htmlspecialchars($billEventAddress) ?></div>
            <div style="text-align:right"><strong>Invoice:</strong> <?= htmlspecialchars($bill['bill_number']) ?><br><strong>Date:</strong> <?= date('d M Y', strtotime($bill['created_at'])) ?><br><strong>Payment:</strong> <?= $bill['payment_status'] === 'paid' ? 'Cash (Full)' : 'Cash (Advance)' ?></div>
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
            <?php if (floatval($bill['discount_amount']) > 0): ?>
            <div class="totals-row"><span>Discount (<?= number_format($billDiscountPercent, 2) ?>%):</span><span>- <?= $currency ?> <?= number_format($bill['discount_amount'], 2) ?></span></div>
            <?php endif; ?>
            <div class="totals-row grand-total"><span>Grand Total:</span><span><?= $currency ?> <?= number_format($bill['total_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Advance Paid:</span><span><?= $currency ?> <?= number_format($bill['paid_amount'], 2) ?></span></div>
            <div class="totals-row"><span>Balance Due:</span><span><?= $currency ?> <?= number_format($bill['total_amount'] - $bill['paid_amount'], 2) ?></span></div>
        </div>

        <div class="footer"><p>Thank you for your business!</p></div>
        <div class="no-print" style="text-align:center; margin-top:20px"><button onclick="window.print()" style="padding:10px 30px; background:#333; color:white; border:none; border-radius:5px; cursor:pointer">Print Invoice</button></div>
    </div>
</body>
</html>
<?php exit; endif; ?>

<?php include 'footer.php'; ?>
