<?php
// Database connection
include '../includes/conn.php'; 
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filter parameters - FIXED: Default to 1st of current month
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01'); // 1st of current month
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d'); // Today
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';
$grade_section = isset($_GET['grade_section']) ? $_GET['grade_section'] : '';

// Pagination parameters
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 10;
if (!in_array($records_per_page, [10, 25, 50, 100])) {
    $records_per_page = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;
$offset = ($page - 1) * $records_per_page;

// Get total count for pagination
$count_sql = "SELECT COUNT(*) as total FROM clinic_log WHERE date BETWEEN ? AND ?";
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

// NEW: Calculate total number of classes (distinct grade_sections)
$classes_sql = "SELECT COUNT(DISTINCT grade_section) as total_classes FROM clinic_log WHERE date BETWEEN ? AND ? AND grade_section IS NOT NULL AND grade_section != ''";
$classes_params = array($start_date, $end_date);
$classes_types = "ss";

if (!empty($grade_section)) {
    $classes_sql .= " AND grade_section = ?";
    $classes_params[] = $grade_section;
    $classes_types .= "s";
}

$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->bind_param($classes_types, ...$classes_params);
$classes_stmt->execute();
$classes_result = $classes_stmt->get_result();
$classes_row = $classes_result->fetch_assoc();
$total_classes = $classes_row['total_classes'] ?? 0;
$classes_stmt->close();

// NEW: Calculate average daily visits
$days_diff = max(1, ceil((strtotime($end_date) - strtotime($start_date)) / (60 * 60 * 24)) + 1);
$average_daily = $total_records > 0 ? round($total_records / $days_diff, 1) : 0;

// NEW: Calculate average users per week
$weeks_sql = "SELECT COUNT(DISTINCT clinic_id) as weekly_users FROM clinic_log WHERE date BETWEEN ? AND ?";
$weeks_params = array($start_date, $end_date);
$weeks_types = "ss";

if (!empty($grade_section)) {
    $weeks_sql .= " AND grade_section = ?";
    $weeks_params[] = $grade_section;
    $weeks_types .= "s";
}

$weeks_stmt = $conn->prepare($weeks_sql);
$weeks_stmt->bind_param($weeks_types, ...$weeks_params);
$weeks_stmt->execute();
$weeks_result = $weeks_stmt->get_result();
$weeks_row = $weeks_result->fetch_assoc();
$weekly_users = $weeks_row['weekly_users'] ?? 0;
$weeks_stmt->close();

// NEW: Get data for chart - Daily visits trend
$chart_sql = "SELECT DATE(date) as visit_date, COUNT(*) as visit_count 
              FROM clinic_log 
              WHERE date BETWEEN ? AND ?";
$chart_params = array($start_date, $end_date);
$chart_types = "ss";

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
$chart_data = [];
$chart_dates = [];

while ($chart_row = $chart_result->fetch_assoc()) {
    $chart_dates[] = $chart_row['visit_date'];
    $chart_labels[] = date('M d', strtotime($chart_row['visit_date']));
    $chart_data[] = $chart_row['visit_count'];
}

$chart_stmt->close();

// NEW: Get data for pie chart - Distribution by grade section
$pie_sql = "SELECT grade_section, COUNT(*) as section_count 
            FROM clinic_log 
            WHERE date BETWEEN ? AND ? AND grade_section IS NOT NULL AND grade_section != ''";
$pie_params = array($start_date, $end_date);
$pie_types = "ss";

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
$pie_data = [];
$pie_colors = [];

// Color palette for pie chart
$color_palette = [
    '#4361ee', '#3a56d4', '#4cc9f0', '#4895ef', '#560bad',
    '#7209b7', '#b5179e', '#f72585', '#7209b7', '#3a0ca3'
];

$color_index = 0;
while ($pie_row = $pie_result->fetch_assoc()) {
    $pie_labels[] = $pie_row['grade_section'];
    $pie_data[] = $pie_row['section_count'];
    $pie_colors[] = $color_palette[$color_index % count($color_palette)];
    $color_index++;
}

$pie_stmt->close();

// Calculate total pages
$total_pages = ceil($total_records / $records_per_page);
if ($page > $total_pages && $total_pages > 0) {
    $page = $total_pages;
    $offset = ($page - 1) * $records_per_page;
}

// Main query to get paginated results
$logs_sql = "
    SELECT id, clinic_id, name, grade_section, date, time
    FROM clinic_log
    WHERE date BETWEEN ? AND ?
";

$logs_params = array($start_date, $end_date);
$logs_types = "ss";

if (!empty($grade_section)) {
    $logs_sql .= " AND grade_section = ?";
    $logs_params[] = $grade_section;
    $logs_types .= "s";
}

$logs_sql .= " ORDER BY date DESC, time DESC LIMIT ? OFFSET ?";
$logs_params[] = $records_per_page;
$logs_params[] = $offset;
$logs_types .= "ii";

$logs_stmt = $conn->prepare($logs_sql);
$logs_stmt->bind_param($logs_types, ...$logs_params);
$logs_stmt->execute();
$logs_result = $logs_stmt->get_result();

// Calculate starting number
$start_number = ($page - 1) * $records_per_page + 1;

// Get all grade sections for filter dropdown
$all_grades_sql = "SELECT DISTINCT grade_section FROM clinic_log WHERE grade_section IS NOT NULL AND grade_section != '' ORDER BY grade_section ASC";
$all_grades_result = $conn->query($all_grades_sql);
$grade_sections = [];
if ($all_grades_result && $all_grades_result->num_rows > 0) {
    while($row = $all_grades_result->fetch_assoc()) {
        $grade_sections[] = $row;
    }
    $all_grades_result->data_seek(0);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Logs Report</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<!-- Chart.js for statistics -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
/* ... (keep all your existing CSS styles) ... */

/* New Chart Section Styles */
.chart-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    margin-bottom: 25px;
    border: 1px solid #eaeaea;
}

.chart-title {
    font-size: 20px;
    color: #2c3e50;
    margin-bottom: 25px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title i {
    color: #4a6bff;
}

.charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 25px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .charts-container {
        grid-template-columns: 1fr;
    }
}

.chart-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #eaeaea;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.chart-header h3 {
    font-size: 16px;
    color: #4361ee;
    font-weight: 600;
}

