<?php
// Database connection
include '../includes/conn.php';
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get date range from GET parameters or use default (current month)
$start_date  = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date    = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'weekly';

// Handle 'today' report type
if ($report_type === 'today') {
    $start_date = date('Y-m-d');
    $end_date   = date('Y-m-d');
}

/**
 * NEW: Yearly Analysis handler (replaces "comparison")
 * - Forces the date range to Jan 1 to Dec 31 of the selected year.
 */
$selected_year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
if ($report_type === 'yearly') {
    // Safety clamp year
    if ($selected_year < 2000) $selected_year = (int)date('Y');
    if ($selected_year > (int)date('Y') + 5) $selected_year = (int)date('Y');

    $start_date = $selected_year . '-01-01';
    $end_date   = $selected_year . '-12-31';
}

// Function to get overview statistics
function getOverviewStats($conn, $start_date, $end_date) {
    $stats = [];

    // Total patients
    $sql = "SELECT COUNT(DISTINCT student_id) as total_patients FROM clinic_records WHERE date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_patients'] = $result->fetch_assoc()['total_patients'] ?? 0;

    // Total visits
    $sql = "SELECT COUNT(*) as total_visits FROM clinic_records WHERE date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_visits'] = $result->fetch_assoc()['total_visits'] ?? 0;

    // Total number of classes (unique grade_section)
    $sql = "SELECT COUNT(DISTINCT grade_section) as total_classes
            FROM clinic_records
            WHERE grade_section IS NOT NULL AND grade_section != '' AND date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['total_classes'] = $result->fetch_assoc()['total_classes'] ?? 0;

    // Average daily visits
    $sql = "SELECT COALESCE(AVG(daily_count), 0) as avg_daily
            FROM (
                SELECT DATE(date) as d, COUNT(*) as daily_count
                FROM clinic_records
                WHERE date BETWEEN ? AND ?
                GROUP BY DATE(date)
            ) as daily_stats";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['avg_daily_visits'] = round($result->fetch_assoc()['avg_daily'] ?? 0, 1);

    // Average users per week
    $sql = "SELECT YEARWEEK(date) as week_num, COUNT(DISTINCT student_id) as weekly_users
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY YEARWEEK(date)";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $weekly_users = [];
    while ($row = $result->fetch_assoc()) {
        $weekly_users[] = (int)$row['weekly_users'];
    }

    $stats['avg_users_per_week'] = count($weekly_users) > 0
        ? round(array_sum($weekly_users) / count($weekly_users), 1)
        : 0;

    // Treated cases
    $sql = "SELECT COUNT(*) as treated_cases
            FROM clinic_records
            WHERE (treatment IS NOT NULL AND treatment != '') AND date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['treated_cases'] = $result->fetch_assoc()['treated_cases'] ?? 0;

    // Pending cases
    $sql = "SELECT COUNT(*) as pending_cases
            FROM clinic_records
            WHERE (treatment IS NULL OR treatment = '') AND date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats['pending_cases'] = $result->fetch_assoc()['pending_cases'] ?? 0;

    // Most common complaint
    $sql = "SELECT complaint, COUNT(*) as count
            FROM clinic_records
            WHERE complaint IS NOT NULL AND complaint != '' AND date BETWEEN ? AND ?
            GROUP BY complaint
            ORDER BY count DESC
            LIMIT 1";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $most_common = $result->fetch_assoc();

    $stats['most_common_complaint'] = $most_common['complaint'] ?? 'N/A';
    $stats['most_common_count']     = $most_common['count'] ?? 0;

    // Treatment completion rate
    $stats['completion_rate'] = $stats['total_visits'] > 0
        ? round(($stats['treated_cases'] / $stats['total_visits']) * 100, 1)
        : 0;

    // Average visits per patient
    $stats['avg_visits_per_patient'] = $stats['total_patients'] > 0
        ? round($stats['total_visits'] / $stats['total_patients'], 2)
        : 0;

    return $stats;
}

// Function to get daily visits data for chart with date range fill
function getDailyVisitsData($conn, $start_date, $end_date) {
    $all_dates = [];
    $current = strtotime($start_date);
    $end = strtotime($end_date);

    while ($current <= $end) {
        $date_str = date('Y-m-d', $current);
        $all_dates[$date_str] = [
            'label'  => date('M d', $current),
            'visits' => 0,
            'date'   => $date_str
        ];
        $current = strtotime('+1 day', $current);
    }

    $sql = "SELECT DATE(date) as visit_date, COUNT(*) as visits
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY DATE(date)
            ORDER BY visit_date";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $date_str = $row['visit_date'];
        if (isset($all_dates[$date_str])) {
            $all_dates[$date_str]['visits'] = (int)$row['visits'];
        }
    }

    $data = ['labels' => [], 'visits' => [], 'dates' => []];
    foreach ($all_dates as $date_data) {
        $data['labels'][] = $date_data['label'];
        $data['visits'][] = $date_data['visits'];
        $data['dates'][]  = $date_data['date'];
    }

    return $data;
}

