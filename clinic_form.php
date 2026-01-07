<?php
include 'conn.php'; // database connection

// =======================
// SAVE FORM
// =======================
if(isset($_POST['submit'])){
    $student_id = $conn->real_escape_string($_POST['student_id']);
    $name = $conn->real_escape_string($_POST['name']);
    $grade_section = $conn->real_escape_string($_POST['grade_section']);
    $complaint = $conn->real_escape_string($_POST['complaint']);
    $treatment = $conn->real_escape_string($_POST['treatment']);

    date_default_timezone_set('Asia/Manila');
    $date = date('Y-m-d');
    $time = date('H:i');

    $sql = "INSERT INTO clinic_records
            (student_id, name, grade_section, complaint, treatment, date, time)
            VALUES
            ('$student_id','$name','$grade_section','$complaint','$treatment','$date','$time')";

    if($conn->query($sql)){
        $success = true;
    } else {
        $error = $conn->error;
    }
}

// =======================
// RFID SCAN
// =======================
if(isset($_GET['rfid'])){
    $rfid = $conn->real_escape_string($_GET['rfid']);
    $sql = "SELECT * FROM students_records WHERE rfid_number='$rfid' LIMIT 1";
    $res = $conn->query($sql);

    if($res->num_rows){
        $row = $res->fetch_assoc();
        echo json_encode([
            'student_id' => $row['student_id'],
            'fullname' => $row['fullname'],
            'grade_section' => $row['grade_section']
        ]);
    } else {
        echo json_encode([]);
    }
    exit;
}

