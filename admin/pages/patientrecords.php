<?php
// Database connection
include '../includes/conn.php'; 
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filter parameters
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'Monthly Analysis';
$grade_section = isset($_GET['grade_section']) ? $_GET['grade_section'] : '';

// Pagination parameters
$records_per_page = 10;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// Only auto-detect grade section if not provided via filter
if (empty($grade_section)) {
    if (isset($_SESSION['user_grade_section']) && !empty($_SESSION['user_grade_section'])) {
        $grade_section = $_SESSION['user_grade_section'];
    } elseif (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $user_sql = "SELECT grade_section FROM users WHERE id = ?";
        $user_stmt = $conn->prepare($user_sql);
        $user_stmt->bind_param("i", $user_id);
        $user_stmt->execute();
        $user_result = $user_stmt->get_result();
        if ($user_row = $user_result->fetch_assoc()) {
            $grade_section = $user_row['grade_section'] ?? '';
        }
        $user_stmt->close();
    }
}

// Update dates based on report type (only if dates are default or empty)
if ((empty($_GET['start_date']) && empty($_GET['end_date'])) || 
    ($start_date == date('Y-m-01') && $end_date == date('Y-m-t'))) {
    
    $endDateObj = new DateTime($end_date);
    $startDateObj = new DateTime($end_date);
    
    switch($report_type) {
        case 'Weekly Analysis':
            $startDateObj->modify('-7 days');
            $start_date = $startDateObj->format('Y-m-d');
            break;
        case 'Monthly Analysis':
            break;
        case 'Yearly Analysis':
            $startDateObj->modify('-1 year');
            $start_date = $startDateObj->format('Y-m-d');
            break;
    }
}

// Build the base SQL query for counting total records
$count_sql = "SELECT COUNT(*) as total FROM clinic_records WHERE 1=1";
$count_params = array();
$count_types = "";

// Add date filter
if (!empty($start_date) && !empty($end_date)) {
    $count_sql .= " AND date BETWEEN ? AND ?";
    $count_params[] = $start_date;
    $count_params[] = $end_date;
    $count_types .= "ss";
}

// Add grade/section filter
if (!empty($grade_section)) {
    $count_sql .= " AND grade_section = ?";
    $count_params[] = $grade_section;
    $count_types .= "s";
}

// Get total records count
$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
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

// Build the main SQL query with pagination
$sql = "SELECT * FROM clinic_records WHERE 1=1";
$params = array();
$types = "";

// Add date filter
if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

// Add grade/section filter
if (!empty($grade_section)) {
    $sql .= " AND grade_section = ?";
    $params[] = $grade_section;
    $types .= "s";
}

// Order by and add pagination
$sql .= " ORDER BY date DESC, time DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;
$types .= "ii";

// Prepare and execute the query
$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

// Get all grade sections for filter dropdown
$all_grades_sql = "SELECT DISTINCT grade_section FROM clinic_records WHERE grade_section IS NOT NULL AND grade_section != '' ORDER BY grade_section ASC";
$all_grades_result = $conn->query($all_grades_sql);

// Get stats based on current filters
$stats_sql = "SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN treatment IS NOT NULL AND treatment != '' THEN 1 ELSE 0 END) as treated,
                SUM(CASE WHEN treatment IS NULL OR treatment = '' THEN 1 ELSE 0 END) as pending
              FROM clinic_records WHERE 1=1";
              
$stats_params = array();
$stats_types = "";
$stats_where = "";

if (!empty($start_date) && !empty($end_date)) {
    $stats_where .= " AND date BETWEEN ? AND ?";
    $stats_params[] = $start_date;
    $stats_params[] = $end_date;
    $stats_types .= "ss";
}
if (!empty($grade_section)) {
    $stats_where .= " AND grade_section = ?";
    $stats_params[] = $grade_section;
    $stats_types .= "s";
}