// Function to get weekly statistics
function getWeeklyStats($conn, $start_date, $end_date) {
    $sql = "SELECT YEARWEEK(date) as week_num,
                   MIN(date) as week_start,
                   MAX(date) as week_end,
                   COUNT(*) as total_visits,
                   COUNT(DISTINCT student_id) as unique_patients,
                   COUNT(DISTINCT grade_section) as active_classes
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY YEARWEEK(date)
            ORDER BY week_num";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $weeks = [];
    $labels = [];
    $visits = [];
    $patients = [];

    while ($row = $result->fetch_assoc()) {
        $week_label = date('M d', strtotime($row['week_start'])) . ' - ' . date('M d', strtotime($row['week_end']));

        $weeks[] = [
            'week_num'        => $row['week_num'],
            'label'           => $week_label,
            'total_visits'    => (int)$row['total_visits'],
            'unique_patients' => (int)$row['unique_patients'],
            'active_classes'  => (int)$row['active_classes']
        ];

        $labels[]   = 'Week ' . substr($row['week_num'], 4);
        $visits[]   = (int)$row['total_visits'];
        $patients[] = (int)$row['unique_patients'];
    }

    return [
        'weeks'         => $weeks,
        'chart_labels'  => $labels,
        'chart_visits'  => $visits,
        'chart_patients'=> $patients
    ];
}

/**
 * NEW: Yearly analysis chart data (monthly totals)
 * This is the "function of yearly analysis" you requested.
 */
function getYearlyMonthlyStats($conn, $year) {
    // Build 12 months placeholder
    $months = [];
    for ($m = 1; $m <= 12; $m++) {
        $key = sprintf('%04d-%02d', $year, $m);
        $months[$key] = [
            'label'    => date('M', strtotime("$year-$m-01")),
            'visits'   => 0,
            'patients' => 0
        ];
    }

    $start = $year . '-01-01';
    $end   = $year . '-12-31';

    $sql = "SELECT
                DATE_FORMAT(date, '%Y-%m') as ym,
                COUNT(*) as total_visits,
                COUNT(DISTINCT student_id) as unique_patients
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY ym
            ORDER BY ym";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start, $end);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $ym = $row['ym'];
        if (isset($months[$ym])) {
            $months[$ym]['visits']   = (int)$row['total_visits'];
            $months[$ym]['patients'] = (int)$row['unique_patients'];
        }
    }

    $labels = [];
    $visits = [];
    $patients = [];
    foreach ($months as $m) {
        $labels[]   = $m['label'];
        $visits[]   = $m['visits'];
        $patients[] = $m['patients'];
    }

    return [
        'chart_labels'   => $labels,
        'chart_visits'   => $visits,
        'chart_patients' => $patients
    ];
}

// Function to get complaint distribution for chart with better grouping
function getComplaintDistribution($conn, $start_date, $end_date) {
    $sql = "SELECT
                CASE
                    WHEN LOWER(complaint) LIKE '%head%' OR LOWER(complaint) LIKE '%ulo%' THEN 'Headache/Head Pain'
                    WHEN LOWER(complaint) LIKE '%stomach%' OR LOWER(complaint) LIKE '%tiyan%' THEN 'Stomach Pain'
                    WHEN LOWER(complaint) LIKE '%eye%' OR LOWER(complaint) LIKE '%mata%' THEN 'Eye Pain/Problem'
                    WHEN LOWER(complaint) LIKE '%fever%' OR LOWER(complaint) LIKE '%lagnat%' THEN 'Fever'
                    WHEN LOWER(complaint) LIKE '%cough%' OR LOWER(complaint) LIKE '%ubo%' THEN 'Cough'
                    WHEN complaint IS NULL OR complaint = '' THEN 'Not Specified'
                    ELSE complaint
                END as complaint_group,
                COUNT(*) as count
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY complaint_group
            ORDER BY count DESC
            LIMIT 8";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = ['labels' => [], 'data' => [], 'colors' => []];

    $colors = ['#4361ee', '#3a0ca3', '#7209b7', '#f72585', '#4cc9f0', '#4895ef', '#560bad', '#b5179e'];

    $i = 0;
    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['complaint_group'];
        $data['data'][]   = (int)$row['count'];
        $data['colors'][] = $colors[$i % count($colors)];
        $i++;
    }

    return $data;
}

// Function to get top classes by visits with cleaner names
function getTopClasses($conn, $start_date, $end_date) {
    $sql = "SELECT
                CASE
                    WHEN grade_section LIKE '%Grade 12%' THEN 'Grade 12'
                    WHEN grade_section LIKE '%Grade 11%' THEN 'Grade 11'
                    WHEN grade_section LIKE '%Grade 10%' THEN 'Grade 10'
                    WHEN grade_section LIKE '%Grade 9%' THEN 'Grade 9'
                    WHEN grade_section LIKE '%Grade 8%' THEN 'Grade 8'
                    WHEN grade_section LIKE '%Grade 7%' THEN 'Grade 7'
                    WHEN grade_section LIKE '%Kindergarten%' THEN 'Kindergarten'
                    ELSE grade_section
                END as class_group,
                COUNT(*) as visits
            FROM clinic_records
            WHERE grade_section IS NOT NULL AND grade_section != ''
                  AND date BETWEEN ? AND ?
            GROUP BY class_group
            ORDER BY visits DESC
            LIMIT 5";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();

    $data = ['labels' => [], 'data' => []];

    while ($row = $result->fetch_assoc()) {
        $data['labels'][] = $row['class_group'];
        $data['data'][]   = (int)$row['visits'];
    }

    if (empty($data['labels'])) {
        $data['labels'] = ['No data'];
        $data['data']   = [0];
    }

    return $data;
}

