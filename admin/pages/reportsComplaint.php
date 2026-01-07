<?php
// Database connection
include '../includes/conn.php'; 
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Check if this is an export request
if (isset($_GET['export']) && $_GET['export'] == 'true') {
    exportComplaintReport();
    exit;
}

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // Start of current month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';
$grade_section = isset($_GET['grade_section']) ? $_GET['grade_section'] : '';

// Get selected month from GET parameter (for backward compatibility)
$selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// Get all grade sections for filter dropdown
$all_grades_sql = "SELECT DISTINCT grade_section FROM clinic_records WHERE grade_section IS NOT NULL AND grade_section != '' ORDER BY grade_section ASC";
$all_grades_result = $conn->query($all_grades_sql);

// Pagination parameters for detailed records
// FIX: Capture per_page parameter from URL
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($records_per_page, [10, 25, 50, 100])) {
    $records_per_page = 10; // Default to 10 if invalid value
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// =======================
// COMPLAINT REPORT QUERY (using date range)
// =======================
$complaint_sql = "
    SELECT 
        complaint,
        COUNT(*) AS total_cases
    FROM clinic_records
    WHERE complaint IS NOT NULL AND complaint != ''
    AND date BETWEEN ? AND ?
";

$complaint_params = array($start_date, $end_date);
$complaint_types = "ss";

if (!empty($grade_section)) {
    $complaint_sql .= " AND grade_section = ?";
    $complaint_params[] = $grade_section;
    $complaint_types .= "s";
}

$complaint_sql .= " GROUP BY complaint ORDER BY total_cases DESC";

$complaint_stmt = $conn->prepare($complaint_sql);
$complaint_stmt->bind_param($complaint_types, ...$complaint_params);
$complaint_stmt->execute();
$complaint_result = $complaint_stmt->get_result();

// =======================
// DETAILED RECORDS QUERY WITH PAGINATION
// =======================
// First get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM clinic_records
    WHERE date BETWEEN ? AND ?
";

$count_params = array($start_date, $end_date);
$count_types = "ss";

if (!empty($grade_section)) {
    $count_sql .= " AND grade_section = ?";
    $count_params[] = $grade_section;
    $count_types .= "s";
}

$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_records = $total_row['total'] ?? 0;
$count_stmt->close();

// Calculate total pages
$total_pages = ceil($total_records / $records_per_page);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

// Now get paginated results
$detailed_sql = "
    SELECT 
        student_id,
        name,
        grade_section,
        complaint,
        treatment,
        date,
        time
    FROM clinic_records
    WHERE date BETWEEN ? AND ?
";

$detailed_params = array($start_date, $end_date);
$detailed_types = "ss";

if (!empty($grade_section)) {
    $detailed_sql .= " AND grade_section = ?";
    $detailed_params[] = $grade_section;
    $detailed_types .= "s";
}

$detailed_sql .= " ORDER BY date DESC, time DESC LIMIT ? OFFSET ?";
$detailed_params[] = $records_per_page;
$detailed_params[] = $offset;
$detailed_types .= "ii";

$detailed_stmt = $conn->prepare($detailed_sql);
$detailed_stmt->bind_param($detailed_types, ...$detailed_params);
$detailed_stmt->execute();
$detailed_result = $detailed_stmt->get_result();

// Calculate starting number for current page
$start_number = ($page - 1) * $records_per_page + 1;

// =======================
// EXPORT FUNCTION
// =======================
function exportComplaintReport() {
    global $conn;
    
    $selected_month = isset($_GET['month']) ? $_GET['month'] : date('Y-m');
    
    $sql = "
        SELECT 
            complaint,
            COUNT(*) AS total_cases
        FROM clinic_records
        WHERE complaint IS NOT NULL AND complaint != ''
        AND DATE_FORMAT(date, '%Y-%m') = ?
        GROUP BY complaint
        ORDER BY total_cases DESC
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $selected_month);
    $stmt->execute();
    $result = $stmt->get_result();
    
    // Calculate total cases
    $total_cases = 0;
    if ($result && $result->num_rows > 0) {
        $result->data_seek(0);
        while ($row = $result->fetch_assoc()) {
            $total_cases += $row['total_cases'];
        }
        $result->data_seek(0);
    }
    
    // Headers
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="monthly_complaint_report_' . $selected_month . '.xls"');
    
    echo "<html>";
    echo "<meta http-equiv=\"Content-Type\" content=\"text/html; charset=utf-8\">";
    echo "<table border='1'>";
    echo "<tr><th colspan='4' style='background:#0066cc;color:white;font-size:16px;padding:10px;'>MONTHLY COMPLAINT ANALYSIS REPORT</th></tr>";
    echo "<tr><th colspan='4' style='padding:8px;'>Month: " . date('F Y', strtotime($selected_month . '-01')) . "</th></tr>";
    echo "<tr><th colspan='4' style='padding:8px;'>Generated: " . date('F d, Y h:i A') . "</th></tr>";
    echo "<tr><th colspan='4' style='padding:8px;'>Total Cases: " . $total_cases . "</th></tr>";
    echo "<tr><td colspan='4'></td></tr>";
    echo "<tr style='background:#f2f2f2;'><th>#</th><th>Complaint Type</th><th>Total Cases</th><th>Percentage</th></tr>";
    
    if ($result && $result->num_rows > 0) {
        $count = 1;
        while ($row = $result->fetch_assoc()) {
            $percentage = $total_cases > 0 ? round(($row['total_cases'] / $total_cases) * 100, 2) : 0;
            echo "<tr>";
            echo "<td style='padding:6px;border:1px solid #000;'>" . $count++ . "</td>";
            echo "<td style='padding:6px;border:1px solid #000;'>" . htmlspecialchars($row['complaint']) . "</td>";
            echo "<td style='padding:6px;border:1px solid #000;'>" . $row['total_cases'] . "</td>";
            echo "<td style='padding:6px;border:1px solid #000;'>" . $percentage . "%</td>";
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='4' style='padding:10px;text-align:center;'>No data available for selected month</td></tr>";
    }
    
    echo "</table></html>";
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Complaint Report</title>
    <link rel="stylesheet" href="../assets/css/reports.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Custom styles for date range filter */
        .date-range-filter {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .date-group {
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 200px;
        }
        
        .date-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #4b5563;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .date-group input,
        .date-group select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            width: 100%;
            transition: border-color 0.3s;
        }
        
        .date-group input:focus,
        .date-group select:focus {
            outline: none;
            border-color: #4361ee;
        }
        
        .filter-actions {
            display: flex;
            gap: 10px;
            margin-top: 24px;
        }
        
        .filter-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
        }
        
        .btn-apply {
            background: #4361ee;
            color: white;
        }
        
        .btn-apply:hover {
            background: #3a56d4;
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
        }
        
        .btn-reset:hover {
            background: #5a6268;
        }
        
        /* Table controls */
        .table-controls {
            background: #f8f9fa;
            padding: 15px 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-section {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            padding: 8px 12px;
            border: 2px solid #ced4da;
            border-radius: 6px;
            min-width: 250px;
            font-size: 14px;
        }
        
        .grade-section-select {
            padding: 8px 12px;
            border: 2px solid #ced4da;
            border-radius: 6px;
            font-size: 14px;
            min-width: 200px;
            background: white;
            cursor: pointer;
            transition: border-color 0.3s;
        }
        
        .grade-section-select:hover {
            border-color: #4361ee;
        }
        
        .grade-section-select:focus {
            outline: none;
            border-color: #4361ee;
        }
        
        /* Keep existing styles */
        .table-container {
            margin-bottom: 30px;
            min-height: 500px;
            display: flex;
            flex-direction: column;
        }
        
        .table-wrapper {
            flex: 1;
            min-height: 400px;
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #e0e0e0;
            border-radius: 0 0 8px 8px;
            background: white;
        }
        
        .content {
            display: grid;
            grid-template-columns: 1fr;
            gap: 30px;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        thead {
            position: sticky;
            top: 0;
            z-index: 10;
        }
        
        th {
            background-color: #4361ee;
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            border: none;
        }
        
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #eef0f3;
            vertical-align: middle;
        }
        
        tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .stats-cards {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }
        
        .stat-card {
            flex: 1;
            background: linear-gradient(135deg, #ffffffff 0%, #ffffffff 100%);
            color: white;
            padding: 25px 20px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(67, 97, 238, 0.2);
            text-align: center;
        }
        
        .stat-card .number {
            font-size: 42px;
            font-weight: bold;
            margin-bottom: 8px;
            color: #4361ee;
        }
        
        .stat-card .label {
            font-size: 16px;
            opacity: 0.9;
            color: #666;
        }
        
        .section-title {
            background: #f8fafc;
            padding: 18px 20px;
            border-radius: 8px 8px 0 0;
            border-bottom: 2px solid #4361ee;
            font-size: 18px;
            font-weight: 600;
            color: #2d3748;
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0;
        }
        
        .controls {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .btn-export {
            background: #10b981;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }
        
        .btn-export:hover {
            background: #0da271;
        }
        
        .main-content {
            padding: 25px;
        }
        
        .container {
            max-width: 1800px;
            margin: 0 auto;
        }
        
        .no-data {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 350px;
            color: #6c757d;
        }
        
        .no-data i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #d1d5db;
        }
        
        .no-data h3 {
            margin-bottom: 10px;
            color: #4b5563;
        }
        
        .percentage-bar {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .bar-container {
            flex: 1;
            height: 20px;
            background: #e5e7eb;
            border-radius: 10px;
            overflow: hidden;
        }
        
        .bar-fill {
            height: 100%;
            background: linear-gradient(90deg, #4361ee, #3a0ca3);
            border-radius: 10px;
        }
        
        .case-count {
            background: #4361ee;
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-weight: 600;
            font-size: 14px;
        }
        
        .table-actions {
            display: flex;
            gap: 10px;
        }
        
        .action-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        
        .action-btn.print {
            background: #3b82f6;
            color: white;
        }
        
        .action-btn.print:hover {
            background: #2563eb;
        }
        
        .header {
            background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.2);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header p {
            font-size: 16px;
            opacity: 0.9;
            margin-bottom: 20px;
        }
        
        .month-display {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
        }
        
        .month-display h2 {
            color: #4361ee;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 22px;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 25px;
            gap: 10px;
        }
        
        .pagination-btn {
            padding: 8px 16px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            transition: all 0.3s;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        
        .pagination-btn:hover:not(.disabled) {
            background: #4361ee;
            color: white;
            border-color: #4361ee;
        }
        
        .pagination-btn.disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .page-numbers {
            display: flex;
            gap: 5px;
        }
        
        .page-number {
            padding: 8px 12px;
            border: 2px solid #e0e0e0;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 600;
            min-width: 40px;
            text-align: center;
            transition: all 0.3s;
        }
        
        .page-number:hover {
            background: #f0f4ff;
            border-color: #4361ee;
        }
        
        .page-number.active {
            background: #4361ee;
            color: white;
            border-color: #4361ee;
        }
        
        .records-per-page {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 14px;
            margin-left: auto;
        }
        
        .records-per-page select {
            padding: 5px 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background: white;
        }
        
        .pagination-info {
            text-align: center;
            margin-top: 10px;
            color: #666;
            font-size: 14px;
        }
        
        /* Table info */
        .table-info {
            font-size: 14px;
            color: #4361ee;
            background: #f0f4ff;
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #dbe4ff;
            font-weight: 600;
        }
        
        /* Column widths for detailed table */
        #detailedTable th:nth-child(1), #detailedTable td:nth-child(1) { width: 5%; }
        #detailedTable th:nth-child(2), #detailedTable td:nth-child(2) { width: 15%; }
        #detailedTable th:nth-child(3), #detailedTable td:nth-child(3) { width: 20%; }
        #detailedTable th:nth-child(4), #detailedTable td:nth-child(4) { width: 15%; }
        #detailedTable th:nth-child(5), #detailedTable td:nth-child(5) { width: 20%; }
        #detailedTable th:nth-child(6), #detailedTable td:nth-child(6) { width: 20%; }
        #detailedTable th:nth-child(7), #detailedTable td:nth-child(7) { width: 10%; }
        #detailedTable th:nth-child(8), #detailedTable td:nth-child(8) { width: 10%; }
        
        /* Print Whole Page Button */
        .btn-print-whole {
            background: #f59e0b;
            color: white;
            border: none;
            padding: 12px 24px;
            border-radius: 6px;
            cursor: pointer;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: background 0.3s;
        }
        
        .btn-print-whole:hover {
            background: #d97706;
        }
        
        /* Hide elements during print */
        @media print {
            .no-print {
                display: none !important;
            }
            
            body * {
                visibility: hidden;
            }
            
            .print-section, .print-section * {
                visibility: visible;
            }
            
            .print-section {
                position: absolute;
                left: 0;
                top: 0;
                width: 100%;
            }
            
            table {
                page-break-inside: auto;
            }
            
            tr {
                page-break-inside: avoid;
                page-break-after: auto;
            }
            
            thead {
                display: table-header-group;
            }
        }
    </style>
</head>
<body>
    <!-- Main Content Area with sidebar offset -->
    <div class="main-content">
        <div class="container">
            <!-- Header -->
            <div class="header no-print">
                <h1>
                    <i class="fa-solid fa-chart-column"></i>
                    Monthly Complaint Report
                </h1>
                <p>Analysis of patient complaints by month</p>
                <div class="table-actions">
                    <button class="action-btn print" onclick="printWholePage()">
                        <i class="fas fa-print"></i> Print Whole Page
                    </button>
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="date-range-filter no-print">
                <div class="date-group">
                    <label for="startDate">
                        <i class="fas fa-calendar-day"></i>
                        Start Date
                    </label>
                    <input type="date" id="startDate" name="start_date" value="<?php echo $start_date; ?>">
                </div>
                
                <div class="date-group">
                    <label for="endDate">
                        <i class="fas fa-calendar-day"></i>
                        End Date
                    </label>
                    <input type="date" id="endDate" name="end_date" value="<?php echo $end_date; ?>">
                </div>
                
                <div class="date-group">
                    <label for="reportType">
                        <i class="fas fa-chart-bar"></i>
                        Report Type
                    </label>
                    <select id="reportType" name="report_type">
                        <option value="weekly" <?php echo $report_type == 'weekly' ? 'selected' : ''; ?>>Weekly Analysis</option>
                        <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly Analysis</option>
                        <option value="yearly" <?php echo $report_type == 'yearly' ? 'selected' : ''; ?>>Yearly Analysis</option>
                    </select>
                </div>
                
                <div class="filter-actions">
                    <button class="filter-btn btn-apply" onclick="applyDateFilter()">
                        <i class="fas fa-filter"></i>
                        Apply Filter
                    </button>
                    <button class="filter-btn btn-reset" onclick="resetDateFilter()">
                        <i class="fas fa-redo"></i>
                        Reset
                    </button>
                </div>
            </div>

            <!-- Print Section (visible only when printing) -->
            <div class="print-section" style="display: none;">
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #4361ee; margin-bottom: 5px;">
                        <i class="fa-solid fa-chart-column"></i>
                        Monthly Complaint Report
                    </h1>
                    <p style="color: #666; margin-bottom: 20px;">Analysis of patient complaints by month</p>
                    
                    <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h2 style="color: #4361ee; margin-bottom: 10px; font-size: 18px;">
                            <i class="fas fa-calendar-week"></i>
                            Analysis for <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                            <?php if (!empty($grade_section)): ?>
                                <span style="font-size: 14px; color: #666;">
                                    (Filtered by: <?php echo htmlspecialchars($grade_section); ?>)
                                </span>
                            <?php endif; ?>
                        </h2>
                        
                        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 15px;">
                            <?php 
                            $total_cases = 0;
                            $complaint_types = 0;
                            if ($complaint_result && $complaint_result->num_rows > 0) {
                                $complaint_types = $complaint_result->num_rows;
                                $complaint_result->data_seek(0);
                                while ($row = $complaint_result->fetch_assoc()) {
                                    $total_cases += $row['total_cases'];
                                }
                                $complaint_result->data_seek(0);
                            }
                            ?>
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;"><?php echo $complaint_types; ?></div>
                                <div style="font-size: 12px; color: #666;">Complaint Types</div>
                            </div>
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;"><?php echo $total_cases; ?></div>
                                <div style="font-size: 12px; color: #666;">Total Cases</div>
                            </div>
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;"><?php echo $total_records; ?></div>
                                <div style="font-size: 12px; color: #666;">Total Records</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: left; color: #666; font-size: 12px; margin-bottom: 20px;">
                        Generated: <?php echo date('F d, Y h:i A'); ?><br>
                        Report Type: <?php echo ucfirst($report_type); ?> Analysis
                    </div>
                </div>
            </div>

            <!-- Month Display -->
            <div class="month-display no-print">
                <h2>
                    <i class="fas fa-calendar-week"></i>
                    Analysis for <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                    <?php if (!empty($grade_section)): ?>
                        <span style="font-size: 16px; color: #666; margin-left: 10px;">
                            (Filtered by: <?php echo htmlspecialchars($grade_section); ?>)
                        </span>
                    <?php endif; ?>
                </h2>
                
                <!-- Stats Cards -->
                <div class="stats-cards">
                    <?php 
                    $total_cases = 0;
                    $complaint_types = 0;
                    if ($complaint_result && $complaint_result->num_rows > 0) {
                        $complaint_types = $complaint_result->num_rows;
                        $complaint_result->data_seek(0);
                        while ($row = $complaint_result->fetch_assoc()) {
                            $total_cases += $row['total_cases'];
                        }
                        $complaint_result->data_seek(0);
                    }
                    ?>
                    <div class="stat-card">
                        <div class="number"><?php echo $complaint_types; ?></div>
                        <div class="label">Complaint Types</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo $total_cases; ?></div>
                        <div class="label">Total Cases</div>
                    </div>
                    <div class="stat-card">
                        <div class="number"><?php echo $total_records; ?></div>
                        <div class="label">Total Records</div>
                    </div>
                </div>
            </div>

            <!-- Content -->
            <div class="content">
                <!-- Complaint Table -->
                <div class="table-container">
                    <div class="section-title no-print">
                        <div><i class="fas fa-table"></i> Complaint Details</div>
                        <div class="table-actions">
                            <button class="action-btn print" onclick="printTable('complaintTable', 'Complaint Report')">
                                <i class="fas fa-print"></i> Print Table
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-wrapper">
                        <?php if ($complaint_result && $complaint_result->num_rows > 0): ?>
                            <table id="complaintTable">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Complaint</th>
                                        <th>Total Cases</th>
                                        <th>Percentage</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $count = 1;
                                    $total_cases = 0;
                                    $complaint_result->data_seek(0);
                                    while ($row = $complaint_result->fetch_assoc()) $total_cases += $row['total_cases'];
                                    $complaint_result->data_seek(0);
                                    while ($row = $complaint_result->fetch_assoc()):
                                        $percentage = $total_cases > 0 ? round(($row['total_cases'] / $total_cases) * 100, 1) : 0;
                                    ?>
                                        <tr>
                                            <td><?php echo $count++; ?></td>
                                            <td><?php echo htmlspecialchars($row['complaint']); ?></td>
                                            <td><span class="case-count"><?php echo $row['total_cases']; ?></span></td>
                                            <td>
                                                <div class="percentage-bar">
                                                    <div class="bar-container">
                                                        <div class="bar-fill" style="width: <?php echo $percentage; ?>%"></div>
                                                    </div>
                                                    <span><?php echo $percentage; ?>%</span>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-list"></i>
                                <h3>No complaint data for selected filters</h3>
                                <p>Try selecting a different date range or section.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Detailed Records Table -->
                <div class="table-container">
                    <div class="section-title no-print">
                        <div><i class="fas fa-list"></i> Detailed Clinic Records</div>
                        <div class="table-actions">
                            <button class="action-btn print" onclick="printTable('detailedTable', 'Clinic Records Report')">
                                <i class="fas fa-print"></i> Print Table
                            </button>
                        </div>
                    </div>
                    
                    <div class="table-controls no-print">
                        <div class="search-section">
                            <input type="text" id="searchInput" class="search-box" placeholder="Search records...">
                            
                            <!-- Grade & Section Filter -->
                            <select id="gradeSectionFilter" class="grade-section-select" onchange="applyFilters()">
                                <option value="">All Grades & Sections</option>
                                <?php if ($all_grades_result && $all_grades_result->num_rows > 0): ?>
                                    <?php while ($grade_row = $all_grades_result->fetch_assoc()): ?>
                                        <option value="<?php echo htmlspecialchars($grade_row['grade_section']); ?>" 
                                            <?php echo $grade_row['grade_section'] == $grade_section ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($grade_row['grade_section']); ?>
                                        </option>
                                    <?php endwhile; ?>
                                <?php endif; ?>
                            </select>
                        </div>
                        
                        <div class="table-info">
                            <?php if (!empty($grade_section)): ?>
                                Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> records for <?php echo htmlspecialchars($grade_section); ?>
                                (<?php echo $records_per_page; ?> per page)
                            <?php else: ?>
                                Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> records
                                (<?php echo $records_per_page; ?> per page)
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="table-wrapper">
                        <?php if ($detailed_result && $detailed_result->num_rows > 0): ?>
                            <table id="detailedTable">
                                <thead>
                                    <tr>
                                        <!-- Added Numbering column -->
                                        <th>#</th>
                                        <th>Student ID</th>
                                        <th>Name</th>
                                        <th>Grade & Section</th>
                                        <th>Complaint</th>
                                        <th>Treatment</th>
                                        <th>Date</th>
                                        <th>Time</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $current_number = $start_number;
                                    while ($row = $detailed_result->fetch_assoc()):
                                    ?>
                                        <tr>
                                            <!-- Added Numbering column -->
                                            <td><?php echo $current_number++; ?></td>
                                            <td><?php echo htmlspecialchars($row['student_id']); ?></td>
                                            <td><?php echo htmlspecialchars($row['name']); ?></td>
                                            <td><?php echo htmlspecialchars($row['grade_section']); ?></td>
                                            <td><?php echo htmlspecialchars($row['complaint']); ?></td>
                                            <td><?php echo htmlspecialchars($row['treatment']); ?></td>
                                            <td><?php echo date('Y-m-d', strtotime($row['date'])); ?></td>
                                            <td><?php echo date('h:i A', strtotime($row['time'])); ?></td>
                                        </tr>
                                    <?php endwhile; ?>
                                </tbody>
                            </table>
                            
                            <!-- Pagination -->
                            <?php if ($total_pages > 1): ?>
                            <div class="pagination no-print">
                                <!-- Previous Button -->
                                <button class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>" 
                                        onclick="changePage(<?php echo $page - 1; ?>)" 
                                        <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                                    <i class="fas fa-chevron-left"></i> Previous
                                </button>
                                
                                <!-- Page Numbers -->
                                <div class="page-numbers">
                                    <?php
                                    // Show first page
                                    if ($page > 3): ?>
                                        <button class="page-number" onclick="changePage(1)">1</button>
                                        <?php if ($page > 4): ?>
                                            <span class="page-number" style="border: none; background: transparent;">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <?php
                                    // Show pages around current page
                                    for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                        <button class="page-number <?php echo $i == $page ? 'active' : ''; ?>" 
                                                onclick="changePage(<?php echo $i; ?>)">
                                            <?php echo $i; ?>
                                        </button>
                                    <?php endfor; ?>
                                    
                                    <?php
                                    // Show last page
                                    if ($page < $total_pages - 2): ?>
                                        <?php if ($page < $total_pages - 3): ?>
                                            <span class="page-number" style="border: none; background: transparent;">...</span>
                                        <?php endif; ?>
                                        <button class="page-number" onclick="changePage(<?php echo $total_pages; ?>)">
                                            <?php echo $total_pages; ?>
                                        </button>
                                    <?php endif; ?>
                                </div>
                                
                                <!-- Next Button -->
                                <button class="pagination-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>" 
                                        onclick="changePage(<?php echo $page + 1; ?>)" 
                                        <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                                    Next <i class="fas fa-chevron-right"></i>
                                </button>
                                
                                <!-- Records per page selector - ALWAYS VISIBLE -->
                                <div class="records-per-page">
                                    <span>Show:</span>
                                    <select onchange="changeRecordsPerPage(this.value)" id="recordsPerPageSelect">
                                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                        <option value="25" <?php echo $records_per_page == 25 ? 'selected' : ''; ?>>25</option>
                                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                        <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                                    </select>
                                    <span>per page</span>
                                </div>
                            </div>
                            
                            <div class="pagination-info no-print">
                                Page <?php echo $page; ?> of <?php echo $total_pages; ?> • 
                                Records <?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?>
                            </div>
                            <?php endif; ?>
                            
                        <?php else: ?>
                            <div class="no-data">
                                <i class="fas fa-clipboard-list"></i>
                                <h3>No detailed records found for selected filters</h3>
                                <p>Try selecting a different date range or section.</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function applyFilters() {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            const gradeSection = document.getElementById('gradeSectionFilter').value;
            const perPageSelect = document.getElementById('recordsPerPageSelect');
            const currentPerPage = perPageSelect ? perPageSelect.value : 10;
            
            // Build URL with all parameters (reset to page 1 when filtering)
            let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=1&per_page=${currentPerPage}`;
            
            // Only add grade_section if it's not empty
            if (gradeSection) {
                url += `&grade_section=${gradeSection}`;
            }
            
            window.location.href = url;
        }
        
        function applyDateFilter() {
            // Same as applyFilters but triggers when date filter is applied
            applyFilters();
        }
        
        function resetDateFilter() {
            const today = new Date().toISOString().split('T')[0];
            const firstDayOfMonth = new Date();
            firstDayOfMonth.setDate(1);
            const firstDayStr = firstDayOfMonth.toISOString().split('T')[0];
            
            document.getElementById('startDate').value = firstDayStr;
            document.getElementById('endDate').value = today;
            document.getElementById('reportType').value = 'monthly';
            document.getElementById('gradeSectionFilter').value = '';
            
            // Reset per page to default
            const perPageSelect = document.getElementById('recordsPerPageSelect');
            if (perPageSelect) {
                perPageSelect.value = '10';
            }
            
            // Apply filters with default values
            applyFilters();
        }
        
        function changePage(newPage) {
            if (newPage < 1 || newPage > <?php echo $total_pages; ?>) return;
            
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            const gradeSection = document.getElementById('gradeSectionFilter').value;
            const perPageSelect = document.getElementById('recordsPerPageSelect');
            const currentPerPage = perPageSelect ? perPageSelect.value : 10;
            
            let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=${newPage}&per_page=${currentPerPage}`;
            
            if (gradeSection) {
                url += `&grade_section=${gradeSection}`;
            }
            
            window.location.href = url;
        }
        
        function changeRecordsPerPage(perPage) {
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            const gradeSection = document.getElementById('gradeSectionFilter').value;
            
            let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=1&per_page=${perPage}`;
            
            if (gradeSection) {
                url += `&grade_section=${gradeSection}`;
            }
            
            window.location.href = url;
        }
        
        function exportToExcel() {
            window.location.href = `?export=true&month=<?php echo $selected_month; ?>`;
        }
        
        function printTable(tableId, title) {
            const table = document.getElementById(tableId);
            if (!table) {
                alert('Table not found');
                return;
            }
            
            const startDate = document.getElementById('startDate').value;
            const endDate = document.getElementById('endDate').value;
            const reportType = document.getElementById('reportType').value;
            const gradeSection = document.getElementById('gradeSectionFilter').value;
            
            const win = window.open('', '', 'width=1200,height=700');
            win.document.write(`
                <html>
                <head>
                    <title>${title}</title>
                    <style>
                        body { font-family: Arial, sans-serif; padding: 30px; }
                        h1 { text-align: center; color: #4361ee; margin-bottom: 10px; }
                        .report-info { text-align: center; margin-bottom: 30px; color: #6c757d; }
                        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                        th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
                        th { background: #f8f9fa; font-weight: bold; color: #4361ee; }
                        tr:nth-child(even) { background: #f9fafb; }
                        @media print {
                            body { padding: 10px; }
                            table { font-size: 11px; }
                        }
                    </style>
                </head>
                <body>
                    <h1>${title}</h1>
                    <div class="report-info">
                        Date Range: ${new Date(startDate).toLocaleDateString()} to ${new Date(endDate).toLocaleDateString()}<br>
                        ${gradeSection ? `Section: ${gradeSection}<br>` : ''}
                        Report Type: ${reportType.charAt(0).toUpperCase() + reportType.slice(1)} Analysis<br>
                        Generated: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}
                    </div>
                    ${table.outerHTML}
                </body>
                </html>
            `);
            win.document.close();
            win.focus();
            setTimeout(() => win.print(), 500);
        }
        
        function printWholePage() {
            // Update print section content
            const printSection = document.querySelector('.print-section');
            
            // Clone the complaint table for print
            const complaintTable = document.getElementById('complaintTable').cloneNode(true);
            
            // Clone the detailed table for print (with all records, not just current page)
            let detailedTableHTML = '';
            
            // Create a print-friendly version of both tables
            printSection.innerHTML = `
                <div style="text-align: center; margin-bottom: 30px;">
                    <h1 style="color: #4361ee; margin-bottom: 5px;">
                        <i class="fa-solid fa-chart-column"></i>
                        Monthly Complaint Report
                    </h1>
                    <p style="color: #666; margin-bottom: 20px;">Analysis of patient complaints by month</p>
                    
                    <div style="background: #f8fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                        <h2 style="color: #4361ee; margin-bottom: 10px; font-size: 18px;">
                            <i class="fas fa-calendar-week"></i>
                            Analysis for ${new Date(document.getElementById('startDate').value).toLocaleDateString()} to ${new Date(document.getElementById('endDate').value).toLocaleDateString()}
                            ${document.getElementById('gradeSectionFilter').value ? `<span style="font-size: 14px; color: #666;">(Filtered by: ${document.getElementById('gradeSectionFilter').value})</span>` : ''}
                        </h2>
                        
                        <div style="display: flex; justify-content: center; gap: 20px; margin-top: 15px;">
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;">${document.querySelector('.stat-card:nth-child(1) .number').textContent}</div>
                                <div style="font-size: 12px; color: #666;">Complaint Types</div>
                            </div>
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;">${document.querySelector('.stat-card:nth-child(2) .number').textContent}</div>
                                <div style="font-size: 12px; color: #666;">Total Cases</div>
                            </div>
                            <div style="text-align: center; padding: 10px; min-width: 100px;">
                                <div style="font-size: 24px; font-weight: bold; color: #4361ee;">${document.querySelector('.stat-card:nth-child(3) .number').textContent}</div>
                                <div style="font-size: 12px; color: #666;">Total Records</div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="text-align: left; color: #666; font-size: 12px; margin-bottom: 20px;">
                        Generated: ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString()}<br>
                        Report Type: ${document.getElementById('reportType').value.charAt(0).toUpperCase() + document.getElementById('reportType').value.slice(1)} Analysis<br>
                        Showing: ${<?php echo min($records_per_page, $total_records - $offset); ?>} records per page (Page ${<?php echo $page; ?>} of ${<?php echo $total_pages; ?>})
                    </div>
                </div>
                
                <div style="margin-bottom: 40px;">
                    <h3 style="color: #4361ee; border-bottom: 2px solid #4361ee; padding-bottom: 5px; margin-bottom: 15px;">
                        Complaint Summary
                    </h3>
                    ${document.getElementById('complaintTable').outerHTML}
                </div>
                
                <div style="margin-bottom: 40px;">
                    <h3 style="color: #4361ee; border-bottom: 2px solid #4361ee; padding-bottom: 5px; margin-bottom: 15px;">
                        Detailed Clinic Records (Page ${<?php echo $page; ?>} of ${<?php echo $total_pages; ?>})
                    </h3>
                    ${document.getElementById('detailedTable').outerHTML}
                </div>
            `;
            
            // Show print section and hide everything else
            printSection.style.display = 'block';
            
            // Trigger print
            window.print();
            
            // Hide print section after printing
            printSection.style.display = 'none';
        }
        
        // Search function for detailed table
        document.addEventListener('DOMContentLoaded', function() {
            const searchInput = document.getElementById('searchInput');
            const rows = document.querySelectorAll('#detailedTable tbody tr');
            
            if (searchInput && rows.length > 0) {
                searchInput.addEventListener('keyup', () => {
                    const value = searchInput.value.toLowerCase();
                    
                    rows.forEach(row => {
                        if (row.innerText.toLowerCase().includes(value)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
            
            // Animate percentage bars on load
            const bars = document.querySelectorAll('.bar-fill');
            bars.forEach((bar, index) => {
                const currentWidth = bar.style.width;
                bar.style.width = '0%';
                
                setTimeout(() => {
                    bar.style.transition = 'width 1s ease-out';
                    bar.style.width = currentWidth;
                }, index * 100);
            });
        });
        
        // Auto-update date range based on report type
        document.getElementById('reportType').addEventListener('change', function() {
            const reportType = this.value;
            const endDateInput = document.getElementById('endDate');
            const startDateInput = document.getElementById('startDate');
            
            const endDate = new Date(endDateInput.value);
            let startDate = new Date(endDate);
            
            switch(reportType) {
                case 'weekly':
                    startDate.setDate(startDate.getDate() - 7);
                    break;
                case 'monthly':
                    startDate.setMonth(startDate.getMonth() - 1);
                    break;
                case 'yearly':
                    startDate.setFullYear(startDate.getFullYear() - 1);
                    break;
            }
            
            // Format date to YYYY-MM-DD
            const formatDate = (date) => {
                return date.toISOString().split('T')[0];
            };
            
            startDateInput.value = formatDate(startDate);
        });
    </script>
</body>
</html>