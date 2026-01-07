<?php
include 'conn.php'; // your DB connection

$success = false;
$error = '';
$name = $grade_section = '';

// Set Philippine timezone
date_default_timezone_set('Asia/Manila');
$date = date('Y-m-d');
$time = date('H:i');

// Only save when user clicks the button
if(isset($_POST['submit'])){
    $clinic_id = $conn->real_escape_string($_POST['clinic_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $grade_section = $conn->real_escape_string($_POST['grade_section']);
    $date = $conn->real_escape_string(string: $_POST['date']);
    $time = $conn->real_escape_string($_POST['time']);

    $sql = "INSERT INTO clinic_log (clinic_id, name, grade_section, date, time)
            VALUES ('$clinic_id','$name','$grade_section','$date','$time')";

    if($conn->query($sql) === TRUE){
        $success = true;
        $error = ''; // CLEAR ANY PREVIOUS ERROR
        $_POST = array(); // Clear POST data
        
        // Clear form after successful submission
        $name = $grade_section = '';
        $clinic_id_value = '';
        $date = date('Y-m-d');
        $time = date('H:i');
    } else {
        $error = $conn->error;
        // Keep values if there's an error
        $clinic_id_value = htmlspecialchars($_POST['clinic_id']);
    }
}

// AJAX: Fetch student info based on RFID scan
if(isset($_GET['rfid'])){
    $rfid = $conn->real_escape_string($_GET['rfid']);
    
    // First try with rfid_number (from your clinic form code)
    $sql = "SELECT * FROM students_records WHERE rfid_number='$rfid' LIMIT 1";
    $result = $conn->query($sql);

    // If not found, try with rfid_id (from your original clinic logbook code)
    if($result && $result->num_rows == 0){
        $sql = "SELECT * FROM students_records WHERE rfid_id='$rfid' LIMIT 1";
        $result = $conn->query($sql);
    }

    if($result && $result->num_rows > 0){
        $row = $result->fetch_assoc();
        echo json_encode([
            'student_id' => $row['student_id'] ?? '',
            'fullname' => $row['fullname'] ?? '',
            'grade_section' => $row['grade_section'] ?? ''
        ]);
    } else {
        echo json_encode([]);
    }
    exit;
}

// AJAX search for students by name
if(isset($_GET['search_name'])){
    $search = $conn->real_escape_string($_GET['search_name']);
    $results = [];
    
    if(strlen($search) >= 2){ // Only search if at least 2 characters
        $sql = "SELECT student_id, fullname, grade_section FROM students_records 
                WHERE fullname LIKE '%$search%' 
                ORDER BY fullname LIMIT 5";
        $result = $conn->query($sql);
        
        while($row = $result->fetch_assoc()){
            $results[] = [
                'student_id' => $row['student_id'],
                'fullname' => $row['fullname'],
                'grade_section' => $row['grade_section']
            ];
        }
    }
    
    echo json_encode($results);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Clinic Log Book</title>

<link rel="stylesheet" href="./assets/css/navbar.css">

<!-- Navbar -->
<div class="navbar">
  <a href="./index.php" class="logo">CLINIC</a>
  <a href="./admin/adminlogin.php" class="admin-profile">
    <img src="./assets/pictures/adminpfp.jpg" alt="Admin" />
  </a>
</div>

<style>
* { box-sizing: border-box; margin:0; padding:0; }
html, body { width:100%; height:100%; font-family: 'Segoe UI', sans-serif; }
body {
    background: url('./pictures/logbg.jpg') center/cover no-repeat;
    display:flex; justify-content:center; align-items:center; position:relative;
}
body::before {
    content: ''; position:fixed; top:0; left:0; width:100%; height:100%;
    background: rgba(255,255,255,0.25); z-index:-1;
}
.clinic-card { width:900px; max-width:90%; background:#fff; border-radius:12px; padding:25px; border-left:6px solid #1e88e5; box-shadow:0 6px 18px rgba(0,0,0,0.1); position: relative; }
.clinic-title { color:#1e88e5; font-size:24px; margin-bottom:20px; text-align:center; font-weight:600; }
.form-group { margin-bottom:16px; }
.form-group label { display:block; font-size:13px; color:#1565c0; margin-bottom:6px; font-weight:500; }
.form-group input { width:100%; padding:10px 12px; border-radius:8px; border:1px solid #bbdefb; outline:none; font-size:14px; }
.form-group input[readonly] { background-color: #f8f9fa; color: #495057; border-color: #e9ecef; }
.submit-btn { width:100%; padding:12px; margin-top:15px; background:#1e88e5; color:#fff; border:none; border-radius:8px; font-size:15px; font-weight:500; cursor:pointer; transition: all 0.3s ease; }
.submit-btn:hover { background:#1565c0; transform: translateY(-1px); box-shadow:0 4px 8px rgba(0,0,0,0.1); }

/* Popup Modal */
.popup-overlay {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.popup-content {
    background: white;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
    text-align: center;
    max-width: 350px;
    width: 90%;
    animation: popupFadeIn 0.3s ease;
}

@keyframes popupFadeIn {
    from {
        opacity: 0;
        transform: translateY(-20px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

.popup-icon {
    font-size: 50px;
    color: #4CAF50;
    margin-bottom: 15px;
}

.popup-title {
    color: #333;
    font-size: 20px;
    margin-bottom: 10px;
    font-weight: 600;
}

.popup-message {
    color: #666;
    margin-bottom: 20px;
    font-size: 15px;
}

.popup-close-btn {
    background: #4CAF50;
    color: white;
    border: none;
    padding: 10px 25px;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    transition: background 0.3s;
}

.popup-close-btn:hover {
    background: #45a049;
}

/* Error Popup */
.error-popup .popup-icon {
    color: #f44336;
}

.error-popup .popup-close-btn {
    background: #f44336;
}

.error-popup .popup-close-btn:hover {
    background: #d32f2f;
}

/* Name suggestions - SIMPLIFIED LIKE CLINIC FORM */
#nameSuggestions {
    display: none;
    border: 1px solid #bbdefb;
    border-radius: 6px;
    background: #fff;
    max-height: 120px;
    overflow-y: auto;
    position: absolute;
    width: 100%;
    z-index: 1000;
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}

#nameSuggestions div {
    padding: 8px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
}

#nameSuggestions div:hover {
    background: #e3f2fd;
}

#nameSuggestions div:last-child {
    border-bottom: none;
}
</style>
</head>
<body>

<!-- Success Popup -->
<div class="popup-overlay" id="successPopup">
    <div class="popup-content">
        <div class="popup-icon">✓</div>
        <h3 class="popup-title">Success!</h3>
        <p class="popup-message">Record saved successfully!</p>
        <button class="popup-close-btn" onclick="closePopup('successPopup')">OK</button>
    </div>
</div>

<!-- Error Popup -->
<div class="popup-overlay error-popup" id="errorPopup">
    <div class="popup-content">
        <div class="popup-icon">✗</div>
        <h3 class="popup-title">Error</h3>
        <p class="popup-message" id="errorMessage"></p>
        <button class="popup-close-btn" onclick="closePopup('errorPopup')">OK</button>
    </div>
</div>

<div class="clinic-card">
    <h2 class="clinic-title">Clinic Log Book</h2>

    <form method="POST" action="" id="clinicForm">
        <div class="form-group">
            <label>Student ID </label>
            <input type="text" name="clinic_id" id="clinic_id" placeholder="Scan RFID or search by name" required autofocus value="<?php echo isset($clinic_id_value) ? $clinic_id_value : ''; ?>" readonly>
        </div>

        <div class="form-group" style="position:relative;">
            <label>Full Name (Input Full Name, If No RFID)</label>
            <input type="text" name="name" id="student_name" value="<?php echo htmlspecialchars($name); ?>" placeholder="Type student name..." required>
            <div id="nameSuggestions"></div>
        </div>

        <div class="form-group">
            <label>Grade & Section</label>
            <input type="text" name="grade_section" id="student_grade" value="<?php echo htmlspecialchars($grade_section); ?>" readonly required>
        </div>

        <div class="form-group">
            <label>Date</label>
            <input type="date" name="date" id="date" value="<?php echo $date; ?>" readonly>
        </div>

        <div class="form-group">
            <label>Time</label>
            <input type="time" name="time" id="time" value="<?php echo $time; ?>" readonly>
        </div>

        <button type="submit" name="submit" class="submit-btn">Save Record</button>
        <button type="button" class="submit-btn" style="background:#6c757d; margin-top:10px;" onclick="clearForm(); return false;">Clear Form</button>
    </form>
</div>

<script>
// RFID SCAN - Same as Clinic Form
let buffer='', timer;
document.addEventListener('keydown', function(e){
    if(timer) clearTimeout(timer);
    if(e.key !== 'Enter'){
        buffer += e.key;
        timer = setTimeout(() => buffer = '', 50);
    } else {
        e.preventDefault();
        let rfid = buffer.trim();
        buffer = '';
        
        if(rfid){
            fetch('?rfid=' + encodeURIComponent(rfid))
            .then(res => res.json())
            .then(data => {
                if(data.student_id){
                    document.getElementById('clinic_id').value = data.student_id;
                    document.getElementById('student_name').value = data.fullname;
                    document.getElementById('student_grade').value = data.grade_section;
                    updateDateTime();
                } else {
                    // Show error message like in Clinic Form
                    showError('Student not found');
                    document.getElementById('clinic_id').value = '';
                    document.getElementById('clinic_id').focus();
                }
            })
            .catch(error => {
             
                showError('Student not found');
            });
        }
    }
});

// NAME SEARCH - Same as Clinic Form
document.getElementById('student_name').addEventListener('input', function(){
    const nameInput = this.value;
    const suggestions = document.getElementById('nameSuggestions');
    
    console.log('Searching for:', nameInput);
    
    if(nameInput.length < 2){
        suggestions.style.display = 'none';
        return;
    }
    
    fetch('?search_name=' + encodeURIComponent(nameInput))
    .then(res => res.json())
    .then(data => {
        console.log('Search results:', data);
        suggestions.innerHTML = '';
        data.forEach(student => {
            let div = document.createElement('div');
            div.textContent = student.fullname + ' (' + student.grade_section + ')';
            div.onclick = function() {
                document.getElementById('clinic_id').value = student.student_id;
                document.getElementById('student_name').value = student.fullname;
                document.getElementById('student_grade').value = student.grade_section;
                suggestions.style.display = 'none';
                updateDateTime();
            };
            suggestions.appendChild(div);
        });
        suggestions.style.display = data.length ? 'block' : 'none';
    })
    .catch(error => {
        console.error('Search error:', error);
        suggestions.style.display = 'none';
    });
});

// Close suggestions when clicking outside
document.addEventListener('click', function(e){
    const suggestions = document.getElementById('nameSuggestions');
    const nameInput = document.getElementById('student_name');
    
    if(!nameInput.contains(e.target) && !suggestions.contains(e.target)){
        suggestions.style.display = 'none';
    }
});

// Function to update date and time fields
function updateDateTime() {
    let now = new Date();
    let phTime = new Date(now.toLocaleString('en-US', { timeZone: 'Asia/Manila' }));
    
    // Format date as YYYY-MM-DD
    let year = phTime.getFullYear();
    let month = String(phTime.getMonth() + 1).padStart(2, '0');
    let day = String(phTime.getDate()).padStart(2, '0');
    document.getElementById('date').value = `${year}-${month}-${day}`;
    
    // Format time as HH:MM
    let hours = String(phTime.getHours()).padStart(2, '0');
    let minutes = String(phTime.getMinutes()).padStart(2, '0');
    document.getElementById('time').value = `${hours}:${minutes}`;
}

function clearForm() {
    console.log("Clearing form");
    document.getElementById('clinic_id').value = '';
    document.getElementById('student_name').value = '';
    document.getElementById('student_grade').value = '';
    document.getElementById('nameSuggestions').style.display = 'none';
    document.getElementById('clinic_id').focus();
    updateDateTime();
    return false;
}

function showSuccess() {
    console.log("Showing success popup");
    // Hide error popup first
    document.getElementById('errorPopup').style.display = 'none';
    // Show success popup
    document.getElementById('successPopup').style.display = 'flex';
    setTimeout(() => {
        closePopup('successPopup');
    }, 3000);
}

function showError(message) {
    console.log("Showing error popup:", message);
    // Hide success popup first
    document.getElementById('successPopup').style.display = 'none';
    // Show error popup
    document.getElementById('errorMessage').textContent = message;
    document.getElementById('errorPopup').style.display = 'flex';
}

function closePopup(popupId) {
    document.getElementById(popupId).style.display = 'none';
}

// SHOW POPUPS FROM PHP
<?php if($success): ?>
    console.log("Save successful! Error variable is: '<?php echo $error; ?>'");
    setTimeout(() => {
        // Make sure error popup is closed
        closePopup('errorPopup');
        // Show success
        showSuccess();
        // Clear form
        clearForm();
    }, 100);
<?php endif; ?>

<?php if(!empty($error)): ?>
    console.log("PHP Error found: <?php echo addslashes($error); ?>");
    setTimeout(() => {
        // Make sure success popup is closed
        closePopup('successPopup');
        showError("<?php echo addslashes($error); ?>");
    }, 100);
<?php endif; ?>

// Close popup when clicking outside
window.addEventListener('click', function(event) {
    if (event.target.classList.contains('popup-overlay')) {
        event.target.style.display = 'none';
    }
});
</script>

</body>
</html>