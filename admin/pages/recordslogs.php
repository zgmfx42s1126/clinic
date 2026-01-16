<?php
include '../includes/conn.php'; 
include $_SERVER['DOCUMENT_ROOT'] . '/clinic/admin/includes/sidebar.php';

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}

// Get filter parameters
$start_date   = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date     = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');
$report_type  = isset($_GET['report_type']) ? $_GET['report_type'] : "Today's Analysis";
$grade_section = isset($_GET['grade_section']) ? $_GET['grade_section'] : '';

// Update dates based on report type (only if dates are default or empty)
if ((empty($_GET['start_date']) && empty($_GET['end_date'])) || 
    ($start_date == date('Y-m-01') && $end_date == date('Y-m-t'))) {

    $endDateObj   = new DateTime($end_date);
    $startDateObj = new DateTime($end_date);

    switch($report_type) {
        case "Today's Analysis":
            $start_date = $end_date = date('Y-m-d');
            break;
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

/* =========================================================
   ✅ Pagination parameters (supports per_page + All)
========================================================= */
$records_per_page = isset($_GET['per_page']) ? $_GET['per_page'] : 10;

if ($records_per_page === 'all') {
    $records_per_page_int = 999999999; // disable pagination
} else {
    $records_per_page_int = (int)$records_per_page;
    if ($records_per_page_int <= 0) $records_per_page_int = 10;
}

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($page < 1) $page = 1;

$offset = ($page - 1) * $records_per_page_int;

/* =========================================================
   ✅ COUNT query
========================================================= */
$count_sql = "SELECT COUNT(*) as total FROM clinic_log WHERE 1=1";
$count_params = [];
$count_types  = "";

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

$count_stmt = $conn->prepare($count_sql);
if (!empty($count_params)) {
    $count_stmt->bind_param($count_types, ...$count_params);
}
$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_row = $count_result->fetch_assoc();
$total_records = (int)($total_row['total'] ?? 0);
$count_stmt->close();

// Calculate total pages
if ($records_per_page === 'all') {
    $total_pages = 1;
    $page = 1;
    $offset = 0;
} else {
    $total_pages = (int)ceil(($total_records ?: 1) / $records_per_page_int);
    if ($total_pages < 1) $total_pages = 1;

    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $records_per_page_int;
    }
}

/* =========================================================
   ✅ MAIN query
========================================================= */
$sql = "SELECT * FROM clinic_log WHERE 1=1";
$params = [];
$types  = "";

if (!empty($start_date) && !empty($end_date)) {
    $sql .= " AND date BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
    $types .= "ss";
}

if (!empty($grade_section)) {
    $sql .= " AND grade_section = ?";
    $params[] = $grade_section;
    $types .= "s";
}

$sql .= " ORDER BY date DESC, time DESC";

if ($records_per_page !== 'all') {
    $sql .= " LIMIT ? OFFSET ?";
    $params[] = $records_per_page_int;
    $params[] = $offset;
    $types .= "ii";
}

$stmt = $conn->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}
$stmt->execute();
$result = $stmt->get_result();

/* =========================================================
   ✅ Grade sections dropdown
========================================================= */
$all_grades_sql = "SELECT DISTINCT grade_section FROM clinic_log WHERE grade_section IS NOT NULL AND grade_section != ''";
$all_grades_params = [];
$all_grades_types  = "";

if (!empty($start_date) && !empty($end_date)) {
    $all_grades_sql .= " AND date BETWEEN ? AND ?";
    $all_grades_params[] = $start_date;
    $all_grades_params[] = $end_date;
    $all_grades_types .= "ss";
}
$all_grades_sql .= " ORDER BY grade_section ASC";