.chart-wrapper {
    position: relative;
    height: 300px;
    width: 100%;
}

/* Update Stats Cards Styles */
.stat-card.total-classes {
    background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
    color: white;
}

.stat-card.total-classes .stat-number,
.stat-card.total-classes .stat-label {
    color: white;
}

.stat-card.total-classes .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

.stat-card.average-daily {
    background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
    color: white;
}

.stat-card.average-daily .stat-number,
.stat-card.average-daily .stat-label {
    color: white;
}

.stat-card.average-daily .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

.stat-card.average-weekly {
    background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
    color: white;
}

.stat-card.average-weekly .stat-number,
.stat-card.average-weekly .stat-label {
    color: white;
}

.stat-card.average-weekly .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

/* Chart Legend */
.chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #666;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}
</style>
</head>
<body>
<div class="main-content">

    <!-- Header -->
    <div class="header no-print">
        <h1><i class="fas fa-chart-bar"></i> Statistics Report</h1>
        <p>Comprehensive analysis of clinic visits with charts and statistics</p>
        <div class="table-actions">
            <button class="action-btn print" onclick="window.print()">
                <i class="fas fa-print"></i> Print Whole Page
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
                <input type="date" id="startDate" class="filter-input" value="<?php echo $start_date; ?>">
            </div>
            
            <div class="filter-group">
                <label for="endDate">End Date</label>
                <input type="date" id="endDate" class="filter-input" value="<?php echo $end_date; ?>">
            </div>
            
            <div class="filter-group">
                <label for="reportType">Report Type</label>
                <select id="reportType" class="filter-select">
                    <option value="weekly" <?php echo $report_type == 'weekly' ? 'selected' : ''; ?>>Weekly Analysis</option>
                    <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>Monthly Analysis</option>
                    <option value="yearly" <?php echo $report_type == 'yearly' ? 'selected' : ''; ?>>Yearly Analysis</option>
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

    <!-- Stats Cards - UPDATED -->
    <div class="stats-cards no-print">
        <div class="stat-card total-classes">
            <div class="stat-icon">
                <i class="fas fa-school"></i>
            </div>
            <div class="stat-number"><?php echo $total_classes; ?></div>
            <div class="stat-label">Total Number of Classes</div>
        </div>
        
        <div class="stat-card average-daily">
            <div class="stat-icon">
                <i class="fas fa-calendar-day"></i>
            </div>
            <div class="stat-number"><?php echo $average_daily; ?></div>
            <div class="stat-label">Average Daily Visits</div>
        </div>
        
        <div class="stat-card average-weekly">
            <div class="stat-icon">
                <i class="fas fa-users"></i>
            </div>
            <div class="stat-number"><?php echo $weekly_users; ?></div>
            <div class="stat-label">Average # of Users per Week</div>
        </div>
    </div>

    <!-- NEW: Chart Section -->
    <div class="chart-section no-print">
        <div class="chart-title">
            <i class="fas fa-chart-line"></i> Statistics Report with Charts
        </div>
        
        <div class="charts-container">
            <!-- Line Chart: Daily Visits Trend -->
            <div class="chart-card">
                <div class="chart-header">
                    <h3><i class="fas fa-chart-line"></i> Daily Visits Trend</h3>
                    <span class="table-info"><?php echo count($chart_data); ?> days analyzed</span>
                </div>
                <div class="chart-wrapper">
                    <canvas id="dailyVisitsChart"></canvas>
                </div>
            </div>
            
            <!-- Pie Chart: Distribution by Grade Section -->
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
                        <span><?php echo htmlspecialchars($label); ?> (<?php echo $pie_data[$index]; ?>)</span>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Summary Statistics -->
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee;">
            <div style="text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #4361ee;"><?php echo $total_records; ?></div>
                <div style="font-size: 14px; color: #666;">Total Records</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #4cc9f0;"><?php echo $days_diff; ?></div>
                <div style="font-size: 14px; color: #666;">Days Analyzed</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #f72585;"><?php echo $average_daily; ?></div>
                <div style="font-size: 14px; color: #666;">Avg. Daily</div>
            </div>
            <div style="text-align: center;">
                <div style="font-size: 24px; font-weight: bold; color: #7209b7;"><?php echo $weekly_users; ?></div>
                <div style="font-size: 14px; color: #666;">Weekly Users</div>
            </div>
        </div>
    </div>

    <!-- Date Range Info -->
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
            <div class="search-section">
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
                    <?php endif; ?>
                </select>
            </div>
            
            <div class="table-info">
                <?php if (!empty($grade_section)): ?>
                    Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> logs for <?php echo htmlspecialchars($grade_section); ?>
                    (<?php echo $records_per_page; ?> per page)
                <?php else: ?>
                    Showing <?php echo min($records_per_page, $total_records - $offset); ?> of <?php echo $total_records; ?> logs
                    (<?php echo $records_per_page; ?> per page)
                <?php endif; ?>
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
            
            <!-- Records per page selector -->
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
    </div>