// Function to get recent visits for reuse
function getRecentVisits($conn, $start_date, $end_date, $limit = 10) {
    $sql = "SELECT
                name,
                grade_section,
                complaint,
                treatment,
                DATE(date) as visit_date,
                TIME(date) as visit_time
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            ORDER BY date DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $limit);
    $stmt->execute();
    return $stmt->get_result();
}

// Function to get recent visits with pagination
function getRecentVisitsWithPagination($conn, $start_date, $end_date, $records_per_page = 10, $page = 1) {
    $offset = ($page - 1) * $records_per_page;

    $sql_count = "SELECT COUNT(*) as total FROM clinic_records WHERE date BETWEEN ? AND ?";
    $stmt = $conn->prepare($sql_count);
    $stmt->bind_param("ss", $start_date, $end_date);
    $stmt->execute();
    $result = $stmt->get_result();
    $total_rows = (int)($result->fetch_assoc()['total'] ?? 0);

    $sql = "SELECT
                name,
                grade_section,
                complaint,
                treatment,
                DATE(date) as visit_date,
                TIME(date) as visit_time
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            ORDER BY date DESC
            LIMIT ? OFFSET ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssii", $start_date, $end_date, $records_per_page, $offset);
    $stmt->execute();
    $data_result = $stmt->get_result();

    return [
        'data'             => $data_result,
        'total'            => $total_rows,
        'page'             => $page,
        'records_per_page' => $records_per_page,
        'total_pages'      => max(1, (int)ceil($total_rows / max(1, $records_per_page)))
    ];
}

// Function to get complaint distribution with limit (for table)
function getComplaintDistributionForTable($conn, $start_date, $end_date, $limit = 10) {
    $sql = "SELECT
                CASE
                    WHEN LOWER(complaint) LIKE '%head%' OR LOWER(complaint) LIKE '%ulo%' THEN 'Headache/Head Pain'
                    WHEN LOWER(complaint) LIKE '%stomach%' OR LOWER(complaint) LIKE '%tiyan%' THEN 'Stomach Pain'
                    WHEN LOWER(complaint) LIKE '%eye%' OR LOWER(complaint) LIKE '%mata%' THEN 'Eye Pain/Problem'
                    WHEN LOWER(complaint) LIKE '%fever%' OR LOWER(complaint) LIKE '%lagnat%' THEN 'Fever'
                    WHEN LOWER(complaint) LIKE '%cough%' OR LOWER(complaint) LIKE '%ubo%' THEN 'Cough'
                    WHEN complaint IS NULL OR complaint = '' THEN 'Not Specified'
                    ELSE complaint
                END as complaint_group,
                COUNT(*) as count
            FROM clinic_records
            WHERE date BETWEEN ? AND ?
            GROUP BY complaint_group
            ORDER BY count DESC
            LIMIT ?";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ssi", $start_date, $end_date, $limit);
    $stmt->execute();
    return $stmt->get_result();
}

// Pagination params
$records_per_page = isset($_GET['records_per_page']) ? (int)$_GET['records_per_page'] : 10;
$current_page     = isset($_GET['page']) ? (int)$_GET['page'] : 1;

// Get all statistics
$overview_stats         = getOverviewStats($conn, $start_date, $end_date);
$daily_data             = getDailyVisitsData($conn, $start_date, $end_date);
$weekly_stats           = getWeeklyStats($conn, $start_date, $end_date);
$yearly_monthly_stats   = getYearlyMonthlyStats($conn, $selected_year); // NEW
$complaint_data         = getComplaintDistribution($conn, $start_date, $end_date);
$top_classes            = getTopClasses($conn, $start_date, $end_date);
$recent_visits_paginated= getRecentVisitsWithPagination($conn, $start_date, $end_date, $records_per_page, $current_page);

$recent_visits       = $recent_visits_paginated['data'];
$total_recent_visits = $recent_visits_paginated['total'];
$total_pages         = $recent_visits_paginated['total_pages'];