$stats_sql .= $stats_where;
$stats_stmt = $conn->prepare($stats_sql);
if (!empty($stats_params)) {
    $stats_stmt->bind_param($stats_types, ...$stats_params);
}
$stats_stmt->execute();
$stats_result = $stats_stmt->get_result();
$stats = $stats_result ? $stats_result->fetch_assoc() : ['total' => 0, 'treated' => 0, 'pending' => 0];
$stats_stmt->close();

$report_date = date('F d, Y');
$report_time = date('h:i A');

$web_path = '/clinic/assets/pictures/format.png';
$server_path = $_SERVER['DOCUMENT_ROOT'] . $web_path;
$image_exists = file_exists($server_path);

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Patient Reports - Clinic Management System</title>
    
    <link rel="preload" as="image" href="<?php echo $web_path; ?>">
    <link rel="stylesheet" href="../assets/css/patient.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <style>
        /* Custom styles for filters */
        .filter-section {
            background: white;
            padding: 25px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            margin-bottom: 25px;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .filter-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            align-items: end;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
        }
        
        .filter-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #4b5563;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
            transition: border-color 0.3s;
            background: white;
        }
        
        .filter-group input:focus,
        .filter-group select:focus {
            outline: none;
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        
        .filter-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
            margin-top: 10px;
        }
        
        .filter-btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s;
            min-width: 150px;
        }
        
        .btn-apply {
            background: #4361ee;
            color: white;
        }
        
        .btn-apply:hover {
            background: #3a56d4;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(67, 97, 238, 0.2);
        }
        
        .btn-reset {
            background: #6c757d;
            color: white;
        }
        
        .btn-reset:hover {
            background: #5a6268;
            transform: translateY(-2px);
        }
        
        /* Update existing table controls */
        .table-controls {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            margin-bottom: 25px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .search-filter {
            display: flex;
            gap: 15px;
            align-items: center;
            flex-wrap: wrap;
        }
        
        .search-box {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            min-width: 250px;
        }
        
        .filter-select {
            padding: 10px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            font-size: 16px;
            min-width: 150px;
            background: white;
        }
        
        .grade-section-select {
            min-width: 250px;
        }
        
        .table-info {
            font-weight: 600;
            color: #4361ee;
            background: #f0f4ff;
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #dbe4ff;
        }
        
        /* Pagination Styles */
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            margin-top: 20px;
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
        }
        
        /* Keep existing styles */
        .page {
            width: 210mm;
            min-height: 297mm;
            padding: 20mm;
            margin: 10mm auto;
            background-color: white;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
            background-image: url('<?php echo $web_path; ?>'); 
            background-size: cover; 
            background-position: center;
            background-repeat: no-repeat;
        }

        .print-template {
            display: none;
        }

        @media print {
            @page {
                size: A4;
                margin: 0;
            }

            body {
                background: none;
                margin: 0;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            .main-content, .sidebar, .header, .table-controls, .no-print,
            .filter-section, .pagination {
                display: none !important;
            }

            .print-template {
                display: block !important;
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                visibility: visible;
            }

            .page {
                width: 100%;
                height: 100%;
                margin: 0;
                box-shadow: none;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                background-image: url('<?php echo $web_path; ?>') !important; 
            }
        }
        
        /* Update header styling */
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
        
        /* Table styling */
        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
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
            position: sticky;
            top: 0;
        }
        
        td {
            padding: 14px 12px;
            border-bottom: 1px solid #eef0f3;
            vertical-align: middle;
        }
        
        tbody tr:hover {
            background-color: #f8fafc;
        }
        
        .status-badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-treated {
            background: #d1fae5;
            color: #065f46;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #92400e;
        }
        
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 60px 20px;
            color: #6c757d;
            text-align: center;
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            color: #d1d5db;
        }
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: #4b5563;
        }
        
        /* Pagination info */
        .pagination-info {
            font-size: 14px;
            color: #666;
            margin-top: 10px;
            text-align: right;
        }
    </style>