</div>

<script>
// Chart data from PHP
const chartLabels = <?php echo json_encode($chart_labels); ?>;
const chartData = <?php echo json_encode($chart_data); ?>;
const pieLabels = <?php echo json_encode($pie_labels); ?>;
const pieData = <?php echo json_encode($pie_data); ?>;
const pieColors = <?php echo json_encode($pie_colors); ?>;

// Initialize charts when page loads
document.addEventListener('DOMContentLoaded', function() {
    // Daily Visits Line Chart
    const dailyCtx = document.getElementById('dailyVisitsChart').getContext('2d');
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
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    title: {
                        display: true,
                        text: 'Number of Visits'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                x: {
                    title: {
                        display: true,
                        text: 'Date'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    },
                    ticks: {
                        maxRotation: 45,
                        minRotation: 45
                    }
                }
            }
        }
    });

    // Class Distribution Pie Chart
    const pieCtx = document.getElementById('classDistributionChart').getContext('2d');
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
                legend: {
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = Math.round((value / total) * 100);
                            return `${label}: ${value} visits (${percentage}%)`;
                        }
                    }
                }
            },
            cutout: '60%'
        }
    });

    // Search function for logs table
    const searchInput = document.getElementById('searchInput');
    const rows = document.querySelectorAll('#logsTable tbody tr');
    
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
    
    // Sidebar toggle handler
    setupSidebarToggle();
});

