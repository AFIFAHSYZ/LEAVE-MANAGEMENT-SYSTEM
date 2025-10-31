<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Verify HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Fetch employees and leave types
$employees = $pdo->query("SELECT id, name  FROM users WHERE position != 'hr' AND position != 'manager' ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
$leaveTypes = $pdo->query("SELECT id, name FROM leave_types ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave requests
$summary = $pdo->query("
    SELECT lr.id, u.name AS employee_name, lt.name AS leave_type,
           lr.start_date, lr.end_date, lr.applied_at,
           (DATE_PART('day', lr.end_date::timestamp - lr.start_date::timestamp) + 1) AS total_days
    FROM leave_requests lr
    LEFT JOIN users u ON lr.user_id = u.id
    LEFT JOIN leave_types lt ON lr.leave_type_id = lt.id
    ORDER BY lr.applied_at DESC
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HR Leave Reports | Teraju LMS</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
body {
  font-family: "Segoe UI", Arial, sans-serif;
  background: #f1f5f9;
  margin: 0;
  color: #111827;
}

.layout { display: flex; }
.main-content { flex: 1; padding: 20px; }
.card {
  background: #fff;
  padding: 24px;
  border-radius: 12px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.08);
}

/* Filters */
.filter-bar {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 20px;
}
.filter-bar select,
.filter-bar input {
  padding: 6px 10px;
  border-radius: 6px;
  border: 1px solid #cbd5e1;
  font-size: 0.95rem;
}
.filter-bar button {
  padding: 8px 14px;
  background: #3b82f6;
  color: #fff;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.2s;
}
.filter-bar button:hover {
  background: #2563eb;
}

/* Table */
.table-container { overflow-x: auto; }
.leave-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.9rem;
  border-radius: 8px;
  overflow: hidden;
}
.leave-table th,
.leave-table td {
  border: 1px solid #e5e7eb;
  padding: 6px 10px; /* compact row spacing */
  text-align: center;
  vertical-align: middle;
}
.leave-table th {
  background: #f3f4f6;
  font-weight: 600;
}
.leave-table tbody tr:nth-child(even) { background: #f9fafb; }

/* Print area */
.print-area {
  display: none;
  background: #fff;
  padding: 20px;
  box-sizing: border-box;
}
.print-header {
  text-align: center;
  margin-bottom: 20px;
}
.print-header img {
  height: 60px;
  margin-bottom: 8px;
}
.print-header h2 {
  margin: 5px 0;
  font-size: 1.4rem;
}
.print-meta,
.print-filter-summary {
  font-size: 0.9rem;
  color: #555;
}
.print-footer {
  text-align: center;
  font-size: 0.8rem;
  color: #777;
  margin-top: 25px;
}

/* Print styles */
@media print {
  @page { size: A4 portrait; margin: 0; }

  html, body {
    width: 210mm;
    height: 297mm;
    margin: 0 !important;
    padding: 0 !important;
    background: #fff !important;
  }

  body * { visibility: hidden !important; }
  .print-area { visibility: visible !important; display: block !important; }
  .print-area * { visibility: visible !important; display: revert !important; }

  .print-area {
    position: fixed !important;
    top: 0;
    left: 0;
    width: 100% !important;
    padding: 10mm !important;
    background: #fff !important;
    box-sizing: border-box !important;
  }

  /* Compact table style for print */
  .leave-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.85rem;
  }
  .leave-table th,
  .leave-table td {
    border: 1px solid #000;
    padding: 4px 6px; /* tighter print rows */
    text-align: center;
    vertical-align: middle;
  }
  .leave-table th {
    background: #f3f4f6;
    font-weight: 600;
  }
  .leave-table tbody tr:nth-child(even) { background: #f9fafb; }

  .sidebar, .filter-bar, header, button, .card > h2 {
    display: none !important;
  }
  tr { page-break-inside: avoid; }
}
</style>
</head>

<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
<header><h1>Leave Management System</h1></header>

  <main class="main-content">
    <div class="card">
      <h2>HR Leave Reports</h2>

      <div class="filter-bar">
        <select id="employeeFilter">
          <option value="">All Employees</option>
          <?php foreach($employees as $e): ?>
            <option value="<?= htmlspecialchars($e['name']) ?>"><?= htmlspecialchars($e['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <select id="typeFilter">
          <option value="">All Leave Types</option>
          <?php foreach($leaveTypes as $lt): ?>
            <option value="<?= htmlspecialchars($lt['name']) ?>"><?= htmlspecialchars($lt['name']) ?></option>
          <?php endforeach; ?>
        </select>

        <input type="date" id="startDateFilter">
        <input type="date" id="endDateFilter">

        <button onclick="printReport()">🖨 Print</button>
      </div>

      <div class="table-container">
        <table class="leave-table" id="reportTable">
          <thead>
            <tr>
              <th>No.</th>
              <th>Employee</th>
              <th>Leave Type</th>
              <th>Start Date</th>
              <th>End Date</th>
              <th>Total Days</th>
              <th>Requested On</th>
            </tr>
          </thead>
          <tbody>
            <?php $i = 1; foreach($summary as $row): ?>
              <tr>
                <td><?= $i++ ?></td>
                <td><?= htmlspecialchars($row['employee_name']) ?></td>
                <td><?= htmlspecialchars($row['leave_type']) ?></td>
                <td><?= htmlspecialchars($row['start_date']) ?></td>
                <td><?= htmlspecialchars($row['end_date']) ?></td>
                <td><?= (int)$row['total_days'] ?></td>
                <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['applied_at']))) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </main>
</div>

<!-- Print template -->
<div class="print-area" id="printArea">
  <div class="print-header">
    <h2>Teraju HR — Leave Report</h2>
    <p class="print-meta">Generated by: <?= htmlspecialchars($user['name']) ?> | Date: <?= date('Y-m-d') ?></p>
    <p class="print-filter-summary" id="filterSummary"></p>
    <hr>
  </div>

  <table class="leave-table" id="reportTablePrint">
    <thead>
      <tr>
        <th>No.</th>
        <th>Employee</th>
        <th>Leave Type</th>
        <th>Start Date</th>
        <th>End Date</th>
        <th>Total Days</th>
        <th>Requested On</th>
      </tr>
    </thead>
    <tbody>
      <?php $i = 1; foreach($summary as $row): ?>
        <tr>
          <td><?= $i++ ?></td>
          <td><?= htmlspecialchars($row['employee_name']) ?></td>
          <td><?= htmlspecialchars($row['leave_type']) ?></td>
          <td><?= htmlspecialchars($row['start_date']) ?></td>
          <td><?= htmlspecialchars($row['end_date']) ?></td>
          <td><?= (int)$row['total_days'] ?></td>
          <td><?= htmlspecialchars(date('Y-m-d', strtotime($row['applied_at']))) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="print-footer">
    <p>Teraju Learning Management System — Confidential</p>
  </div>
</div>

<script>
function filterTable() {
  const emp = document.getElementById('employeeFilter').value.toLowerCase();
  const type = document.getElementById('typeFilter').value.toLowerCase();
  const start = document.getElementById('startDateFilter').value;
  const end = document.getElementById('endDateFilter').value;
  const rows = document.querySelectorAll('#reportTable tbody tr');

  rows.forEach(row => {
    const name = row.cells[1].textContent.toLowerCase();
    const leaveType = row.cells[2].textContent.toLowerCase();
    const startDate = row.cells[3].textContent;
    const endDate = row.cells[4].textContent;
    let show = true;
    if (emp && !name.includes(emp)) show = false;
    if (type && !leaveType.includes(type)) show = false;
    if (start && endDate < start) show = false;
    if (end && startDate > end) show = false;
    row.style.display = show ? '' : 'none';
  });
}

function printReport() {
  const emp = document.getElementById('employeeFilter').value || 'All Employees';
  const type = document.getElementById('typeFilter').value || 'All Leave Types';
  const start = document.getElementById('startDateFilter').value || 'Any';
  const end = document.getElementById('endDateFilter').value || 'Any';
  
  document.getElementById('filterSummary').textContent =
      `Filters — Employee: ${emp}, Type: ${type}, Date Range: ${start} to ${end}`;

  // --- NEW CODE: Copy filtered rows from visible table ---
  const mainRows = document.querySelectorAll('#reportTable tbody tr');
  const printBody = document.querySelector('#reportTablePrint tbody');
  printBody.innerHTML = ''; // Clear old rows

  mainRows.forEach(row => {
    if (row.style.display !== 'none') {
      printBody.appendChild(row.cloneNode(true)); // clone visible rows only
    }
  });

  // Now print only the filtered data
  window.print();
}

document.getElementById('employeeFilter').addEventListener('change', filterTable);
document.getElementById('typeFilter').addEventListener('change', filterTable);
document.getElementById('startDateFilter').addEventListener('change', filterTable);
document.getElementById('endDateFilter').addEventListener('change', filterTable);
</script>
<script src="../../assets/js/sidebar.js"></script>
</body>
</html>
