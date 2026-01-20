<?php
// Database connection
include '../includes/conn.php';
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

/* ===========================
   ✅ Background Image Setup
   =========================== */
$web_path = '/clinic/assets/pictures/format.png';
$server_path = $_SERVER['DOCUMENT_ROOT'] . $web_path;
$image_exists = file_exists($server_path);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

/* ===========================
   ✅ Filters (DEFAULT: TODAY)
   =========================== */
$report_type   = isset($_GET['report_type']) ? $_GET['report_type'] : 'today';
$grade_section = isset($_GET['grade_section']) ? $_GET['grade_section'] : '';

if ($report_type === 'today') {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d');
    $end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
} else {
    $start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
    $end_date   = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
}

/* ===========================
   ✅ Pagination (UI) + Persist per_page
   =========================== */
$allowed_per_page = [10, 25, 50, 100];
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($records_per_page, $allowed_per_page, true)) {
    $records_per_page = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

/* ===========================
   ✅ TOTAL RECORDS (FILTERED)
   =========================== */
$count_sql    = "SELECT COUNT(*) as total FROM clinic_log WHERE date BETWEEN ? AND ?";
$count_params = [$start_date, $end_date];
$count_types  = "ss";

if (!empty($grade_section)) {
    $count_sql .= " AND grade_section = ?";
    $count_params[] = $grade_section;
    $count_types .= "s";
}

$count_stmt = $conn->prepare($count_sql);
$count_stmt->bind_param($count_types, ...$count_params);
$count_stmt->execute();
$count_result  = $count_stmt->get_result();
$total_row     = $count_result->fetch_assoc();
$total_records = (int)($total_row['total'] ?? 0);
$count_stmt->close();

/* ===========================
   ✅ TOTAL RECORDS (ALL - SAME DATES, NO GRADE FILTER)
   Used for Percentage card denominator
   =========================== */
$total_all_sql  = "SELECT COUNT(*) as total_all FROM clinic_log WHERE date BETWEEN ? AND ?";
$total_all_stmt = $conn->prepare($total_all_sql);
$total_all_stmt->bind_param("ss", $start_date, $end_date);
$total_all_stmt->execute();
$total_all_result  = $total_all_stmt->get_result();
$total_all_row     = $total_all_result->fetch_assoc();
$total_all_records = (int)($total_all_row['total_all'] ?? 0);
$total_all_stmt->close();

/* ===========================
   ✅ Total Number of Classes (CARD)
   UNIQUE DATES overall in current filter
   =========================== */
$card_classes_sql = "SELECT COUNT(DISTINCT DATE(date)) as total_classes
                     FROM clinic_log
                     WHERE date BETWEEN ? AND ?
                     AND grade_section IS NOT NULL
                     AND grade_section != ''";
$card_classes_params = [$start_date, $end_date];
$card_classes_types  = "ss";

if (!empty($grade_section)) {
    $card_classes_sql .= " AND grade_section = ?";
    $card_classes_params[] = $grade_section;
    $card_classes_types .= "s";
}

$card_classes_stmt = $conn->prepare($card_classes_sql);
$card_classes_stmt->bind_param($card_classes_types, ...$card_classes_params);
$card_classes_stmt->execute();
$card_classes_result = $card_classes_stmt->get_result();
$card_classes_row = $card_classes_result->fetch_assoc();
$total_classes = (int)($card_classes_row['total_classes'] ?? 0);
$card_classes_stmt->close();

/* ===========================
   ✅ Average Users Visits per Day
   =========================== */
$days_diff     = max(1, (int)ceil((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1);
$average_daily = $total_records > 0 ? round($total_records / $days_diff, 1) : 0;

/* ===========================
   ✅ Percentage Card
   =========================== */
$percentage_value = 0;
if ($total_all_records > 0) {
    $percentage_value = (int)round(($total_records / $total_all_records) * 100);
}

/* ===========================
   ✅ Chart: Daily visits trend
   =========================== */
$chart_sql = "SELECT DATE(date) as visit_date, COUNT(*) as visit_count
              FROM clinic_log
              WHERE date BETWEEN ? AND ?";

$chart_params = [$start_date, $end_date];
$chart_types  = "ss";

if (!empty($grade_section)) {
    $chart_sql .= " AND grade_section = ?";
    $chart_params[] = $grade_section;
    $chart_types .= "s";
}

$chart_sql .= " GROUP BY DATE(date) ORDER BY date ASC";

$chart_stmt = $conn->prepare($chart_sql);
$chart_stmt->bind_param($chart_types, ...$chart_params);
$chart_stmt->execute();
$chart_result = $chart_stmt->get_result();

$chart_labels = [];
$chart_data   = [];
while ($chart_row = $chart_result->fetch_assoc()) {
    $chart_labels[] = date('M d', strtotime($chart_row['visit_date']));
    $chart_data[]   = (int)$chart_row['visit_count'];
}
$chart_stmt->close();

/* ===========================
   ✅ Pie: Distribution by class
   =========================== */
$pie_sql = "SELECT grade_section, COUNT(*) as section_count
            FROM clinic_log
            WHERE date BETWEEN ? AND ?
            AND grade_section IS NOT NULL
            AND grade_section != ''";

$pie_params = [$start_date, $end_date];
$pie_types  = "ss";

if (!empty($grade_section)) {
    $pie_sql .= " AND grade_section = ?";
    $pie_params[] = $grade_section;
    $pie_types .= "s";
}

$pie_sql .= " GROUP BY grade_section ORDER BY section_count DESC LIMIT 10";

$pie_stmt = $conn->prepare($pie_sql);
$pie_stmt->bind_param($pie_types, ...$pie_params);
$pie_stmt->execute();
$pie_result = $pie_stmt->get_result();

$pie_labels = [];
$pie_data   = [];
$pie_colors = [];

$color_palette = [
    '#4361ee', '#3a56d4', '#4cc9f0', '#4895ef', '#560bad',
    '#7209b7', '#b5179e', '#f72585', '#7209b7', '#3a0ca3'
];

$color_index = 0;
while ($pie_row = $pie_result->fetch_assoc()) {
    $pie_labels[] = $pie_row['grade_section'];
    $pie_data[]   = (int)$pie_row['section_count'];
    $pie_colors[] = $color_palette[$color_index % count($color_palette)];
    $color_index++;
}
$pie_stmt->close();

/* ===========================
   ✅ Pagination fix
   =========================== */
$total_pages = ($records_per_page > 0) ? (int)ceil($total_records / $records_per_page) : 1;
if ($total_pages < 1) $total_pages = 1;

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

/* ===========================
   ✅ Main logs query (FILTERED + PAGINATED)
   =========================== */
$logs_sql = "
    SELECT id, clinic_id, name, grade_section, date, time
    FROM clinic_log
    WHERE date BETWEEN ? AND ?
";
$logs_params = [$start_date, $end_date];
$logs_types  = "ss";

if (!empty($grade_section)) {
    $logs_sql .= " AND grade_section = ?";
    $logs_params[] = $grade_section;
    $logs_types .= "s";
}

$logs_sql .= " ORDER BY date DESC, time DESC LIMIT ? OFFSET ?";
$logs_params[] = $records_per_page;
$logs_params[] = $offset;
$logs_types   .= "ii";

$logs_stmt = $conn->prepare($logs_sql);
$logs_stmt->bind_param($logs_types, ...$logs_params);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();

$start_number = ($page - 1) * $records_per_page + 1;

/* ===========================
   ✅ Dropdown: grade sections
   =========================== */
$all_grades_sql = "SELECT DISTINCT grade_section
                   FROM clinic_log
                   WHERE date BETWEEN ? AND ?
                   AND grade_section IS NOT NULL
                   AND grade_section != ''
                   ORDER BY grade_section ASC";
$all_grades_stmt = $conn->prepare($all_grades_sql);
$all_grades_stmt->bind_param("ss", $start_date, $end_date);
$all_grades_stmt->execute();
$all_grades_result = $all_grades_stmt->get_result();

$grade_sections = [];
if ($all_grades_result && $all_grades_result->num_rows > 0) {
    while ($row = $all_grades_result->fetch_assoc()) {
        $grade_sections[] = $row;
    }
}
$all_grades_stmt->close();

/* ===========================
   ✅ Grade Level Statistics Table
   =========================== */
$gradeStatsQuery = "
SELECT 
    SUBSTRING_INDEX(grade_section, ' - ', 1) as grade_level,
    COUNT(DISTINCT DATE(date)) as total_classes,
    COUNT(DISTINCT name) as total_users,
    CONCAT(ROUND((COUNT(*) * 100.0 / NULLIF(?, 0)), 2), '%') as percentage
FROM clinic_log
WHERE date BETWEEN ? AND ?
  AND grade_section IS NOT NULL
  AND grade_section != ''
";

$gradeStatsParams = [(int)$total_records, $start_date, $end_date];
$gradeStatsTypes  = "iss";

if (!empty($grade_section)) {
    $gradeStatsQuery .= " AND grade_section = ?";
    $gradeStatsParams[] = $grade_section;
    $gradeStatsTypes .= "s";
}

$gradeStatsQuery .= "
GROUP BY SUBSTRING_INDEX(grade_section, ' - ', 1)
ORDER BY 
    CASE SUBSTRING_INDEX(grade_section, ' - ', 1)
        WHEN 'Kindergarten' THEN 0
        WHEN 'Grade 1' THEN 1
        WHEN 'Grade 2' THEN 2
        WHEN 'Grade 3' THEN 3
        WHEN 'Grade 4' THEN 4
        WHEN 'Grade 5' THEN 5
        WHEN 'Grade 6' THEN 6
        WHEN 'Grade 7' THEN 7
        WHEN 'Grade 8' THEN 8
        WHEN 'Grade 9' THEN 9
        WHEN 'Grade 10' THEN 10
        WHEN 'Grade 11' THEN 11
        WHEN 'Grade 12' THEN 12
        ELSE 99
    END
";

$gradeStatsStmt = $conn->prepare($gradeStatsQuery);
$gradeStatsStmt->bind_param($gradeStatsTypes, ...$gradeStatsParams);
$gradeStatsStmt->execute();
$gradeStatsResult = $gradeStatsStmt->get_result();

$gradeStats = [];
if ($gradeStatsResult) {
    while ($row = $gradeStatsResult->fetch_assoc()) {
        $gradeStats[] = $row;
    }
}
$gradeStatsStmt->close();

/* ===========================
   ✅ TOTAL ROW (unique days overall, distinct users overall)
   =========================== */
$total_row_sql = "
    SELECT 
        COUNT(DISTINCT DATE(date)) as total_unique_days,
        COUNT(DISTINCT name) as total_unique_users
    FROM clinic_log
    WHERE date BETWEEN ? AND ?
      AND grade_section IS NOT NULL
      AND grade_section != ''
";
$total_row_params = [$start_date, $end_date];
$total_row_types  = "ss";

if (!empty($grade_section)) {
    $total_row_sql .= " AND grade_section = ?";
    $total_row_params[] = $grade_section;
    $total_row_types .= "s";
}

$total_row_stmt = $conn->prepare($total_row_sql);
$total_row_stmt->bind_param($total_row_types, ...$total_row_params);
$total_row_stmt->execute();
$total_row_result = $total_row_stmt->get_result();
$total_row_data   = $total_row_result->fetch_assoc();

$total_unique_days  = (int)($total_row_data['total_unique_days'] ?? 0);
$total_unique_users = (int)($total_row_data['total_unique_users'] ?? 0);

$total_row_stmt->close();

/* ===========================
   ✅ Helper: shown now
   =========================== */
$shown_now = max(0, min($records_per_page, $total_records - $offset));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Logs Report</title>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/css/reportslogs.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<style>
/* ✅ REMOVED MAIN PAGE BACKGROUND WATERMARK COMPLETELY */

.main-content.report-background{
    width:100%;
}
.page-container{
    width:100%;
    max-width: 1320px;
    margin: 0 auto;
    padding: 18px 18px 40px;
}

.table-controls{
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
}
.controls-left{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.controls-right{
    display:flex;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
    margin-left:auto;
}
.per-page-wrap{
    display:flex;
    align-items:center;
    gap:8px;
    white-space:nowrap;
}
.per-page-wrap select{
    min-width:90px;
}

.pagination{
    display:flex;
    justify-content:center;
    align-items:center;
    gap:10px;
    flex-wrap:wrap;
}
.pagination-info{
    text-align:center;
}

@media print {
    @page { size: A4; margin: 0; }
    body{
        margin:0 !important;
        padding:0 !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
        background: none !important;
    }
    .no-print{ display:none !important; }
    .page-container{ max-width: none; padding:0; }
}
</style>
</head>
<body>

<div class="main-content report-background">
    <div class="report-content">
        <div class="page-container">

            <!-- Header -->
            <div class="header no-print">
                <h1><i class="fas fa-clipboard-list"></i> Records Logs Reports </h1>
                <p>Comprehensive analysis of clinic visits with charts and statistics</p>
                <div class="table-actions">
                    <button class="action-btn print" onclick="printWholePage()">
                        <i class="fas fa-print"></i> Print Page
                    </button>
                </div>
            </div>

            <!-- Date Range Filter -->
            <div class="date-range-filter no-print">
                <div class="filter-title">
                    <i class="fas fa-filter"></i> Filter Options
                </div>

                <div class="filter-row">
                    <div class="filter-group">
                        <label for="startDate">Start Date</label>
                        <input type="date" id="startDate" class="filter-input" value="<?php echo htmlspecialchars($start_date); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="endDate">End Date</label>
                        <input type="date" id="endDate" class="filter-input" value="<?php echo htmlspecialchars($end_date); ?>">
                    </div>

                    <div class="filter-group">
                        <label for="reportType">Report Type</label>
                        <select id="reportType" class="filter-select">
                            <option value="today"   <?php echo $report_type == 'today'   ? 'selected' : ''; ?>>Today's Report</option>
                            <option value="weekly"  <?php echo $report_type == 'weekly'  ? 'selected' : ''; ?>>Weekly Analysis</option>
                            <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly Analysis</option>
                            <option value="yearly"  <?php echo $report_type == 'yearly'  ? 'selected' : ''; ?>>Yearly Analysis</option>
                        </select>
                    </div>
                </div>

                <div class="filter-buttons">
                    <button class="btn btn-primary" onclick="applyFilters()">
                        <i class="fas fa-check"></i> Apply Filter
                    </button>
                    <button class="btn btn-secondary" onclick="resetDateFilter()">
                        <i class="fas fa-redo"></i> Reset
                    </button>
                </div>
            </div>

            <!-- Stats Cards -->
            <div class="stats-cards no-print">
                <div class="stat-card total-classes">
                    <div class="stat-icon"><i class="fas fa-school"></i></div>
                    <div class="stat-number"><?php echo (int)$total_classes; ?></div>
                    <div class="stat-label">Total Number of Classes</div>
                </div>

                <div class="stat-card average-daily">
                    <div class="stat-icon"><i class="fas fa-calendar-day"></i></div>
                    <div class="stat-number"><?php echo $average_daily; ?></div>
                    <div class="stat-label">Average Users Visits</div>
                </div>

                <div class="stat-card average-weekly">
                    <div class="stat-icon"><i class="fas fa-users"></i></div>
                    <div class="stat-number"><?php echo (int)$percentage_value; ?>%</div>
                    <div class="stat-label">Percentage</div>
                </div>
            </div>

            <!-- Grade Level Statistics Table -->
            <div class="grade-stats-section no-print">
                <div class="section-title" style="margin-bottom: 0; border-radius: 8px 8px 0 0;">
                    <div>
                        <i class="fas fa-graduation-cap"></i>
                        Grade Level Statistics for <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                        <?php if (!empty($grade_section)): ?>
                            <span style="font-size: 14px; color: #eaf2ff; margin-left: 10px;">
                                (Filtered by: <?php echo htmlspecialchars($grade_section); ?>)
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="table-actions">
                        <button class="action-btn print" onclick="printGradeStats()">
                            <i class="fas fa-print"></i> Print Grade Stats
                        </button>
                    </div>
                </div>

                <div class="table-container">
                    <table class="grade-stats-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Grade Level</th>
                                <th>Total Numbers of Classes</th>
                                <th>Total Numbers of Users</th>
                                <th>Percentage</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!empty($gradeStats)): ?>
                            <?php $id = 1; ?>
                            <?php foreach ($gradeStats as $stat): ?>
                                <?php
                                $percentageNum = (float)str_replace('%', '', $stat['percentage']);
                                $percentageClass = 'percentage-low';
                                if ($percentageNum > 20) $percentageClass = 'percentage-high';
                                elseif ($percentageNum > 10) $percentageClass = 'percentage-medium';
                                ?>
                                <tr>
                                    <td><?php echo $id++; ?></td>
                                    <td><strong><?php echo htmlspecialchars($stat['grade_level']); ?></strong></td>
                                    <td><?php echo (int)$stat['total_classes']; ?></td>
                                    <td><?php echo (int)$stat['total_users']; ?></td>
                                    <td class="<?php echo $percentageClass; ?>"><?php echo htmlspecialchars($stat['percentage']); ?></td>
                                </tr>
                            <?php endforeach; ?>

                            <tr class="total-row">
                                <td></td>
                                <td><strong>TOTAL</strong></td>
                                <td><strong><?php echo (int)$total_unique_days; ?></strong></td>
                                <td><strong><?php echo (int)$total_unique_users; ?></strong></td>
                                <td><strong><?php echo ($total_records > 0 ? '100.00' : '0.00'); ?>%</strong></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td colspan="5" style="text-align:center; padding:40px; color:#7f8c8d;">
                                    No grade level statistics found for the selected period.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Charts -->
            <div class="chart-section no-print">
                <div class="chart-title">
                    <i class="fas fa-chart-line"></i> Statistics Report with Charts
                </div>

                <div class="charts-container">
                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-chart-line"></i> Daily Visits Trend</h3>
                            <span class="table-info"><?php echo count($chart_data); ?> days analyzed</span>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="dailyVisitsChart"></canvas>
                        </div>
                    </div>

                    <div class="chart-card">
                        <div class="chart-header">
                            <h3><i class="fas fa-chart-pie"></i> Distribution by Class</h3>
                            <span class="table-info">Top <?php echo count($pie_labels); ?> classes</span>
                        </div>
                        <div class="chart-wrapper">
                            <canvas id="classDistributionChart"></canvas>
                        </div>

                        <?php if(!empty($pie_labels)): ?>
                        <div class="chart-legend">
                            <?php foreach($pie_labels as $index => $label): ?>
                            <div class="legend-item">
                                <div class="legend-color" style="background-color: <?php echo $pie_colors[$index]; ?>"></div>
                                <span><?php echo htmlspecialchars($label); ?> (<?php echo (int)$pie_data[$index]; ?>)</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Logs Header -->
            <div class="section-title" style="margin-bottom: 0; border-radius: 8px 8px 0 0;">
                <div>
                    <i class="fas fa-calendar-week"></i>
                    Analysis for <?php echo date('F j, Y', strtotime($start_date)); ?> to <?php echo date('F j, Y', strtotime($end_date)); ?>
                    <?php if (!empty($grade_section)): ?>
                        <span style="font-size: 14px; color: #666; margin-left: 10px;">
                            (Filtered by: <?php echo htmlspecialchars($grade_section); ?>)
                        </span>
                    <?php endif; ?>
                </div>
                <div class="table-actions">
                    <button class="action-btn print" onclick="printTable()">
                        <i class="fas fa-print"></i> Print Table
                    </button>
                </div>
            </div>

            <!-- Logs Table -->
            <div class="table-container">
                <div class="table-controls no-print">
                    <div class="controls-left">
                        <input type="text" id="searchInput" class="search-box" placeholder="Search logs...">

                        <select id="gradeSectionFilter" class="grade-section-select">
                            <option value="">All Grades & Sections</option>
                            <?php if(!empty($grade_sections)): ?>
                                <?php foreach($grade_sections as $section): ?>
                                    <option value="<?php echo htmlspecialchars($section['grade_section']); ?>"
                                        <?php echo $section['grade_section'] == $grade_section ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($section['grade_section']); ?>
                                    </option>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <option value="" disabled>No grade sections found for selected date range</option>
                            <?php endif; ?>
                        </select>
                    </div>

                    <div class="controls-right">
                        <div class="per-page-wrap">
                            <span style="font-weight:600;color:#4b5563;">Show:</span>
                            <select id="recordsPerPageSelect" onchange="changeRecordsPerPage(this.value)">
                                <option value="10"  <?php echo $records_per_page == 10 ? 'selected' : ''; ?>>10</option>
                                <option value="25"  <?php echo $records_per_page == 25 ? 'selected' : ''; ?>>25</option>
                                <option value="50"  <?php echo $records_per_page == 50 ? 'selected' : ''; ?>>50</option>
                                <option value="100" <?php echo $records_per_page == 100 ? 'selected' : ''; ?>>100</option>
                            </select>
                        </div>

                        <div class="table-info">
                            <?php if (!empty($grade_section)): ?>
                                Showing <?php echo $shown_now; ?> of <?php echo (int)$total_records; ?> logs for <?php echo htmlspecialchars($grade_section); ?>
                            <?php else: ?>
                                Showing <?php echo $shown_now; ?> of <?php echo (int)$total_records; ?> logs
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="table-wrapper">
                    <?php if($logs_result && $logs_result->num_rows > 0): ?>
                    <table id="logsTable">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>Clinic ID</th>
                                <th>Name</th>
                                <th>Grade & Section</th>
                                <th>Date</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php $num = $start_number; while($row = $logs_result->fetch_assoc()): ?>
                            <tr>
                                <td><?php echo $num++; ?></td>
                                <td><?php echo htmlspecialchars($row['clinic_id']); ?></td>
                                <td><?php echo htmlspecialchars($row['name']); ?></td>
                                <td><?php echo htmlspecialchars($row['grade_section']); ?></td>
                                <td><?php echo date('Y-m-d', strtotime($row['date'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($row['time'])); ?></td>
                            </tr>
                            <?php endwhile; ?>
                        </tbody>
                    </table>
                    <?php else: ?>
                    <div class="no-data">
                        <i class="fas fa-clipboard-list"></i>
                        <h3>No logs found for selected filters</h3>
                        <p>Try selecting a different date range or section.</p>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Pagination -->
                <?php if($total_pages > 1): ?>
                <div class="pagination no-print">
                    <button class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                            onclick="changePage(<?php echo $page - 1; ?>)"
                            <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                        <i class="fas fa-chevron-left"></i> Previous
                    </button>

                    <div class="page-numbers">
                        <?php if ($page > 3): ?>
                            <button class="page-number" onclick="changePage(1)">1</button>
                            <?php if ($page > 4): ?>
                                <span class="page-number" style="border:none;background:transparent;cursor:default;">...</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <button class="page-number <?php echo $i == $page ? 'active' : ''; ?>"
                                    onclick="changePage(<?php echo $i; ?>)">
                                <?php echo $i; ?>
                            </button>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages - 2): ?>
                            <?php if ($page < $total_pages - 3): ?>
                                <span class="page-number" style="border:none;background:transparent;cursor:default;">...</span>
                            <?php endif; ?>
                            <button class="page-number" onclick="changePage(<?php echo $total_pages; ?>)">
                                <?php echo $total_pages; ?>
                            </button>
                        <?php endif; ?>
                    </div>

                    <button class="pagination-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"
                            onclick="changePage(<?php echo $page + 1; ?>)"
                            <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                        Next <i class="fas fa-chevron-right"></i>
                    </button>
                </div>

                <div class="pagination-info no-print">
                    Page <?php echo (int)$page; ?> of <?php echo (int)$total_pages; ?> •
                    Records <?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $records_per_page, $total_records); ?>
                    of <?php echo (int)$total_records; ?>
                </div>
                <?php endif; ?>

            </div>

        </div>
    </div>
</div>

<script>
const chartLabels = <?php echo json_encode($chart_labels); ?>;
const chartData   = <?php echo json_encode($chart_data); ?>;
const pieLabels   = <?php echo json_encode($pie_labels); ?>;
const pieData     = <?php echo json_encode($pie_data); ?>;
const pieColors   = <?php echo json_encode($pie_colors); ?>;

document.addEventListener('DOMContentLoaded', function() {
    // Daily Visits Line Chart
    const daily = document.getElementById('dailyVisitsChart');
    if (daily) {
        const dailyCtx = daily.getContext('2d');
        new Chart(dailyCtx, {
            type: 'line',
            data: {
                labels: chartLabels,
                datasets: [{
                    label: 'Daily Visits',
                    data: chartData,
                    borderColor: '#4361ee',
                    backgroundColor: 'rgba(67, 97, 238, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4361ee',
                    pointBorderColor: '#fff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false } },
                scales: {
                    y: { beginAtZero: true, title: { display: true, text: 'Number of Visits' }, grid: { color: 'rgba(0,0,0,0.05)' } },
                    x: { title: { display: true, text: 'Date' }, grid: { color: 'rgba(0,0,0,0.05)' }, ticks: { maxRotation: 45, minRotation: 45 } }
                }
            }
        });
    }

    // Pie Chart
    const pie = document.getElementById('classDistributionChart');
    if (pie) {
        const pieCtx = pie.getContext('2d');
        new Chart(pieCtx, {
            type: 'doughnut',
            data: {
                labels: pieLabels,
                datasets: [{
                    data: pieData,
                    backgroundColor: pieColors,
                    borderColor: '#fff',
                    borderWidth: 2,
                    hoverOffset: 15
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function(context) {
                                const label = context.label || '';
                                const value = context.parsed || 0;
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total ? Math.round((value / total) * 100) : 0;
                                return `${label}: ${value} visits (${percentage}%)`;
                            }
                        }
                    }
                },
                cutout: '60%'
            }
        });
    }

    // Search (client-side)
    const searchInput = document.getElementById('searchInput');
    const table = document.getElementById('logsTable');
    if (searchInput && table) {
        const rows = table.querySelectorAll('tbody tr');
        searchInput.addEventListener('keyup', () => {
            const value = searchInput.value.toLowerCase();
            rows.forEach(row => row.style.display = row.innerText.toLowerCase().includes(value) ? '' : 'none');
        });
    }

    // Auto apply grade/section
    const gradeSectionFilter = document.getElementById('gradeSectionFilter');
    if (gradeSectionFilter) {
        gradeSectionFilter.addEventListener('change', function() {
            setTimeout(() => { applyFilters(); }, 60);
        });
    }
});

/* ===========================
   ✅ FILTERS (keep per_page persistent)
   =========================== */
function getPerPage() {
    const sel = document.getElementById('recordsPerPageSelect');
    return sel ? sel.value : '10';
}

function buildUrl(page = 1, perPage = null) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter') ? document.getElementById('gradeSectionFilter').value : '';
    const per = perPage !== null ? perPage : getPerPage();

    let url = `?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}&report_type=${encodeURIComponent(reportType)}&page=${page}&per_page=${encodeURIComponent(per)}`;
    if (gradeSection) url += `&grade_section=${encodeURIComponent(gradeSection)}`;
    return url;
}

function applyFilters() {
    window.location.href = buildUrl(1);
}

function resetDateFilter() {
    const today = new Date().toISOString().split('T')[0];
    document.getElementById('startDate').value = today;
    document.getElementById('endDate').value = today;
    document.getElementById('reportType').value = 'today';
    if (document.getElementById('gradeSectionFilter')) document.getElementById('gradeSectionFilter').value = '';
    if (document.getElementById('recordsPerPageSelect')) document.getElementById('recordsPerPageSelect').value = '10';
    window.location.href = buildUrl(1, '10');
}

function changePage(newPage) {
    if (newPage < 1 || newPage > <?php echo (int)$total_pages; ?>) return;
    window.location.href = buildUrl(newPage);
}

function changeRecordsPerPage(perPage) {
    window.location.href = buildUrl(1, perPage);
}

/* Auto update date range based on reportType */
document.getElementById('reportType').addEventListener('change', function() {
    const reportType = this.value;
    const today = new Date();
    const formatDate = (d) => d.toISOString().split('T')[0];

    let startDate = new Date(today);
    let endDate = new Date(today);

    switch(reportType) {
        case 'today':
            break;
        case 'weekly':
            startDate.setDate(today.getDate() - 7);
            break;
        case 'monthly':
            startDate = new Date(today.getFullYear(), today.getMonth(), 1);
            break;
        case 'yearly':
            startDate = new Date(today.getFullYear(), 0, 1);
            startDate.setFullYear(today.getFullYear() - 1);
            break;
    }

    document.getElementById('startDate').value = formatDate(startDate);
    document.getElementById('endDate').value = formatDate(endDate);
});

/* ===========================
   ✅ PRINT HELPERS (UNCHANGED)
   =========================== */
function buildPagedTableHTML(tableEl, rowsPerPage = 10) {
    const thead = tableEl.querySelector('thead')?.outerHTML || '';
    const rows = Array.from(tableEl.querySelectorAll('tbody tr'));
    if (!rows.length) return [];

    const pages = [];
    for (let i = 0; i < rows.length; i += rowsPerPage) {
        const chunk = rows.slice(i, i + rowsPerPage).map(r => r.outerHTML).join('');
        pages.push(`
            <table>
                ${thead}
                <tbody>${chunk}</tbody>
            </table>
        `);
    }
    return pages;
}

function printBaseStyles(bgUrl) {
    return `
        @page{ size:A4; margin:0; }
        *{ box-sizing:border-box; font-family:'Segoe UI', Arial, sans-serif; }
        body{
            margin:0; padding:0;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
            background:white;
        }
        .print-page{
            width:210mm; height:297mm;
            position:relative;
            page-break-after:always;
            overflow:hidden;
            background:#fff;
            ${bgUrl ? `background-image:url('${bgUrl}');` : ''}
            background-size: cover;
            background-position: center top;
            background-repeat:no-repeat;
        }
        .print-page:last-child{ page-break-after:auto; }
        .print-content{ padding:22mm 18mm 42mm 18mm; }
        .print-header{
            text-align:center;
            margin-bottom:16px;
            padding:12px;
            background:rgba(255,255,255,0.95);
            border-radius:8px;
            border:2px solid #4361ee;
        }
        .print-header h1{ margin:0; font-size:22px; color:#4361ee; }
        .subtitle{ margin-top:6px; color:#666; font-size:14px; }
        .print-info{
            margin:12px 0 16px 0;
            background:rgba(255,255,255,0.95);
            border:1px solid #ddd;
            border-radius:8px;
            padding:10px 12px;
            font-size:12px;
            display:flex; justify-content:space-between;
            gap:12px; flex-wrap:wrap;
        }
        .section-title{
            margin: 14px 0 10px 0;
            background: rgba(67,97,238,0.92);
            color:#fff;
            padding:10px 12px;
            border-radius:6px;
            font-size:14px;
            font-weight:700;
        }
        table{
            width:100%;
            border-collapse:collapse;
            background:rgba(255,255,255,0.95);
            font-size:11px;
        }
        th, td{ border:1px solid #000; padding:6px; vertical-align:top; }
        th{ background:#f0f0f0; color:#000; font-weight:700; }
        tr{ page-break-inside:avoid; }
        thead{ display:table-header-group; }
        .print-footer{
            position:absolute;
            left:18mm; right:18mm; bottom:14mm;
            text-align:center;
            font-size:11px;
            color:#555;
            background:rgba(255,255,255,0.90);
            border:1px solid #ddd;
            border-radius:6px;
            padding:6px 10px;
        }
    `;
}

function printGradeStats() {
    const gradeStatsTable = document.querySelector('.grade-stats-table');
    if (!gradeStatsTable) { alert('Grade statistics table not found'); return; }

    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter') ? document.getElementById('gradeSectionFilter').value : '';

    const startDateFormatted = new Date(startDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const endDateFormatted   = new Date(endDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });

    const bgUrl = <?php echo json_encode($image_exists ? $web_path : ''); ?>;

    const win = window.open('', '', 'width=1200,height=700');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Grade Level Statistics</title>
            <style>${printBaseStyles(bgUrl)}</style>
        </head>
        <body>
            <div class="print-page">
                <div class="print-content">
                    <div class="print-header">
                        <h1>Monthly Logs Reports</h1>
                        <div class="subtitle">Comprehensive Analysis of Clinic Visits</div>
                    </div>

                    <div class="print-info">
                        <div><strong>Report Period:</strong> ${startDateFormatted}${startDate === endDate ? '' : ' to ' + endDateFormatted}</div>
                        <div><strong>Report Type:</strong> ${reportType === 'today' ? "Today's Report" : reportType.charAt(0).toUpperCase() + reportType.slice(1) + ' Analysis'}</div>
                        ${gradeSection ? `<div><strong>Class Filter:</strong> ${gradeSection}</div>` : ''}
                        <div><strong>Generated:</strong> ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
                    </div>

                    <div class="section-title">Grade Level Statistics</div>
                    ${gradeStatsTable.outerHTML}

                    <div class="print-footer">Report generated by Clinic Management System</div>
                </div>
            </div>

            <script>
                window.onload = function(){
                    window.print();
                    window.onafterprint = function(){ window.close(); }
                }
            <\/script>
        </body>
        </html>
    `);
    win.document.close();
}

function printTable() {
    const table = document.getElementById('logsTable');
    if (!table) { alert('Table not found'); return; }

    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter').value;

    const startDateFormatted = new Date(startDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const endDateFormatted   = new Date(endDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });

    const bgUrl = <?php echo json_encode($image_exists ? $web_path : ''); ?>;

    const pages = buildPagedTableHTML(table, 10);
    if (!pages.length) { alert('No rows to print'); return; }

    const htmlPages = pages.map((pageTable, idx) => `
        <div class="print-page">
            <div class="print-content">
                <div class="print-header">
                    <h1>Monthly Logs Reports</h1>
                    <div class="subtitle">Comprehensive Analysis of Clinic Visits</div>
                </div>

                <div class="print-info">
                    <div><strong>Report Period:</strong> ${startDateFormatted}${startDate === endDate ? '' : ' to ' + endDateFormatted}</div>
                    <div><strong>Report Type:</strong> ${reportType === 'today' ? "Today's Report" : reportType.charAt(0).toUpperCase() + reportType.slice(1) + ' Analysis'}</div>
                    ${gradeSection ? `<div><strong>Class Filter:</strong> ${gradeSection}</div>` : ''}
                    <div><strong>Generated:</strong> ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
                </div>

                <div class="section-title">Clinic Visits Log (Page ${idx + 1} of ${pages.length})</div>
                ${pageTable}

                <div class="print-footer">Report generated by Clinic Management System</div>
            </div>
        </div>
    `).join('');

    const win = window.open('', '', 'width=1200,height=700');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Clinic Visits Log</title>
            <style>${printBaseStyles(bgUrl)}</style>
        </head>
        <body>
            ${htmlPages}
            <script>
                window.onload = function(){
                    window.print();
                    window.onafterprint = function(){ window.close(); }
                }
            <\/script>
        </body>
        </html>
    `);
    win.document.close();
}

function printWholePage() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter').value;

    const startDateFormatted = new Date(startDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });
    const endDateFormatted   = new Date(endDate).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });

    const table = document.getElementById('logsTable');
    const gradeStatsTable = document.querySelector('.grade-stats-table');
    const bgUrl = <?php echo json_encode($image_exists ? $web_path : ''); ?>;

    const logPages = table ? buildPagedTableHTML(table, 10) : [];

    let htmlPages = `
        <div class="print-page">
            <div class="print-content">
                <div class="print-header">
                    <h1>Monthly Logs Reports</h1>
                    <div class="subtitle">Comprehensive Analysis of Clinic Visits</div>
                </div>

                <div class="print-info">
                    <div><strong>Report Period:</strong> ${startDateFormatted}${startDate === endDate ? '' : ' to ' + endDateFormatted}</div>
                    <div><strong>Report Type:</strong> ${reportType === 'today' ? "Today's Report" : reportType.charAt(0).toUpperCase() + reportType.slice(1) + ' Analysis'}</div>
                    ${gradeSection ? `<div><strong>Class Filter:</strong> ${gradeSection}</div>` : ''}
                    <div><strong>Generated:</strong> ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
                </div>

                <div class="section-title">Grade Level Statistics</div>
                ${gradeStatsTable ? gradeStatsTable.outerHTML : '<div style="background:rgba(255,255,255,0.95);padding:12px;border:1px solid #000;">No grade level statistics available.</div>'}

                <div class="print-footer">Report generated by Clinic Management System</div>
            </div>
        </div>
    `;

    if (logPages.length) {
        htmlPages += logPages.map((pageTable, idx) => `
            <div class="print-page">
                <div class="print-content">
                    <div class="print-header">
                        <h1>Monthly Logs Reports</h1>
                        <div class="subtitle">Comprehensive Analysis of Clinic Visits</div>
                    </div>

                    <div class="print-info">
                        <div><strong>Report Period:</strong> ${startDateFormatted}${startDate === endDate ? '' : ' to ' + endDateFormatted}</div>
                        <div><strong>Report Type:</strong> ${reportType === 'today' ? "Today's Report" : reportType.charAt(0).toUpperCase() + reportType.slice(1) + ' Analysis'}</div>
                        ${gradeSection ? `<div><strong>Class Filter:</strong> ${gradeSection}</div>` : ''}
                        <div><strong>Generated:</strong> ${new Date().toLocaleDateString()} ${new Date().toLocaleTimeString([], {hour:'2-digit', minute:'2-digit'})}</div>
                    </div>

                    <div class="section-title">Clinic Visits Log (Page ${idx + 1} of ${logPages.length})</div>
                    ${pageTable}

                    <div class="print-footer">Report generated by Clinic Management System</div>
                </div>
            </div>
        `).join('');
    }

    const win = window.open('', '', 'width=1200,height=700');
    win.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Monthly Logs Report</title>
            <style>${printBaseStyles(bgUrl)}</style>
        </head>
        <body>
            ${htmlPages}
            <script>
                window.onload = function(){
                    window.print();
                    window.onaffterprint = function(){ window.close(); }
                }
            <\/script>
        </body>
        </html>
    `);
    win.document.close();
}
</script>

</body>
</html>
<?php if(isset($logs_stmt)) $logs_stmt->close(); ?>
<?php if(isset($conn)) $conn->close(); ?>