// Function to handle sidebar toggle
function setupSidebarToggle() {
    // Look for sidebar toggle button
    const toggleBtn = document.querySelector('.sidebar-toggle, .toggle-btn, [data-toggle="sidebar"]');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (toggleBtn && sidebar && mainContent) {
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            
            // Add or remove sidebar-collapsed class to body
            if (sidebar.classList.contains('collapsed')) {
                document.body.classList.add('sidebar-collapsed');
                // If you need to store the state in localStorage
                localStorage.setItem('sidebarCollapsed', 'true');
            } else {
                document.body.classList.remove('sidebar-collapsed');
                localStorage.setItem('sidebarCollapsed', 'false');
            }
        });
        
        // Check localStorage for saved sidebar state
        if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            document.body.classList.add('sidebar-collapsed');
        }
    }
    
    // Also handle window resize
    window.addEventListener('resize', function() {
        if (window.innerWidth < 768) {
            const sidebar = document.querySelector('.sidebar');
            if (sidebar && !sidebar.classList.contains('collapsed')) {
                sidebar.classList.add('collapsed');
                document.body.classList.add('sidebar-collapsed');
            }
        }
    });
}

// Existing functions (keep as is)
function applyFilters() {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter').value;
    const perPageSelect = document.getElementById('recordsPerPageSelect');
    const currentPerPage = perPageSelect ? perPageSelect.value : 10;
    
    let url = `?start_date=${startDate}&end_date=${endDate}&report_type=${reportType}&page=1&per_page=${currentPerPage}`;
    
    if (gradeSection) {
        url += `&grade_section=${gradeSection}`;
    }
    
    window.location.href = url;
}

// FIXED: Reset function now sets date to 1st of current month
function resetDateFilter() {
    const today = new Date().toISOString().split('T')[0];
    
    // Calculate start date as 1st of current month
    const startDate = new Date();
    startDate.setDate(1);
    const startDateStr = startDate.toISOString().split('T')[0];
    
    document.getElementById('startDate').value = startDateStr;
    document.getElementById('endDate').value = today;
    document.getElementById('reportType').value = 'monthly';
    document.getElementById('gradeSectionFilter').value = '';
    
    const perPageSelect = document.getElementById('recordsPerPageSelect');
    if (perPageSelect) {
        perPageSelect.value = '10';
    }
    
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

function printTable() {
    const table = document.getElementById('logsTable');
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
            <title>Monthly Logs Report</title>
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
            <h1>Monthly Logs Report</h1>
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
            // Set to 1st of that month
            startDate.setDate(1);
            break;
        case 'yearly':
            startDate.setFullYear(startDate.getFullYear() - 1);
            // Set to January 1st of that year
            startDate.setMonth(0);
            startDate.setDate(1);
            break;
    }
    
    const formatDate = (date) => {
        return date.toISOString().split('T')[0];
    };
    
    startDateInput.value = formatDate(startDate);
});
</script>
</body>
</html>

<style>
/* Reset and Base Styles */
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

body {
    background-color: #f5f7fa;
    color: #333;
    line-height: 1.6;
    transition: margin-left 0.3s;
}

/* FIXED: Sidebar responsive styling */
body.sidebar-collapsed .main-content {
    margin-left: 0 !important;
    width: 100% !important;
    max-width: 100% !important;
}

body:not(.sidebar-collapsed) .main-content {
    margin-left: 250px; /* Adjust this based on your sidebar width */
    width: calc(100% - 250px);
}

/* If your sidebar is not 250px, adjust the value above to match your sidebar width */

.main-content {
    padding: 25px;
    max-width: 1800px;
    margin: 0 auto;
    transition: all 0.3s ease;
    min-height: 100vh;
}

/* When sidebar is collapsed on mobile */
@media (max-width: 768px) {
    .main-content {
        margin-left: 0 !important;
        width: 100% !important;
        padding: 15px;
    }
}

/* Header Styles */
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

/* Date Range Filter */
.date-range-filter {
    background: white;
    padding: 25px;
    border-radius: 10px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    margin-bottom: 25px;
    border: 1px solid #eaeaea;
}