</head>
<body>

    <div style="background-image: url('<?php echo $web_path; ?>'); width:0; height:0; overflow:hidden; visibility:hidden; position:absolute;"></div>

    <div class="main-content no-print">
        <div class="container">
            <div class="header">
                <h1><i class="fa fa-file-medical-alt"></i> Patient Records</h1>
                <p>View all patient clinic visit records</p>
            </div>
            
            <!-- Filter Section -->
            <div class="filter-section">
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="startDate">
                            <i class="fas fa-calendar-day"></i>
                            Start Date
                        </label>
                        <input type="date" id="startDate" name="start_date" value="<?php echo $start_date; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="endDate">
                            <i class="fas fa-calendar-day"></i>
                            End Date
                        </label>
                        <input type="date" id="endDate" name="end_date" value="<?php echo $end_date; ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label for="reportType">
                            <i class="fas fa-chart-bar"></i>
                            Report Type
                        </label>
                        <select id="reportType" name="report_type">
                            <option value="Weekly Analysis" <?php echo $report_type == 'Weekly Analysis' ? 'selected' : ''; ?>>Weekly Analysis</option>
                            <option value="Monthly Analysis" <?php echo $report_type == 'Monthly Analysis' ? 'selected' : ''; ?>>Monthly Analysis</option>
                            <option value="Yearly Analysis" <?php echo $report_type == 'Yearly Analysis' ? 'selected' : ''; ?>>Yearly Analysis</option>
                        </select>
                    </div>
                </div>
                
                <div class="filter-actions">
                    <button class="filter-btn btn-reset" onclick="resetFilters()">
                        <i class="fas fa-redo"></i>
                        Reset
                    </button>
                    <button class="filter-btn btn-apply" onclick="applyFilters()">
                        <i class="fas fa-filter"></i>
                        Apply Filter
                    </button>
                </div>
            </div>
            
            <div class="table-controls">
                <div class="search-filter">
                    <input type="text" class="search-box" placeholder="Search patients..." onkeyup="searchTable()" id="searchInput">
                    
                    <select class="filter-select" onchange="filterTableByStatus()" id="statusFilter">
                        <option value="">All Status</option>
                        <option value="treated">Treated</option>
                        <option value="pending">Pending</option>
                    </select>
                    
                    <!-- Grade & Section Filter -->
                    <select class="filter-select grade-section-select" onchange="applyFilters()" id="gradeSectionFilter">
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
                        Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> record(s) for <?php echo htmlspecialchars($grade_section); ?> from <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                    <?php else: ?>
                        Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> record(s) from <?php echo date('M d, Y', strtotime($start_date)); ?> to <?php echo date('M d, Y', strtotime($end_date)); ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="table-container">
                <?php if ($result && $result->num_rows > 0): ?>
                    <table id="patientsTable">
                        <thead>
                            <tr>
                                <!-- REMOVED: <th>ID</th> -->
                                <th>Student ID</th>
                                <th>Name</th>
                                <th>Grade & Section</th>
                                <th>Complaint</th>
                                <th>Treatment</th>
                                <th>Status</th>
                                <th>Date</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php while($row = $result->fetch_assoc()): ?>
                            <tr>
                                <!-- REMOVED: <td><?php echo htmlspecialchars($row['id'] ?? ''); ?></td> -->
                                <td><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['name'] ?? ''); ?></strong></td>
                                <td><?php echo htmlspecialchars($row['grade_section'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['complaint'] ?? 'Not specified'); ?></td>
                                <td><?php echo !empty($row['treatment']) ? htmlspecialchars($row['treatment']) : '<span style="color:#999;">No treatment yet</span>'; ?></td>
                                <td>
                                    <?php if (!empty($row['treatment'])): ?>
                                        <span class="status-badge status-treated">Treated</span>
                                    <?php else: ?>
                                        <span class="status-badge status-pending">Pending</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo !empty($row['date']) ? date('Y-m-d', strtotime($row['date'])) : ''; ?></td>
                                <td><?php echo !empty($row['time']) ? date('h:i A', strtotime($row['time'])) : ''; ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    
                    <!-- Pagination -->
                    <div class="pagination">
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
                                    <span class="page-number">...</span>
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
                                    <span class="page-number">...</span>
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
                        
                        <!-- Records per page selector -->
                        <div class="records-per-page">
                            <span>Show:</span>
                            <select onchange="changeRecordsPerPage(this.value)">
                                <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25" <?php echo $records_per_page == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                            <span>per page</span>
                        </div>
                    </div>
                    
                    <div class="pagination-info">
                        Page <?php echo $page; ?> of <?php echo $total_pages; ?> • 
                        Records <?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $records_per_page, $total_records); ?> of <?php echo $total_records; ?>
                    </div>
                    
                <?php else: ?>
                    <div class="empty-state">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No patient records found</h3>
                        <p>There are no records matching your filter criteria<?php echo !empty($grade_section) ? ' for ' . htmlspecialchars($grade_section) : ''; ?>.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- PRINT TEMPLATE (without pagination) -->
    <div class="print-template">
        <div class="page">
            <!-- UPDATED: Simple format like the second image -->
            <div style="position: relative; z-index: 10; font-family: monospace; margin-top: 20mm; line-height: 1.8;">
                <div style="font-size: 14px; margin-bottom: 5px;">
                    Report Period: <?php echo date('F d, Y', strtotime($start_date)) . ' to ' . date('F d, Y', strtotime($end_date)); ?>
                </div>
                <div style="font-size: 14px; margin-bottom: 15px;">
                    <?php echo $total_records; ?> total records analyzed
                </div>
                
                <!-- Simple format like the second image -->
                <div style="font-family: monospace; font-size: 14px; margin-bottom: 5px;">
                    Start Date                                                                  End Date
                </div>
                <div style="font-family: monospace; font-size: 14px; margin-bottom: 10px;">
                    <?php 
                    $start_date_formatted = date('m/d/Y', strtotime($start_date));
                    $end_date_formatted = date('m/d/Y', strtotime($end_date));
                    
                    // Simple format with proper spacing
                    echo sprintf("%-70s%s", $start_date_formatted, $end_date_formatted);
                    ?>
                </div>
                
                <!-- Report Type line -->
                <div style="font-family: monospace; font-size: 14px; margin-top: 10px;">
                    Report Type: <?php echo $report_type; ?>
                </div>
                
                <!-- Grade Section line (if applicable) -->
                <?php if (!empty($grade_section)): ?>
                <div style="font-family: monospace; font-size: 14px; margin-top: 5px;">
                    Grade/Section: <?php echo htmlspecialchars($grade_section); ?>
                </div>
                <?php endif; ?>
            </div>
            <!-- END: Updated format -->
            
            <div class="document-header" style="text-align: center; margin-bottom: 30px; position: relative; z-index: 10;">
                <div class="school-subtitle" style="font-size: 16px;">Clinic Management System</div>
            </div>
            
            <div class="report-header" style="text-align: center; margin-bottom: 20px; border-bottom: 2px solid #000; padding-bottom: 10px; position: relative; z-index: 10;">
                <div class="report-title" style="font-size: 20px; font-weight: bold; text-transform: uppercase;">Patient Clinic Visit Report</div>
                <div class="report-subtitle">Complete Patient Records</div>
            </div>
            
            <div class="report-info" style="display: flex; justify-content: space-between; margin-bottom: 20px; position: relative; z-index: 10;">
                <div class="info-section">
                    <strong>Report Generated:</strong> <?php echo $report_date . ' at ' . $report_time; ?>
                </div>
                <div class="info-section">
                    <strong>Date Range:</strong> <?php echo date('m/d/Y', strtotime($start_date)) . ' - ' . date('m/d/Y', strtotime($end_date)); ?>
                </div>
                <div class="info-section">
                    <strong>School Year:</strong> 2025-2026
                </div>
            </div>
            
            <?php if (!empty($grade_section)): ?>
            <div class="filter-info" style="margin-bottom: 15px; padding: 10px; background: rgba(255,255,255,0.9); border-left: 4px solid #4361ee; position: relative; z-index: 10;">
                <strong>Grade/Section:</strong> <?php echo htmlspecialchars($grade_section); ?>
            </div>
            <?php endif; ?>
            
            <div class="report-stats" style="display: flex; justify-content: space-around; margin-bottom: 30px; border: 1px solid #ddd; padding: 15px; background: rgba(255,255,255,0.9); position: relative; z-index: 10;">
                <div class="stat-item" style="text-align: center;">
                    <div class="stat-number" style="font-size: 24px; font-weight: bold;"><?php echo $stats['total']; ?></div>
                    <div class="stat-label">Total Patients</div>
                </div>
                <div class="stat-item" style="text-align: center;">
                    <div class="stat-number" style="font-size: 24px; font-weight: bold;"><?php echo $stats['treated']; ?></div>
                    <div class="stat-label">Treated Cases</div>
                </div>
                <div class="stat-item" style="text-align: center;">
                    <div class="stat-number" style="font-size: 24px; font-weight: bold;"><?php echo $stats['pending']; ?></div>
                    <div class="stat-label">Pending Cases</div>
                </div>
            </div>
            
            <div class="print-table-container" style="position: relative; z-index: 10;">
                <table class="print-table" style="width: 100%; border-collapse: collapse; background: rgba(255,255,255,0.95);">
                    <thead>
                        <tr style="background-color: #f2f2f2;">
                            <!-- REMOVED: ID column from print view too -->
                            <th style="border: 1px solid #000; padding: 8px; width: 15%;">Student ID</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 20%;">Name</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 20%;">Grade/Section</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 15%;">Complaint</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 20%;">Treatment</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 5%;">Status</th>
                            <th style="border: 1px solid #000; padding: 8px; width: 15%;">Date</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        // Re-query all records for print (without pagination)
                        $print_sql = "SELECT * FROM clinic_records WHERE 1=1";
                        $print_params = array();
                        $print_types = "";
                        
                        if (!empty($start_date) && !empty($end_date)) {
                            $print_sql .= " AND date BETWEEN ? AND ?";
                            $print_params[] = $start_date;
                            $print_params[] = $end_date;
                            $print_types .= "ss";
                        }
                        if (!empty($grade_section)) {
                            $print_sql .= " AND grade_section = ?";
                            $print_params[] = $grade_section;
                            $print_types .= "s";
                        }
                        
                        $print_sql .= " ORDER BY date DESC, time DESC";
                        $print_stmt = $conn->prepare($print_sql);
                        if (!empty($print_params)) {
                            $print_stmt->bind_param($print_types, ...$print_params);
                        }
                        $print_stmt->execute();
                        $print_result = $print_stmt->get_result();
                        
                        while($row = $print_result->fetch_assoc()): 
                        ?>
                        <tr>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($row['student_id'] ?? ''); ?></td>
                            <td style="border: 1px solid #000; padding: 8px;"><strong><?php echo htmlspecialchars($row['name'] ?? ''); ?></strong></td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($row['grade_section'] ?? ''); ?></td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($row['complaint'] ?? ''); ?></td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo htmlspecialchars($row['treatment'] ?? ''); ?></td>
                            <td style="border: 1px solid #000; padding: 8px;">
                                <?php echo !empty($row['treatment']) ? 'TREATED' : 'PENDING'; ?>
                            </td>
                            <td style="border: 1px solid #000; padding: 8px;"><?php echo !empty($row['date']) ? date('Y-m-d', strtotime($row['date'])) : ''; ?></td>
                        </tr>
                        <?php 
                        endwhile; 
                        $print_stmt->close();
                        ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