if (!empty($all_grades_params)) {
    $all_grades_stmt = $conn->prepare($all_grades_sql);
    $all_grades_stmt->bind_param($all_grades_types, ...$all_grades_params);
    $all_grades_stmt->execute();
    $all_grades_result = $all_grades_stmt->get_result();
} else {
    $all_grades_result = $conn->query($all_grades_sql);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Patient Logs - Clinic Management System</title>

<link rel="stylesheet" href="../assets/css/patient.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<style>
    /* existing css kept... */

    .table-controls{
        background:#fff;
        padding:16px 18px;
        border-radius:10px;
        box-shadow:0 2px 10px rgba(0,0,0,0.05);
        margin-bottom:12px;
        display:flex;
        justify-content:space-between;
        align-items:center;
        gap:15px;
        flex-wrap:wrap;
    }

    /* ✅ Like screenshot: left controls in one line */
    .controls-left{
        display:flex;
        align-items:center;
        gap:12px;
        flex-wrap:wrap;
    }

    .search-box{
        padding:10px 12px;
        border:2px solid #e0e0e0;
        border-radius:6px;
        font-size:14px;
        min-width:210px;
    }

    .grade-section-select,
    .per-page-select{
        padding:10px 12px;
        border:2px solid #e0e0e0;
        border-radius:6px;
        font-size:14px;
        background:#fff;
        min-width:240px;
    }

    .per-page-wrap{
        display:flex;
        align-items:center;
        gap:8px;
        font-size:14px;
        color:#4b5563;
    }
    .per-page-wrap .per-page-select{
        min-width:90px;
    }

    /* ✅ right info badge */
    .table-info{
        font-weight:600;
        color:#4361ee;
        background:#f0f4ff;
        padding:8px 14px;
        border-radius:6px;
        border:1px solid #dbe4ff;
        font-size:13px;
        white-space:nowrap;
    }

    /* ✅ Pagination centered bottom */
    .pagination-wrap{
        padding:14px 12px 6px;
        display:flex;
        justify-content:center;
        align-items:center;
    }

    .pagination{
        display:flex;
        justify-content:center;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
    }

    .pagination-btn{
        padding:8px 14px;
        border:1px solid #e0e0e0;
        background:#fff;
        border-radius:6px;
        cursor:pointer;
        font-size:13px;
        font-weight:600;
        transition:.2s;
        display:flex;
        align-items:center;
        gap:6px;
    }
    .pagination-btn:hover:not(.disabled){
        background:#4361ee;
        border-color:#4361ee;
        color:#fff;
    }
    .pagination-btn.disabled{
        opacity:.45;
        cursor:not-allowed;
    }

    .page-numbers{
        display:flex;
        gap:6px;
        align-items:center;
    }
    .page-number{
        padding:8px 12px;
        border:1px solid #e0e0e0;
        background:#fff;
        border-radius:6px;
        cursor:pointer;
        font-size:13px;
        font-weight:700;
        min-width:38px;
        text-align:center;
        transition:.2s;
    }
    .page-number:hover{ border-color:#4361ee; background:#f0f4ff; }
    .page-number.active{ background:#4361ee; border-color:#4361ee; color:#fff; }
    .page-dots{
        padding:8px 10px;
        border:none;
        background:transparent;
        color:#6b7280;
        font-weight:700;
    }

    .pagination-info{
        text-align:center;
        font-size:12px;
        color:#6b7280;
        padding:0 0 14px;
    }

    /* table styles same */
    .table-container{ background:#fff; border-radius:10px; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table{ width:100%; border-collapse:collapse; }
    th{ background:#4361ee; color:#fff; padding:12px; text-align:left; }
    td{ padding:12px; border-bottom:1px solid #eef0f3; }
</style>
</head>
<body>

<div class="main-content no-print">
<div class="container">
    <div class="header">
        <h1><i class="fas fa-clipboard-list"></i> Patient Logs</h1>
        <p>Clinic visit log records</p>
    </div>

    <!-- Filter Section (unchanged) -->
    <div class="filter-section">
        <div class="filter-row">
            <div class="filter-group">
                <label for="startDate"><i class="fas fa-calendar-day"></i> Start Date</label>
                <input type="date" id="startDate" name="start_date" value="<?php echo htmlspecialchars($start_date); ?>">
            </div>

            <div class="filter-group">
                <label for="endDate"><i class="fas fa-calendar-day"></i> End Date</label>
                <input type="date" id="endDate" name="end_date" value="<?php echo htmlspecialchars($end_date); ?>">
            </div>

            <div class="filter-group">
                <label for="reportType"><i class="fas fa-chart-bar"></i> Report Type</label>
                <select id="reportType" name="report_type">
                    <option value="Today's Analysis" <?php echo $report_type == "Today's Analysis" ? 'selected' : ''; ?>>Today's Analysis</option>
                    <option value="Weekly Analysis" <?php echo $report_type == "Weekly Analysis" ? 'selected' : ''; ?>>Weekly Analysis</option>
                    <option value="Monthly Analysis" <?php echo $report_type == "Monthly Analysis" ? 'selected' : ''; ?>>Monthly Analysis</option>
                    <option value="Yearly Analysis" <?php echo $report_type == "Yearly Analysis" ? 'selected' : ''; ?>>Yearly Analysis</option>
                </select>
            </div>
        </div>

        <div class="filter-actions">
            <button class="filter-btn btn-reset" onclick="resetFilters()">
                <i class="fas fa-redo"></i> Reset
            </button>
            <button class="filter-btn btn-apply" onclick="applyFilters()">
                <i class="fas fa-filter"></i> Apply Filter
            </button>
        </div>
    </div>

    <!-- ✅ Controls row like screenshot -->
    <div class="table-controls">
        <div class="controls-left">
            <input type="text" class="search-box" placeholder="Search by clinic ID or name..." onkeyup="searchTable()" id="searchInput">

            <select class="grade-section-select" onchange="applyFilters()" id="gradeSectionFilter">
                <option value="">All Grades & Sections</option>
                <?php if ($all_grades_result && $all_grades_result->num_rows > 0): ?>
                    <?php while ($grade_row = $all_grades_result->fetch_assoc()): ?>
                        <option value="<?php echo htmlspecialchars($grade_row['grade_section']); ?>"
                            <?php echo $grade_row['grade_section'] == $grade_section ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($grade_row['grade_section']); ?>
                        </option>
                    <?php endwhile; ?>
                    <?php $all_grades_result->data_seek(0); ?>
                <?php endif; ?>
            </select>

            <!-- ✅ Show selector positioned beside filters -->
            <div class="per-page-wrap">
                <span>Show:</span>
                <select id="perPageSelect" class="per-page-select" onchange="changeRecordsPerPage(this.value)">
                    <option value="10"  <?php echo ($records_per_page_int == 10 && $records_per_page !== 'all') ? 'selected' : ''; ?>>10</option>
                    <option value="25"  <?php echo ($records_per_page_int == 25) ? 'selected' : ''; ?>>25</option>
                    <option value="50"  <?php echo ($records_per_page_int == 50) ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo ($records_per_page_int == 100) ? 'selected' : ''; ?>>100</option>
                    <option value="all" <?php echo ($records_per_page === 'all') ? 'selected' : ''; ?>>All</option>
                </select>
            </div>
        </div>

        <div class="table-info">
            <?php
            $showing = 0;
            if ($total_records > 0) {
                if ($records_per_page === 'all') $showing = $total_records;
                else $showing = min($records_per_page_int, max(0, $total_records - $offset));
            }
            ?>
            Showing <?php echo $showing; ?> of <?php echo $total_records; ?> records
            (<?php echo ($records_per_page === 'all') ? 'All' : (int)$records_per_page_int; ?> per page)
        </div>
    </div>

    <div class="table-container">
        <?php if ($result && $result->num_rows > 0): ?>
            <table id="logsTable">
                <thead>
                    <tr>
                        <th>Clinic ID</th>
                        <th>Name</th>
                        <th>Grade & Section</th>
                        <th>Date</th>
                        <th>Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = $result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($row['clinic_id'] ?? ''); ?></td>
                            <td><strong><?php echo htmlspecialchars($row['name'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($row['grade_section'] ?? ''); ?></td>
                            <td><?php echo !empty($row['date']) ? date('Y-m-d', strtotime($row['date'])) : ''; ?></td>
                            <td><?php echo !empty($row['time']) ? date('h:i A', strtotime($row['time'])) : ''; ?></td>
                        </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>

            <!-- ✅ Pagination centered bottom + "..." logic like screenshot -->
            <?php if ($records_per_page !== 'all' && $total_pages > 1): ?>
                <div class="pagination-wrap">
                    <div class="pagination">
                        <button class="pagination-btn <?php echo $page <= 1 ? 'disabled' : ''; ?>"
                                onclick="changePage(<?php echo $page - 1; ?>)"
                                <?php echo $page <= 1 ? 'disabled' : ''; ?>>
                            <i class="fas fa-chevron-left"></i> Previous
                        </button>

                        <div class="page-numbers">
                            <?php
                            // show first page + dots if needed
                            if ($page > 3) {
                                echo '<button class="page-number" onclick="changePage(1)">1</button>';
                                if ($page > 4) echo '<span class="page-dots">…</span>';
                            }

                            // window around current page
                            $start = max(1, $page - 1);
                            $end   = min($total_pages, $page + 1);

                            for ($i = $start; $i <= $end; $i++) {
                                $active = ($i == $page) ? 'active' : '';
                                echo '<button class="page-number '.$active.'" onclick="changePage('.$i.')">'.$i.'</button>';
                            }

                            // show last page + dots if needed
                            if ($page < $total_pages - 2) {
                                if ($page < $total_pages - 3) echo '<span class="page-dots">…</span>';
                                echo '<button class="page-number" onclick="changePage('.$total_pages.')">'.$total_pages.'</button>';
                            }
                            ?>
                        </div>

                        <button class="pagination-btn <?php echo $page >= $total_pages ? 'disabled' : ''; ?>"
                                onclick="changePage(<?php echo $page + 1; ?>)"
                                <?php echo $page >= $total_pages ? 'disabled' : ''; ?>>
                            Next <i class="fas fa-chevron-right"></i>
                        </button>
                    </div>
                </div>

                <div class="pagination-info">
                    Page <?php echo $page; ?> of <?php echo $total_pages; ?> •
                    Records <?php echo min($offset + 1, $total_records); ?>-<?php echo min($offset + $records_per_page_int, $total_records); ?> of <?php echo $total_records; ?>
                    • Showing <?php echo (int)$records_per_page_int; ?> per page
                </div>
            <?php elseif ($records_per_page === 'all' && $total_records > 0): ?>
                <div class="pagination-info">Showing All records</div>
            <?php endif; ?>

        <?php else: ?>
            <div class="empty-state">
                <i class="fas fa-clipboard-list"></i>
                <h3>No patient logs found</h3>
                <p>There are no logs matching your filter criteria<?php echo !empty($grade_section) ? ' for ' . htmlspecialchars($grade_section) : ''; ?>.</p>
            </div>
        <?php endif; ?>
    </div>
</div>
</div>

<script>
function buildUrl(page = 1, perPage = null) {
    const startDate = document.getElementById('startDate').value;
    const endDate = document.getElementById('endDate').value;
    const reportType = document.getElementById('reportType').value;
    const gradeSection = document.getElementById('gradeSectionFilter').value;

    let url = `?start_date=${encodeURIComponent(startDate)}&end_date=${encodeURIComponent(endDate)}&report_type=${encodeURIComponent(reportType)}&page=${page}`;

    if (gradeSection) url += `&grade_section=${encodeURIComponent(gradeSection)}`;

    const currentPerPage = perPage ?? (document.getElementById('perPageSelect') ? document.getElementById('perPageSelect').value : null);
    if (currentPerPage) url += `&per_page=${encodeURIComponent(currentPerPage)}`;

    return url;
}

function applyFilters() { window.location.href = buildUrl(1); }

function resetFilters() {
    const todayStr = new Date().toISOString().split('T')[0];
    window.location.href = `?start_date=${encodeURIComponent(todayStr)}&end_date=${encodeURIComponent(todayStr)}&report_type=${encodeURIComponent("Today's Analysis")}&page=1&per_page=10`;
}

function changePage(newPage) { window.location.href = buildUrl(newPage); }

function changeRecordsPerPage(perPage) { window.location.href = buildUrl(1, perPage); }

function searchTable() {
    const input = document.getElementById("searchInput");
    const filter = input.value.toUpperCase();
    const table = document.getElementById("logsTable");
    const tr = table.getElementsByTagName("tr");

    for (let i = 0; i < tr.length; i++) {
        const td = tr[i].getElementsByTagName("td");
        let found = false;
        for (let j = 0; j < td.length; j++) {
            const cell = td[j];
            if (cell) {
                const txtValue = cell.textContent || cell.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) { found = true; break; }
            }
        }
        tr[i].style.display = found ? "" : "none";
    }
}

document.getElementById('reportType').addEventListener('change', function() {
    const reportType = this.value;
    const endDateInput = document.getElementById('endDate');
    const startDateInput = document.getElementById('startDate');

    if (reportType === "Today's Analysis") {
        const todayStr = new Date().toISOString().split('T')[0];
        startDateInput.value = todayStr;
        endDateInput.value = todayStr;
        return;
    }

    const endDate = new Date(endDateInput.value);
    let startDate = new Date(endDate);

    switch(reportType) {
        case 'Weekly Analysis': startDate.setDate(startDate.getDate() - 7); break;
        case 'Monthly Analysis': startDate = new Date(endDate.getFullYear(), endDate.getMonth(), 1); break;
        case 'Yearly Analysis': startDate.setFullYear(startDate.getFullYear() - 1); break;
    }
    startDateInput.value = startDate.toISOString().split('T')[0];
});
</script>

<?php
if (isset($all_grades_stmt)) $all_grades_stmt->close();
if (isset($conn)) $conn->close();
?>
</body>
</html>

   <style>
        /* (Your existing CSS 그대로 유지) */
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
        .filter-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; align-items: end; }
        .filter-group { display: flex; flex-direction: column; }
        .filter-group label {
            margin-bottom: 8px;
            font-weight: 600;
            color: #4b5563;
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
        }
        .filter-group input, .filter-group select {
            padding: 12px 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            font-size: 16px;
            width: 100%;
            transition: border-color 0.3s;
            background: white;
        }
        .filter-group input:focus, .filter-group select:focus {
            outline: none;
            border-color: #4361ee;
            box-shadow: 0 0 0 3px rgba(67, 97, 238, 0.1);
        }
        .filter-actions { display: flex; justify-content: flex-end; gap: 15px; margin-top: 10px; }
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
        .btn-apply { background: #4361ee; color: white; }
        .btn-apply:hover { background: #3a56d4; transform: translateY(-2px); box-shadow: 0 4px 12px rgba(67, 97, 238, 0.2); }
        .btn-reset { background: #6c757d; color: white; }
        .btn-reset:hover { background: #5a6268; transform: translateY(-2px); }

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
        .search-filter { display: flex; gap: 15px; align-items: center; flex-wrap: wrap; }
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
        .grade-section-select { min-width: 250px; }
        .table-info {
            font-weight: 600;
            color: #4361ee;
            background: #f0f4ff;
            padding: 8px 16px;
            border-radius: 6px;
            border: 1px solid #dbe4ff;
        }

        .pagination { display: flex; justify-content: center; align-items: center; margin-top: 20px; gap: 10px; }
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
        .pagination-btn:hover:not(.disabled) { background: #4361ee; color: white; border-color: #4361ee; }
        .pagination-btn.disabled { opacity: 0.5; cursor: not-allowed; }

        .page-numbers { display: flex; gap: 5px; flex-wrap: wrap; justify-content: center; }
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
        .page-number:hover { background: #f0f4ff; border-color: #4361ee; }
        .page-number.active { background: #4361ee; color: white; border-color: #4361ee; }

        .records-per-page { display: flex; align-items: center; gap: 10px; font-size: 14px; margin-left: auto; }
        .records-per-page select { padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px; }

        .header {
            background: linear-gradient(135deg, #4361ee 0%, #3a56d4 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(67, 97, 238, 0.2);
        }
        .header h1 { font-size: 32px; margin-bottom: 10px; display: flex; align-items: center; gap: 15px; }
        .header p { font-size: 16px; opacity: 0.9; margin-bottom: 20px; }

        .table-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        table { width: 100%; border-collapse: collapse; }
        thead { position: sticky; top: 0; z-index: 10; }
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
        td { padding: 14px 12px; border-bottom: 1px solid #eef0f3; vertical-align: middle; }
        tbody tr:hover { background-color: #f8fafc; }

        .pagination-info { font-size: 14px; color: #666; margin-top: 10px; text-align: right; padding: 0 10px 10px; }
        .empty-state { display:flex; flex-direction:column; align-items:center; justify-content:center; padding:60px 20px; color:#6c757d; text-align:center; }
        .empty-state i { font-size:64px; margin-bottom:20px; color:#d1d5db; }
        .empty-state h3 { margin-bottom:10px; color:#4b5563; }
    </style>