.filter-title {
    font-size: 18px;
    color: #2c3e50;
    margin-bottom: 20px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-title i {
    color: #4a6bff;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.filter-group {
    display: flex;
    flex-direction: column;
}

.filter-group label {
    font-weight: 600;
    margin-bottom: 8px;
    color: #555;
    font-size: 14px;
}

.filter-input {
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    transition: border 0.3s;
}

.filter-input:focus {
    outline: none;
    border-color: #4a6bff;
    box-shadow: 0 0 0 3px rgba(74, 107, 255, 0.1);
}

.filter-select {
    padding: 12px 15px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 15px;
    background-color: white;
    cursor: pointer;
}

.filter-buttons {
    display: flex;
    gap: 15px;
    margin-top: 10px;
}

.btn {
    padding: 12px 25px;
    border: none;
    border-radius: 8px;
    font-weight: 600;
    font-size: 15px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s;
}

.btn-primary {
    background-color: #4a6bff;
    color: white;
}

.btn-primary:hover {
    background-color: #3a5bf0;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(74, 107, 255, 0.2);
}

.btn-secondary {
    background-color: #f8f9fa;
    color: #555;
    border: 1px solid #ddd;
}

.btn-secondary:hover {
    background-color: #e9ecef;
}

/* Stats Cards */
.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: white;
    padding: 25px 20px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    text-align: center;
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-5px);
}

.stat-icon {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 15px;
    font-size: 24px;
}

.stat-icon.cases {
    background-color: rgba(74, 107, 255, 0.1);
    color: #4a6bff;
}

.stat-icon.records {
    background-color: rgba(52, 152, 219, 0.1);
    color: #3498db;
}

.stat-icon.types {
    background-color: rgba(46, 204, 113, 0.1);
    color: #2ecc71;
}

.stat-number {
    font-size: 36px;
    font-weight: 700;
    color: #2c3e50;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 16px;
    color: #7f8c8d;
}

/* NEW: Updated Stats Cards Styles */
.stat-card.total-classes {
    background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
    color: white;
}

.stat-card.total-classes .stat-number,
.stat-card.total-classes .stat-label {
    color: white;
}

.stat-card.total-classes .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

.stat-card.average-daily {
    background: linear-gradient(135deg, #4cc9f0 0%, #4895ef 100%);
    color: white;
}

.stat-card.average-daily .stat-number,
.stat-card.average-daily .stat-label {
    color: white;
}

.stat-card.average-daily .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

.stat-card.average-weekly {
    background: linear-gradient(135deg, #f72585 0%, #b5179e 100%);
    color: white;
}

.stat-card.average-weekly .stat-number,
.stat-card.average-weekly .stat-label {
    color: white;
}

.stat-card.average-weekly .stat-icon {
    background-color: rgba(255, 255, 255, 0.2);
    color: white;
}

/* NEW: Chart Section Styles */
.chart-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    margin-bottom: 25px;
    border: 1px solid #eaeaea;
}

.chart-title {
    font-size: 20px;
    color: #2c3e50;
    margin-bottom: 25px;
    font-weight: 600;
    display: flex;
    align-items: center;
    gap: 10px;
}

.chart-title i {
    color: #4a6bff;
}

.charts-container {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(500px, 1fr));
    gap: 25px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .charts-container {
        grid-template-columns: 1fr;
    }
}

.chart-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    border: 1px solid #eaeaea;
    box-shadow: 0 2px 8px rgba(0,0,0,0.05);
}

.chart-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.chart-header h3 {
    font-size: 16px;
    color: #4361ee;
    font-weight: 600;
}

.chart-wrapper {
    position: relative;
    height: 300px;
    width: 100%;
}

.chart-legend {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 15px;
    padding-top: 15px;
    border-top: 1px solid #eee;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 12px;
    color: #666;
}

.legend-color {
    width: 12px;
    height: 12px;
    border-radius: 2px;
}

/* Table Container */
.table-container {
    background: white;
    border-radius: 12px;
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
    overflow: hidden;
    margin-bottom: 30px;
}

.section-title {
    background-color: #f8f9fa;
    padding: 18px 20px;
    border-bottom: 1px solid #eaeaea;
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 18px;
    font-weight: 600;
    color: #2c3e50;
}

.section-title div {
    display: flex;
    align-items: center;
    gap: 10px;
}

.table-actions {
    display: flex;
    gap: 10px;
}

.action-btn {
    padding: 8px 16px;
    background-color: #f8f9fa;
    border: 1px solid #ddd;
    border-radius: 4px;
    color: #555;
    font-weight: 600;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 6px;
    transition: all 0.3s;
    font-size: 14px;
}

.action-btn:hover {
    background-color: #e9ecef;
}

.action-btn.print {
    background-color: #3b82f6;
    color: white;
    border: none;
}

.action-btn.print:hover {
    background-color: #2563eb;
}