// =======================
// NAME SEARCH (NO RFID)
// =======================
if(isset($_GET['search_name'])){
    $name = $conn->real_escape_string($_GET['search_name']);
    $sql = "SELECT * FROM students_records
            WHERE fullname LIKE '%$name%' LIMIT 5";
    $res = $conn->query($sql);

    $data = [];
    while($row = $res->fetch_assoc()){
        $data[] = [
            'student_id' => $row['student_id'],
            'fullname' => $row['fullname'],
            'grade_section' => $row['grade_section']
        ];
    }
    echo json_encode($data);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Clinic Form</title>


<link rel="stylesheet" href="./assets/css/navbar.css">

<!-- Navbar -->
<div class="navbar">
  <a href="./index.php" class="logo">CLINIC</a>
  <a href="./admin/adminlogin.php" class="admin-profile">
    <img src="./assets/pictures/adminpfp.jpg" alt="Admin" />
  </a>
</div>


<style>
*{margin:0;padding:0;box-sizing:border-box;font-family:'Segoe UI'}
body{
  background:url('./pictures/clinicbg.jpg') no-repeat center fixed;
  background-size:cover;
  display:flex;
  justify-content:center;
  align-items:center;
  height:100vh;
}
.clinic-card{
  width:900px;
  background:#fff;
  padding:20px;
  border-radius:12px;
  border-left:6px solid #1e88e5;
  box-shadow:0 6px 18px rgba(0,0,0,.15)
}
.clinic-title{
  text-align:center;
  color:#1e88e5;
  margin-bottom:20px
}
.form-group{margin-bottom:15px}
label{font-size:13px;color:#1565c0}
input,textarea{
  width:100%;
  padding:12px;
  border-radius:8px;
  border:1px solid #bbdefb;
  font-size:14px
}
input[readonly]{background:#f1f3f5}
textarea{height:50px}
button{
  width:100%;
  padding:12px;
  border:none;
  border-radius:8px;
  font-weight:600;
  cursor:pointer
}
.submit-btn{background:#1e88e5;color:#fff}
.clear-btn{margin-top:10px;background:#6c757d;color:#fff}

/* Name suggestions */
#nameSuggestions{
  display:none;
  border:1px solid #bbdefb;
  border-radius:6px;
  background:#fff;
  max-height:120px;
  overflow-y:auto;
}
#nameSuggestions div{
  padding:8px;
  cursor:pointer
}
#nameSuggestions div:hover{
  background:#e3f2fd
}

/* POPUPS */
.popup-overlay{
  display:none;
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.5);
  justify-content:center;
  align-items:center;
  z-index:999
}
.popup-content{
  background:#fff;
  padding:30px;
  border-radius:12px;
  text-align:center;
  width:300px
}
.popup-icon{font-size:45px;margin-bottom:10px}
.success .popup-icon{color:#4caf50}
.error .popup-icon{color:#f44336}
.popup-btn{
  margin-top:15px;
  padding:8px 20px;
  border:none;
  border-radius:6px;
  cursor:pointer;
  color:#fff
}
.success .popup-btn{background:#4caf50}
.error .popup-btn{background:#f44336}
</style>
</head>

<body>

<!-- SUCCESS POPUP -->
<div class="popup-overlay success" id="successPopup">
  <div class="popup-content">
    <div class="popup-icon">✓</div>
    <h3>Success</h3>
    <p>Record saved successfully</p>
    <button class="popup-btn" onclick="closePopup('successPopup')">OK</button>
  </div>
</div>

<!-- ERROR POPUP -->
<div class="popup-overlay error" id="errorPopup">
  <div class="popup-content">
    <div class="popup-icon">✗</div>
    <h3>Error</h3>
    <p id="errorMessage"></p>
    <button class="popup-btn" onclick="closePopup('errorPopup')">OK</button>
  </div>
</div>

<div class="clinic-card">
<h2 class="clinic-title">Clinic</h2>

<form method="POST">

<div class="form-group">
<label>Student ID</label>
<input type="text" id="student_id" name="student_id" readonly required>
</div>

<div class="form-group">
<label>Full Name (Input Full Name, If No RFID)</label>
<input type="text" id="student_name" name="name" required>
<div id="nameSuggestions"></div>
</div>

<div class="form-group">
<label>Grade & Section</label>
<input type="text" id="student_grade" name="grade_section" readonly required>
</div>

<div class="form-group">
<label>Complaint / Sickness</label>
<textarea name="complaint" id="complaint" required></textarea>
</div>

<div class="form-group">
<label>Treatment</label>
<textarea name="treatment" required></textarea>
</div>

<button class="submit-btn" name="submit">Save Record</button>
<button type="button" class="clear-btn" onclick="clearForm()">Clear</button>

</form>
</div>

<script>
// RFID SCAN
// RFID SCAN
let buffer = '';
let timer = null;

document.addEventListener('keydown', function(e){
    if (timer) clearTimeout(timer);

    if (e.key !== 'Enter') {
        buffer += e.key;
        timer = setTimeout(() => buffer = '', 100);
    } else {
        e.preventDefault();

        const rfid = buffer.trim();
        buffer = '';

        if (!rfid) return;

        fetch('?rfid=' + encodeURIComponent(rfid))
        .then(res => res.json())
        .then(data => {
            if (data.student_id) {
                document.getElementById('student_id').value = data.student_id;
                document.getElementById('student_name').value = data.fullname;
                document.getElementById('student_grade').value = data.grade_section;
                document.getElementById('complaint').focus();
            } else {
                showError('Student not found');
                clearForm();
            }
        })
        .catch(() => {
            showError('RFID scan failed');
        });
    }
});

// NAME SEARCH
student_name.addEventListener('input',()=>{
  if(student_name.value.length<2){
    nameSuggestions.style.display='none';
    return;
  }
  fetch('?search_name='+student_name.value)
  .then(r=>r.json())
  .then(data=>{
    nameSuggestions.innerHTML='';
    data.forEach(d=>{
      let div=document.createElement('div');
      div.textContent=d.fullname+' ('+d.grade_section+')';
      div.onclick=()=>{
        student_id.value=d.student_id;
        student_name.value=d.fullname;
        student_grade.value=d.grade_section;
        nameSuggestions.style.display='none';
        complaint.focus();
      };
      nameSuggestions.appendChild(div);
    });
    nameSuggestions.style.display=data.length?'block':'none';
  });
});

function clearForm(){
  student_id.value='';
  student_name.value='';
  student_grade.value='';
  complaint.value='';
}

function showError(msg){
  errorMessage.textContent=msg;
  errorPopup.style.display='flex';
}
function closePopup(id){
  document.getElementById(id).style.display='none';
}

// SHOW POPUPS FROM PHP
<?php if(isset($success)): ?>
  setTimeout(()=>successPopup.style.display='flex',100);
<?php endif; ?>

<?php if(isset($error)): ?>
  setTimeout(()=>showError("<?php echo addslashes($error); ?>"),100);
<?php endif; ?>
</script>

</body>
</html>