</body>
</html>

<script>
    function applyFilters() {
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const reportType = document.getElementById('reportType').value;
        const gradeSection = document.getElementById('gradeSectionFilter').value;
        
        // Build URL with all parameters (reset to page 1 when filtering)
        let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=1`;
        
        // Only add grade_section if it's not empty
        if (gradeSection) {
            url += `&grade_section=${gradeSection}`;
        }
        
        window.location.href = url;
    }
    
    function resetFilters() {
        const today = new Date();
        const firstDayOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
        const lastDayOfMonth = new Date(today.getFullYear(), today.getMonth() + 1, 0);
        
        // Format dates as YYYY-MM-DD
        const firstDayStr = firstDayOfMonth.toISOString().split('T')[0];
        const lastDayStr = lastDayOfMonth.toISOString().split('T')[0];
        
        document.getElementById('startDate').value = firstDayStr;
        document.getElementById('endDate').value = lastDayStr;
        document.getElementById('reportType').value = 'Monthly Analysis';
        document.getElementById('gradeSectionFilter').value = '';
        
        // Redirect without grade_section parameter
        window.location.href = `?start_date=${firstDayStr}&end_date=${lastDayStr}&report_type=Monthly Analysis&page=1`;
    }
    
    function changePage(newPage) {
        if (newPage < 1 || newPage > <?php echo $total_pages; ?>) return;
        
        const startDate = document.getElementById('startDate').value;
        const endDate = document.getElementById('endDate').value;
        const reportType = document.getElementById('reportType').value;
        const gradeSection = document.getElementById('gradeSectionFilter').value;
        
        let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=${newPage}`;
        
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
    
    // Auto-update date range when report type changes
    document.getElementById('reportType').addEventListener('change', function() {
        const reportType = this.value;
        const endDateInput = document.getElementById('endDate');
        const startDateInput = document.getElementById('startDate');
        
        const endDate = new Date(endDateInput.value);
        let startDate = new Date(endDate);
        
        switch(reportType) {
            case 'Weekly Analysis':
                startDate.setDate(startDate.getDate() - 7);
                break;
            case 'Monthly Analysis':
                startDate = new Date(endDate.getFullYear(), endDate.getMonth(), 1);
                break;
            case 'Yearly Analysis':
                startDate.setFullYear(startDate.getFullYear() - 1);
                break;
        }
        
        startDateInput.value = startDate.toISOString().split('T')[0];
        // Only update if it's the current month end date
        if (endDateInput.value === '<?php echo date("Y-m-t"); ?>') {
            if (reportType === 'Monthly Analysis') {
                const lastDay = new Date(endDate.getFullYear(), endDate.getMonth() + 1, 0);
                endDateInput.value = lastDay.toISOString().split('T')[0];
            }
        }
    });
    
    // Search function
    function searchTable() {
        var input, filter, table, tr, td, i, txtValue;
        input = document.getElementById("searchInput");
        filter = input.value.toUpperCase();
        table = document.getElementById("patientsTable");
        tr = table.getElementsByTagName("tr");
        
        for (i = 0; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td");
            var found = false;
            for (var j = 0; j < td.length; j++) {
                var cell = td[j];
                if (cell) {
                    txtValue = cell.textContent || cell.innerText;
                    if (txtValue.toUpperCase().indexOf(filter) > -1) {
                        found = true;
                        break;
                    }
                }
            }
            tr[i].style.display = found ? "" : "none";
        }
    }
    
    // Filter table by status (client-side filtering)
    function filterTableByStatus() {
        var filter, table, tr, td, i;
        filter = document.getElementById("statusFilter").value.toUpperCase();
        table = document.getElementById("patientsTable");
        tr = table.getElementsByTagName("tr");
        
        for (i = 0; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td")[5]; // Status column (now 5 because ID column removed)
            if (td) {
                var statusText = td.textContent || td.innerText;
                var status = statusText.toUpperCase().includes("TREATED") ? "TREATED" : "PENDING";
                
                if (filter === "" || (filter === "TREATED" && status === "TREATED") || (filter === "PENDING" && status === "PENDING")) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }
</script>

<?php
if(isset($conn)) $conn->close();
?>