/* Table Controls */
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
    padding: 10px 15px;
    border: 2px solid #ced4da;
    border-radius: 6px;
    min-width: 250px;
    font-size: 14px;
    background: white;
    transition: border-color 0.3s;
}

.search-box:focus {
    outline: none;
    border-color: #4361ee;
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.grade-section-select {
    padding: 10px 15px;
    border: 2px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    min-width: 200px;
    background: white;
    cursor: pointer;
    transition: all 0.3s;
    color: #333;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
    background-repeat: no-repeat;
    background-position: right 10px center;
    background-size: 16px;
    padding-right: 35px;
    appearance: none;
    -webkit-appearance: none;
    -moz-appearance: none;
}

.grade-section-select:hover {
    border-color: #4361ee;
}

.grade-section-select:focus {
    outline: none;
    border-color: #4361ee;
    box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
}

.table-info {
    font-size: 14px;
    color: #4361ee;
    background: #f0f4ff;
    padding: 8px 15px;
    border-radius: 6px;
    border: 1px solid #dbe4ff;
    font-weight: 600;
    white-space: nowrap;
}

/* Table Wrapper and Table */
.table-wrapper {
    overflow-x: auto;
    max-height: 600px;
    overflow-y: auto;
    background: white;
    border: 1px solid #e0e0e0;
    border-radius: 0 0 8px 8px;
}

table {
    width: 100%;
    border-collapse: collapse;
    min-width: 800px;
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

/* No Data State */
.no-data {
    padding: 60px 20px;
    text-align: center;
    color: #7f8c8d;
}

.no-data i {
    font-size: 64px;
    margin-bottom: 20px;
    color: #d1d5db;
}

.no-data h3 {
    font-size: 22px;
    margin-bottom: 10px;
    color: #95a5a6;
}

.no-data p {
    font-size: 16px;
    color: #7f8c8d;
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

/* Print Styles */
@media print {
    .no-print {
        display: none !important;
    }
    
    body {
        background-color: white;
    }
    
    .main-content {
        padding: 0;
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .header, .date-range-filter, .stats-cards, .chart-section, .table-controls, .pagination {
        display: none;
    }
    
    .table-container {
        box-shadow: none;
        border: 1px solid #ddd;
    }
    
    table {
        border: 1px solid #ddd;
    }
    
    th, td {
        border: 1px solid #ddd;
    }
}

/* Responsive Design */
@media (max-width: 768px) {
    .main-content {
        padding: 15px;
        margin-left: 0 !important;
        width: 100% !important;
    }
    
    .header {
        padding: 20px;
    }
    
    .header h1 {
        font-size: 24px;
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .filter-row {
        grid-template-columns: 1fr;
    }
    
    .stats-cards {
        grid-template-columns: 1fr;
    }
    
    .charts-container {
        grid-template-columns: 1fr;
    }
    
    .chart-card {
        padding: 15px;
    }
    
    .chart-wrapper {
        height: 250px;
    }
    
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-section {
        width: 100%;
    }
    
    .search-box, .grade-section-select {
        width: 100%;
        min-width: unset;
    }
    
    .table-info {
        width: 100%;
        text-align: center;
    }
    
    .pagination {
        flex-wrap: wrap;
    }
    
    .section-title {
        flex-direction: column;
        gap: 15px;
        align-items: flex-start;
    }
    
    .table-actions {
        width: 100%;
        justify-content: flex-start;
    }
}

@media (max-width: 1024px) {
    .table-controls {
        flex-direction: column;
        align-items: stretch;
    }
    
    .search-section {
        width: 100%;
    }
    
    .search-box, .grade-section-select {
        flex: 1;
        min-width: unset;
    }
    
    .table-info {
        width: 100%;
        text-align: center;
        margin-top: 10px;
    }
    
    .charts-container {
        grid-template-columns: 1fr;
    }
}

@media (max-width: 480px) {
    .filter-buttons {
        flex-direction: column;
    }
    
    .btn {
        width: 100%;
        justify-content: center;
    }
    
    .table-actions {
        flex-direction: column;
    }
    
    .action-btn {
        width: 100%;
        justify-content: center;
    }
    
    .search-section {
        flex-direction: column;
    }
    
    .search-box, .grade-section-select {
        width: 100%;
    }
    
    .chart-title {
        font-size: 18px;
    }
    
    .chart-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 10px;
    }
    
    .chart-header h3 {
        font-size: 14px;
    }
}
</style>