$complaint_table_data = getComplaintDistributionForTable($conn, $start_date, $end_date, 10);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Clinic Statistics Report</title>
    <link rel="stylesheet" href="../assets/css/statistics.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <!-- Main Content Area -->
    <div class="main-content">
        <div class="container">
            <!-- Header -->
            <div class="header">
                <h1><i class="fas fa-chart-line"></i> Clinic Statistics Dashboard <span class="real-data-badge">REAL DATA</span></h1>
                <p>Comprehensive analysis of clinic operations with detailed charts and metrics</p>
            </div>

            <!-- Date Range Display -->
            <div class="date-range">
                <i class="fas fa-calendar-alt"></i>
                Report Period: <?php echo date('F d, Y', strtotime($start_date)); ?> to <?php echo date('F d, Y', strtotime($end_date)); ?>
                <span style="margin-left: auto; font-size: 13px; color: #6c757d;">
                    <i class="fas fa-database"></i> <?php echo $overview_stats['total_visits']; ?> total records analyzed
                </span>
            </div>

            <!-- Filters -->
            <div class="filters">
                <form method="GET" action="">
                    <div class="filter-group">
                        <div class="filter-item">
                            <label><i class="fas fa-calendar-start"></i> Start Date</label>
                            <input type="date" name="start_date" value="<?php echo $start_date; ?>" required <?php echo ($report_type === 'yearly') ? 'disabled' : ''; ?>>
                        </div>

                        <div class="filter-item">
                            <label><i class="fas fa-calendar-end"></i> End Date</label>
                            <input type="date" name="end_date" value="<?php echo $end_date; ?>" required <?php echo ($report_type === 'yearly') ? 'disabled' : ''; ?>>
                        </div>

                        <div class="filter-item">
                            <label><i class="fas fa-chart-bar"></i> Report Type</label>
                            <select name="report_type" onchange="toggleYearSelect(this.value)">
                                <option value="today"   <?php echo $report_type == 'today' ? 'selected' : ''; ?>>Today's Analysis</option>
                                <option value="weekly"  <?php echo $report_type == 'weekly' ? 'selected' : ''; ?>>Weekly Analysis</option>
                                <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly Analysis</option>
                                <option value="yearly"  <?php echo $report_type == 'yearly' ? 'selected' : ''; ?>>Yearly Analysis</option>
                            </select>
                        </div>

                        <!-- NEW: Year selector shown for Yearly Analysis -->
                        <div class="filter-item" id="yearSelectWrap" style="<?php echo ($report_type === 'yearly') ? '' : 'display:none;'; ?>">
                            <label><i class="fas fa-calendar"></i> Year</label>
                            <select name="year">
                                <?php
                                $currentY = (int)date('Y');
                                for ($y = $currentY; $y >= $currentY - 5; $y--) {
                                    $sel = ($selected_year === $y) ? 'selected' : '';
                                    echo "<option value=\"$y\" $sel>$y</option>";
                                }
                                ?>
                            </select>
                        </div>

                        <button type="submit" class="btn-apply">
                            <i class="fas fa-filter"></i> Apply Filter
                        </button>
                    </div>

                    <!-- Quick Date Filters -->
                    <div class="quick-filters">
                        <a href="?start_date=<?php echo date('Y-m-d'); ?>&end_date=<?php echo date('Y-m-d'); ?>&report_type=today"
                           class="quick-filter-btn <?php echo ($start_date == date('Y-m-d') && $end_date == date('Y-m-d')) ? 'active' : ''; ?>">
                            <i class="fas fa-sun"></i> Today
                        </a>

                        <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>&report_type=weekly"
                           class="quick-filter-btn <?php echo ($start_date == date('Y-m-d', strtotime('-7 days')) && $end_date == date('Y-m-d')) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-week"></i> Last 7 Days
                        </a>

                        <a href="?start_date=<?php echo date('Y-m-01'); ?>&end_date=<?php echo date('Y-m-t'); ?>&report_type=monthly"
                           class="quick-filter-btn <?php echo ($start_date == date('Y-m-01') && $end_date == date('Y-m-t')) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar-alt"></i> This Month
                        </a>

                        <a href="?start_date=<?php echo date('Y-m-01', strtotime('-1 month')); ?>&end_date=<?php echo date('Y-m-t', strtotime('-1 month')); ?>&report_type=monthly"
                           class="quick-filter-btn <?php echo ($start_date == date('Y-m-01', strtotime('-1 month')) && $end_date == date('Y-m-t', strtotime('-1 month'))) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> Last Month
                        </a>

                        <!-- NEW: Yearly quick filter -->
                        <a href="?report_type=yearly&year=<?php echo date('Y'); ?>"
                           class="quick-filter-btn <?php echo ($report_type === 'yearly' && $selected_year === (int)date('Y')) ? 'active' : ''; ?>">
                            <i class="fas fa-calendar"></i> This Year
                        </a>
                    </div>
                </form>
            </div>

            <!-- Key Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon patients"><i class="fas fa-user-injured"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['total_patients']; ?></div>
                    <div class="stat-label">Total Unique Patients</div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-line"></i> <?php echo $overview_stats['avg_visits_per_patient']; ?> avg visits per patient
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon visits"><i class="fas fa-calendar-check"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['total_visits']; ?></div>
                    <div class="stat-label">Total Clinic Visits</div>
                    <div class="stat-trend <?php echo $overview_stats['pending_cases'] > 0 ? 'down' : ''; ?>">
                        <i class="fas fa-<?php echo $overview_stats['pending_cases'] > 0 ? 'exclamation-circle' : 'check-circle'; ?>"></i>
                        <?php echo $overview_stats['pending_cases']; ?> pending cases
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon classes"><i class="fas fa-school"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['total_classes']; ?></div>
                    <div class="stat-label">Active Classes/Groups</div>
                    <div class="stat-trend"><i class="fas fa-users"></i> Accessed clinic services</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon avg-daily"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['avg_daily_visits']; ?></div>
                    <div class="stat-label">Average Daily Visits</div>
                    <div class="stat-trend">
                        <i class="fas fa-chart-bar"></i> Based on <?php echo count($daily_data['dates']); ?> days
                    </div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon avg-weekly"><i class="fas fa-calendar-week"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['avg_users_per_week']; ?></div>
                    <div class="stat-label">Average Users Per Week</div>
                    <div class="stat-trend"><i class="fas fa-user-friends"></i> Weekly unique patients</div>
                </div>

                <div class="stat-card">
                    <div class="stat-icon completion"><i class="fas fa-percentage"></i></div>
                    <div class="stat-number"><?php echo $overview_stats['completion_rate']; ?>%</div>
                    <div class="stat-label">Treatment Completion Rate</div>
                    <div class="stat-trend"><i class="fas fa-check-circle"></i> <?php echo $overview_stats['treated_cases']; ?> treated</div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-section">
                <!-- Daily/Weekly/Monthly Visits Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">
                            <i class="fas fa-chart-line"></i> Clinic Visits Trend
                            <span class="real-data-badge"><?php echo $overview_stats['total_visits']; ?> visits</span>
                        </div>

                        <div class="chart-toggle">
                            <button class="toggle-btn" id="btnDaily" onclick="showDailyChart(this)" type="button">Daily</button>
                            <button class="toggle-btn" id="btnWeekly" onclick="showWeeklyChart(this)" type="button">Weekly</button>
                            <button class="toggle-btn" id="btnMonthly" onclick="showMonthlyChart(this)" type="button" style="<?php echo ($report_type === 'yearly') ? '' : 'display:none;'; ?>">Monthly</button>
                        </div>
                    </div>

                    <div class="chart-wrapper">
                        <canvas id="visitsChart"></canvas>
                    </div>

                    <div class="chart-summary">
                        <div class="chart-summary-item">
                            <i class="fas fa-calendar-day"></i>
                            <span>Period: <span class="chart-summary-value"><?php echo count($daily_data['dates']); ?> days</span></span>
                        </div>
                        <div class="chart-summary-item">
                            <i class="fas fa-users"></i>
                            <span>Total: <span class="chart-summary-value"><?php echo $overview_stats['total_visits']; ?> visits</span></span>
                        </div>
                        <div class="chart-summary-item">
                            <i class="fas fa-chart-bar"></i>
                            <span>Avg/Day: <span class="chart-summary-value"><?php echo round($overview_stats['total_visits'] / max(1, count($daily_data['dates'])), 1); ?></span></span>
                        </div>
                    </div>
                </div>

                <!-- Complaint Distribution Chart -->
                <div class="chart-container">
                    <div class="chart-header">
                        <div class="chart-title">
                            <i class="fas fa-stethoscope"></i> Complaint Distribution
                            <span class="real-data-badge"><?php echo array_sum($complaint_data['data']); ?> cases</span>
                        </div>
                    </div>
                    <div class="chart-wrapper">
                        <canvas id="complaintChart"></canvas>
                    </div>
                    <div class="chart-summary">
                        <div class="chart-summary-item">
                            <i class="fas fa-stethoscope"></i>
                            <span>Most Common: <span class="chart-summary-value"><?php echo htmlspecialchars($overview_stats['most_common_complaint']); ?></span></span>
                        </div>
                        <div class="chart-summary-item">
                            <i class="fas fa-list-ol"></i>
                            <span>Cases: <span class="chart-summary-value"><?php echo $overview_stats['most_common_count']; ?></span></span>
                        </div>
                        <div class="chart-summary-item">
                            <i class="fas fa-percentage"></i>
                            <span>Percentage: <span class="chart-summary-value"><?php echo $overview_stats['total_visits'] > 0 ? round(($overview_stats['most_common_count'] / $overview_stats['total_visits']) * 100, 1) : 0; ?>%</span></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Top Classes Chart -->
            <div class="chart-container" style="margin-bottom: 30px;">
                <div class="chart-header">
                    <div class="chart-title">
                        <i class="fas fa-school"></i> Top 5 Classes by Visits
                        <span class="real-data-badge"><?php echo $overview_stats['total_classes']; ?> active classes</span>
                    </div>
                </div>
                <div class="chart-wrapper">
                    <canvas id="classesChart"></canvas>
                </div>
                <div class="chart-summary">
                    <div class="chart-summary-item">
                        <i class="fas fa-chart-pie"></i>
                        <span>Top Class: <span class="chart-summary-value"><?php echo !empty($top_classes['labels'][0]) ? htmlspecialchars($top_classes['labels'][0]) : 'N/A'; ?></span></span>
                    </div>
                    <div class="chart-summary-item">
                        <i class="fas fa-user-graduate"></i>
                        <span>Visits: <span class="chart-summary-value"><?php echo !empty($top_classes['data'][0]) ? $top_classes['data'][0] : 0; ?></span></span>
                    </div>
                    <div class="chart-summary-item">
                        <i class="fas fa-percentage"></i>
                        <span>of Total: <span class="chart-summary-value"><?php echo array_sum($top_classes['data']) > 0 ? round(($top_classes['data'][0] / array_sum($top_classes['data'])) * 100, 1) : 0; ?>%</span></span>
                    </div>
                </div>
            </div>

            <!-- Recent Visits Table -->
            <div class="table-container" style="margin-bottom: 30px;">
                <div class="table-title">
                    <i class="fas fa-history"></i> Recent Clinic Visits
                </div>

                <form method="GET" action="" class="records-per-page">
                    <input type="hidden" name="start_date" value="<?php echo $start_date; ?>">
                    <input type="hidden" name="end_date" value="<?php echo $end_date; ?>">
                    <input type="hidden" name="report_type" value="<?php echo $report_type; ?>">
                    <input type="hidden" name="page" value="1">
                    <?php if ($report_type === 'yearly'): ?>
                        <input type="hidden" name="year" value="<?php echo $selected_year; ?>">
                    <?php endif; ?>

                    <label for="records_per_page">Show:</label>
                    <select name="records_per_page" id="records_per_page" onchange="this.form.submit()">
                        <option value="5"  <?php echo $records_per_page == 5 ? 'selected' : ''; ?>>5 records</option>
                        <option value="10" <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10 records</option>
                        <option value="20" <?php echo $records_per_page == 20 ? 'selected' : ''; ?>>20 records</option>
                        <option value="50" <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50 records</option>
                    </select>
                </form>

                <div class="table-wrapper">
                    <?php if ($recent_visits->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Student Name</th>
                                    <th>Class/Section</th>
                                    <th>Complaint</th>
                                    <th>Treatment</th>
                                    <th>Date</th>
                                    <th>Time</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $i = (($current_page - 1) * $records_per_page) + 1;
                                while ($row = $recent_visits->fetch_assoc()):
                                ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($row['name']); ?></td>
                                        <td><?php echo htmlspecialchars($row['grade_section']); ?></td>
                                        <td><span class="count-badge"><?php echo htmlspecialchars($row['complaint'] ?: 'Not specified'); ?></span></td>
                                        <td><?php echo htmlspecialchars($row['treatment'] ?: 'Pending'); ?></td>
                                        <td><?php echo date('M d, Y', strtotime($row['visit_date'])); ?></td>
                                        <td><?php echo date('h:i A', strtotime($row['visit_time'])); ?></td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>

                        <!-- Pagination -->
                        <div class="pagination">
                            <div class="pagination-info">
                                Showing <?php echo (($current_page - 1) * $records_per_page) + 1; ?>
                                to <?php echo min($current_page * $records_per_page, $total_recent_visits); ?>
                                of <?php echo $total_recent_visits; ?> entries
                            </div>

                            <div class="pagination-controls">
                                <?php
                                $base = "?start_date=$start_date&end_date=$end_date&report_type=$report_type&records_per_page=$records_per_page";
                                if ($report_type === 'yearly') $base .= "&year=$selected_year";
                                ?>

                                <a href="<?php echo $base . "&page=" . max(1, $current_page - 1); ?>"
                                   class="pagination-btn <?php echo $current_page == 1 ? 'disabled' : ''; ?>">
                                    <i class="fas fa-chevron-left"></i> Previous
                                </a>

                                <div class="page-numbers">
                                    <?php if ($current_page > 3): ?>
                                        <a href="<?php echo $base . "&page=1"; ?>" class="page-number">1</a>
                                        <?php if ($current_page > 4): ?>
                                            <span class="page-number">...</span>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <?php
                                    for ($p = max(1, $current_page - 2); $p <= min($total_pages, $current_page + 2); $p++):
                                    ?>
                                        <a href="<?php echo $base . "&page=$p"; ?>"
                                           class="page-number <?php echo $p == $current_page ? 'active' : ''; ?>">
                                            <?php echo $p; ?>
                                        </a>
                                    <?php endfor; ?>

                                    <?php if ($current_page < $total_pages - 2): ?>
                                        <?php if ($current_page < $total_pages - 3): ?>
                                            <span class="page-number">...</span>
                                        <?php endif; ?>
                                        <a href="<?php echo $base . "&page=$total_pages"; ?>" class="page-number"><?php echo $total_pages; ?></a>
                                    <?php endif; ?>
                                </div>

                                <a href="<?php echo $base . "&page=" . min($total_pages, $current_page + 1); ?>"
                                   class="pagination-btn <?php echo $current_page == $total_pages ? 'disabled' : ''; ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-history"></i>
                            <h3>No Recent Visits</h3>
                            <p>No clinic visits found in the selected period</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Complaint Analysis Details Table -->
            <div class="table-container">
                <div class="table-title">
                    <i class="fas fa-stethoscope"></i> Top 10 Complaint Analysis Details
                </div>
                <div class="table-wrapper">
                    <?php if ($complaint_table_data->num_rows > 0): ?>
                        <table>
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Complaint Type</th>
                                    <th>Cases</th>
                                    <th>Percentage</th>
                                    <th>Trend</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $total_complaints_for_table = 0;
                                $complaint_table_data->data_seek(0);
                                while ($row = $complaint_table_data->fetch_assoc()) {
                                    $total_complaints_for_table += (int)$row['count'];
                                }
                                $complaint_table_data->data_seek(0);

                                $i = 1;
                                while ($row = $complaint_table_data->fetch_assoc()):
                                    $count = (int)$row['count'];
                                    $percentage = $total_complaints_for_table > 0 ? round(($count / $total_complaints_for_table) * 100, 1) : 0;
                                    $trend = $percentage > 20 ? 'High' : ($percentage > 10 ? 'Medium' : 'Low');
                                    $trend_color = $percentage > 20 ? '#ef4444' : ($percentage > 10 ? '#f59e0b' : '#10b981');
                                ?>
                                    <tr>
                                        <td><?php echo $i++; ?></td>
                                        <td><?php echo htmlspecialchars($row['complaint_group']); ?></td>
                                        <td><span class="count-badge"><?php echo $count; ?></span></td>
                                        <td>
                                            <div class="percentage-bar">
                                                <div class="bar">
                                                    <div class="fill" style="width: <?php echo $percentage; ?>%"></div>
                                                </div>
                                                <span style="font-size: 12px; font-weight: 600;"><?php echo $percentage; ?>%</span>
                                            </div>
                                        </td>
                                        <td>
                                            <span style="color: <?php echo $trend_color; ?>; font-weight: 600;">
                                                <?php echo $trend; ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endwhile; ?>
                            </tbody>
                        </table>
                    <?php else: ?>
                        <div class="no-data">
                            <i class="fas fa-stethoscope"></i>
                            <h3>No complaint data available</h3>
                            <p>No complaints recorded in the selected period</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <script>
        // Chart instances
        let visitsChart;
        let complaintChartInstance;
        let classesChartInstance;
        let currentChartType = 'daily';

        const REPORT_TYPE = <?php echo json_encode($report_type); ?>;

        function setActive(btn) {
            document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('active'));
            if (btn) btn.classList.add('active');
        }

        // DAILY
        function initDailyChart() {
            const ctx = document.getElementById('visitsChart').getContext('2d');
            if (visitsChart) visitsChart.destroy();

            const gradient = ctx.createLinearGradient(0, 0, 0, 400);
            gradient.addColorStop(0, 'rgba(67, 97, 238, 0.2)');
            gradient.addColorStop(1, 'rgba(67, 97, 238, 0.05)');

            visitsChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: <?php echo json_encode($daily_data['labels']); ?>,
                    datasets: [{
                        label: 'Daily Visits',
                        data: <?php echo json_encode($daily_data['visits']); ?>,
                        borderColor: '#4361ee',
                        backgroundColor: gradient,
                        borderWidth: 3,
                        fill: true,
                        tension: 0.3,
                        pointBackgroundColor: '#4361ee',
                        pointBorderColor: '#ffffff',
                        pointBorderWidth: 2,
                        pointRadius: 5,
                        pointHoverRadius: 8
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { display: true, position: 'top' },
                        tooltip: {
                            mode: 'index',
                            intersect: false,
                            callbacks: {
                                label: function(context) {
                                    const date = <?php echo json_encode($daily_data['dates']); ?>[context.dataIndex];
                                    return `${date}: ${context.raw} visits`;
                                },
                                title: function() { return ''; }
                            }
                        }
                    },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } },
                        x: { ticks: { maxRotation: 45 } }
                    }
                }
            });
        }

        // WEEKLY
        function initWeeklyChart() {
            const ctx = document.getElementById('visitsChart').getContext('2d');
            if (visitsChart) visitsChart.destroy();

            const gradient1 = ctx.createLinearGradient(0, 0, 0, 300);
            gradient1.addColorStop(0, 'rgba(67, 97, 238, 0.9)');
            gradient1.addColorStop(1, 'rgba(67, 97, 238, 0.6)');

            const gradient2 = ctx.createLinearGradient(0, 0, 0, 300);
            gradient2.addColorStop(0, 'rgba(16, 185, 129, 0.9)');
            gradient2.addColorStop(1, 'rgba(16, 185, 129, 0.6)');

            visitsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($weekly_stats['chart_labels']); ?>,
                    datasets: [
                        {
                            label: 'Total Visits',
                            data: <?php echo json_encode($weekly_stats['chart_visits']); ?>,
                            backgroundColor: gradient1,
                            borderColor: '#4361ee',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Unique Patients',
                            data: <?php echo json_encode($weekly_stats['chart_patients']); ?>,
                            backgroundColor: gradient2,
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top' } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        }

        // MONTHLY (Yearly Analysis)
        function initMonthlyChart() {
            const ctx = document.getElementById('visitsChart').getContext('2d');
            if (visitsChart) visitsChart.destroy();

            const gradient1 = ctx.createLinearGradient(0, 0, 0, 300);
            gradient1.addColorStop(0, 'rgba(67, 97, 238, 0.9)');
            gradient1.addColorStop(1, 'rgba(67, 97, 238, 0.6)');

            const gradient2 = ctx.createLinearGradient(0, 0, 0, 300);
            gradient2.addColorStop(0, 'rgba(16, 185, 129, 0.9)');
            gradient2.addColorStop(1, 'rgba(16, 185, 129, 0.6)');

            visitsChart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($yearly_monthly_stats['chart_labels']); ?>,
                    datasets: [
                        {
                            label: 'Monthly Visits',
                            data: <?php echo json_encode($yearly_monthly_stats['chart_visits']); ?>,
                            backgroundColor: gradient1,
                            borderColor: '#4361ee',
                            borderWidth: 1,
                            borderRadius: 4
                        },
                        {
                            label: 'Monthly Unique Patients',
                            data: <?php echo json_encode($yearly_monthly_stats['chart_patients']); ?>,
                            backgroundColor: gradient2,
                            borderColor: '#10b981',
                            borderWidth: 1,
                            borderRadius: 4
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: true, position: 'top' } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        }

        // Complaint Chart
        function initComplaintChart() {
            const ctx = document.getElementById('complaintChart').getContext('2d');
            if (complaintChartInstance) complaintChartInstance.destroy();

            complaintChartInstance = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: <?php echo json_encode($complaint_data['labels']); ?>,
                    datasets: [{
                        data: <?php echo json_encode($complaint_data['data']); ?>,
                        backgroundColor: <?php echo json_encode($complaint_data['colors']); ?>,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 15
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { position: 'right' } },
                    cutout: '65%'
                }
            });
        }

        // Classes Chart
        function initClassesChart() {
            const ctx = document.getElementById('classesChart').getContext('2d');
            if (classesChartInstance) classesChartInstance.destroy();

            const colors = [
                'rgba(67, 97, 238, 0.9)',
                'rgba(58, 12, 163, 0.9)',
                'rgba(114, 9, 183, 0.9)',
                'rgba(247, 37, 133, 0.9)',
                'rgba(76, 201, 240, 0.9)'
            ];

            const gradients = colors.map((color) => {
                const g = ctx.createLinearGradient(0, 0, 0, 300);
                g.addColorStop(0, color);
                g.addColorStop(1, color.replace('0.9', '0.6'));
                return g;
            });

            classesChartInstance = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($top_classes['labels']); ?>,
                    datasets: [{
                        label: 'Number of Visits',
                        data: <?php echo json_encode($top_classes['data']); ?>,
                        backgroundColor: gradients,
                        borderColor: colors.map(c => c.replace('0.9', '1')),
                        borderWidth: 1,
                        borderRadius: 4,
                        borderSkipped: false
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: { legend: { display: false } },
                    scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } }
                }
            });
        }

        function showDailyChart(btn) {
            currentChartType = 'daily';
            setActive(btn);
            initDailyChart();
        }

        function showWeeklyChart(btn) {
            currentChartType = 'weekly';
            setActive(btn);
            initWeeklyChart();
        }

        function showMonthlyChart(btn) {
            currentChartType = 'monthly';
            setActive(btn);
            initMonthlyChart();
        }

        function toggleYearSelect(type) {
            const wrap = document.getElementById('yearSelectWrap');
            const btnMonthly = document.getElementById('btnMonthly');

            if (type === 'yearly') {
                wrap.style.display = '';
                btnMonthly.style.display = '';
            } else {
                wrap.style.display = 'none';
                btnMonthly.style.display = 'none';
            }
        }

        // Initialize all charts on page load
        document.addEventListener('DOMContentLoaded', function() {
            initComplaintChart();
            initClassesChart();

            // Default chart selection
            if (REPORT_TYPE === 'yearly') {
                document.getElementById('btnMonthly').style.display = '';
                setActive(document.getElementById('btnMonthly'));
                initMonthlyChart();
            } else {
                // default daily
                setActive(document.getElementById('btnDaily'));
                initDailyChart();
            }

            // Animate stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.opacity = '0';
                card.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    card.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    card.style.opacity = '1';
                    card.style.transform = 'translateY(0)';
                }, index * 100);
            });

            // Animate chart containers
            const chartContainers = document.querySelectorAll('.chart-container');
            chartContainers.forEach((container, index) => {
                container.style.opacity = '0';
                container.style.transform = 'translateY(20px)';
                setTimeout(() => {
                    container.style.transition = 'opacity 0.5s ease, transform 0.5s ease';
                    container.style.opacity = '1';
                    container.style.transform = 'translateY(0)';
                }, 300 + (index * 100));
            });
        });
    </script>
</body>
</html>

<?php
if (isset($conn)) $conn->close();
?>
