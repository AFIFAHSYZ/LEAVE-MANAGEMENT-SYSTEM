<?php
session_start();
require_once '../../config/conn.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if ($user['position'] !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Get employee ID
$emp_id = $_GET['id'] ?? null;
if (!$emp_id) {
    header("Location: employees.php");
    exit();
}

// Fetch employee info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

// Fetch leave balances
$sql = "
    SELECT 
        lt.id AS leave_type_id,
        lt.name AS leave_type,
        lb.year,
        lb.entitled_days,
        lb.used_days,
        lb.carry_forward,
        lb.total_available
    FROM leave_balances lb
    JOIN leave_types lt ON lb.leave_type_id = lt.id
    WHERE lb.user_id = :emp_id
    ORDER BY lt.id
";
$stmt = $pdo->prepare($sql);
$stmt->execute([':emp_id' => $emp_id]);
$balances = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch leave records
$sql2 = "
    SELECT 
        lr.id,
        lt.name AS leave_type,
        lr.start_date,
        lr.end_date,
        lr.total_days,
        lr.reason,
        lr.status
    FROM leave_requests lr
    JOIN leave_types lt ON lr.leave_type_id = lt.id
    WHERE lr.user_id = :emp_id
    ORDER BY lr.start_date DESC
";
$stmt2 = $pdo->prepare($sql2);
$stmt2->execute([':emp_id' => $emp_id]);
$requests = $stmt2->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($employee['name']) ?> | Leave Balances</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
  body { font-family: 'Inter', sans-serif; background: #f9fafb; color: #111827; }
  .card { background: #fff; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 30px; }
  .card h2 { margin-bottom: 5px; font-size: 1.5rem; color: #1e293b; }
  .employee-info { margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 10px; }
  .employee-info p { margin: 5px 0; }

  /* Table Styling */
  table.leave-table { width: 100%; border-collapse: collapse; margin-top: 10px; border-radius: 10px; overflow: hidden; font-size: 0.9rem; }
  table.leave-table th, table.leave-table td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: center; }
  table.leave-table th { background: #334155; color: #fff; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
  table.leave-table tr:nth-child(even) { background: #f1f5f9; }
  table.leave-table tr:hover { background: #e2e8f0; }

  /* Editable cells */
  .editable-cell { position: relative; min-width: 100px; }
  .cell-value { display: inline-block; font-weight: 500; }
  .cell-input { width: 80px; text-align: center; padding: 4px; border: 1px solid #cbd5e1; border-radius: 6px; display: none; }

  /* Action buttons only in Action column */
  .action-cell { min-width: 140px; }
  .action-btn { background: none; border: none; cursor: pointer; font-size: 1.1rem; transition: 0.2s; padding: 4px 6px; }
  .action-btn.edit { color: #2563eb; }
  .action-btn.save { color: #16a34a; display: none; }
  .action-btn:hover { transform: scale(1.1); }
  .action-btn:disabled { color: #9ca3af; cursor: not-allowed; }

  /* Toast */
  .status-msg {position: fixed;top: 16px;right: 16px;background: #16a34a;color: #fff;padding: 10px 15px;border-radius: 8px;box-shadow: 0 2px 10px rgba(0,0,0,0.1);opacity: 0;transform: translateY(-6px);transition: opacity .25s ease, transform .25s ease;z-index: 10000;    pointer-events: none;font-weight: 600;}
  .status-msg.show { opacity: 1; transform: translateY(0); }

  /* Leave Record Table */
  .record-table th { background: #334155; color: #fff; }
  .status-badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; text-transform: capitalize; font-weight: 500; }
  .status-approved { background: #dcfce7; color: #166534; }
  .status-pending { background: #fef9c3; color: #854d0e; }
  .status-rejected { background: #fee2e2; color: #991b1b; }

  /* Filter Controls */
  .filters { display: flex; gap: 10px; align-items: center; }
  .filters select, .filters button { padding: 6px 10px; border: 1px solid #cbd5e1; border-radius: 6px; background: #fff; cursor: pointer; }
  .filters button { background: #e5e7eb; }
  .filters button:hover { background: #d1d5db; }

  .btn-back {background: #64748b;color: #fff;padding: 6px 12px;border-radius: 6px;text-decoration: none;font-weight: 500;transition: 0.2s;}
  .btn-back:hover {background: #475569;}
</style>
</head>
<body>
<div class="layout">
  <?php include 'sidebar.php'; ?>
  <header><h1>Leave Management System</h1></header>

  <main class="main-content">
    <div style="margin-top:15px; margin-bottom: 15px;">
      <a href="employees.php" class="btn-back">← Back to List</a>
    </div>

    <!-- Leave Balances -->
    <div class="card">
      <h2>Leave Balances</h2>
      <h2><?= htmlspecialchars($employee['name']) ?></h2>

      <div class="employee-info">
        <p><strong>Email:</strong> <?= htmlspecialchars($employee['email']) ?></p>
        <p><strong>Position:</strong> <?= ucfirst($employee['position']) ?></p>
        <p><strong>Race:</strong> <?= $employee['race'] ?></p>
        <p><strong>Religion:</strong> <?= $employee['religion'] ?></p>
        <p><strong>Project:</strong> <?= $employee['project'] ?></p>
        <p><strong>Contract:</strong> <?= $employee['contract'] ?></p>
        <p><strong>Date Joined:</strong> <?= $employee['date_joined'] ?></p>
      </div>

      <table class="leave-table" id="balancesTable">
        <thead>
          <tr>
            <th>Leave Type</th>
            <th>Year</th>
            <th>Entitled</th>
            <th>Used</th>
            <th>Carry Forward</th>
            <th>Total Available</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($balances as $b):
            $isAnnual = strtolower($b['leave_type']) === 'annual leave';
          ?>
            <tr
              data-leave-id="<?= htmlspecialchars($b['leave_type_id']) ?>"
              data-user-id="<?= htmlspecialchars($emp_id) ?>"
              data-is-annual="<?= $isAnnual ? '1' : '0' ?>"
            >
              <td><?= htmlspecialchars($b['leave_type']) ?></td>
              <td><?= htmlspecialchars($b['year']) ?></td>

              <!-- Entitled (editable when editing) -->
              <td class="editable-cell entitled-cell">
                <span class="cell-value"><?= htmlspecialchars($b['entitled_days']) ?></span>
                <input type="number" class="cell-input entitled-input" min="0" value="<?= htmlspecialchars($b['entitled_days']) ?>">
              </td>

              <!-- Used (editable when editing) -->
              <td class="editable-cell used-cell">
                <span class="cell-value"><?= htmlspecialchars($b['used_days']) ?></span>
                <input type="number" class="cell-input used-input" min="0" value="<?= htmlspecialchars($b['used_days']) ?>">
              </td>

              <!-- Carry Forward (editable only for Annual Leave) -->
              <td class="editable-cell carry-cell">
                <span class="cell-value carry-value"><?= htmlspecialchars($b['carry_forward']) ?></span>
                <?php if ($isAnnual): ?>
                  <input type="number" class="cell-input carry-input" min="0" max="5" value="<?= htmlspecialchars($b['carry_forward']) ?>">
                <?php endif; ?>
              </td>

              <td><strong class="total-available"><?= htmlspecialchars($b['total_available']) ?></strong></td>

              <td class="action-cell">
                <button class="action-btn edit" title="Edit">✏️</button>
                <button class="action-btn save" title="Save">✅</button>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Leave Records -->
    <div class="card">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <h2>Leave Records</h2>
        <div class="filters">
          <label>Type:</label>
          <select id="filterType">
            <option value="all">All</option>
            <?php
            $types = array_unique(array_column($requests, 'leave_type'));
            foreach ($types as $t): ?>
              <option value="<?= htmlspecialchars($t) ?>"><?= htmlspecialchars($t) ?></option>
            <?php endforeach; ?>
          </select>

          <label>Status:</label>
          <select id="filterStatus">
            <option value="all">All</option>
            <option value="approved">Approved</option>
            <option value="verified">Verified</option>
            <option value="pending">Pending</option>
            <option value="rejected">Rejected</option>
          </select>

          <button id="clearFilter">Clear</button>
        </div>
      </div>

      <table class="leave-table record-table" id="recordTable">
        <thead>
          <tr>
            <th>Leave Type</th>
            <th>Start Date</th>
            <th>End Date</th>
            <th>Days</th>
            <th>Reason</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
          <?php if (count($requests) > 0): ?>
            <?php foreach ($requests as $r): ?>
              <tr data-type="<?= htmlspecialchars(strtolower($r['leave_type'])) ?>" data-status="<?= htmlspecialchars(strtolower($r['status'])) ?>">
                <td><?= htmlspecialchars($r['leave_type']) ?></td>
                <td><?= htmlspecialchars($r['start_date']) ?></td>
                <td><?= htmlspecialchars($r['end_date']) ?></td>
                <td><?= htmlspecialchars($r['total_days']) ?></td>
                <td><?= htmlspecialchars($r['reason']) ?></td>
                <td>
                  <span class="status-badge status-<?= strtolower($r['status']) ?>">
                    <?= htmlspecialchars($r['status']) ?>
                  </span>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr><td colspan="6" style="text-align:center; color:#6b7280;">No leave records found.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </main>
</div>

<!-- Toast element -->
<div id="status" class="status-msg" role="status" aria-live="polite">Leave balance updated ✅</div>

<script src="../../assets/js/sidebar.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  // ---------------- Filter Logic ----------------
  const filterType = document.getElementById('filterType');
  const filterStatus = document.getElementById('filterStatus');
  const clearFilter = document.getElementById('clearFilter');
  const rows = document.querySelectorAll('#recordTable tbody tr');

  function applyFilters() {
    const selectedType = (filterType?.value || 'all').toLowerCase();
    const selectedStatus = (filterStatus?.value || 'all').toLowerCase();

    rows.forEach(row => {
      const type = row.dataset.type;
      const status = row.dataset.status;
      const matchType = selectedType === 'all' || type === selectedType;
      const matchStatus = selectedStatus === 'all' || status === selectedStatus;
      row.style.display = (matchType && matchStatus) ? '' : 'none';
    });
  }

  filterType?.addEventListener('change', applyFilters);
  filterStatus?.addEventListener('change', applyFilters);
  clearFilter?.addEventListener('click', () => {
    if (filterType) filterType.value = 'all';
    if (filterStatus) filterStatus.value = 'all';
    rows.forEach(row => row.style.display = '');
  });

  // ---------------- Toast ----------------
  function showStatus(message = 'Leave balance updated ✅', type = 'success') {
    const msg = document.getElementById('status');
    if (!msg) {
      console.warn('#status element not found.');
      return;
    }
    msg.textContent = message;
    if (type === 'success') {
      msg.style.background = '#16a34a';
      msg.style.color = '#fff';
    } else {
      msg.style.background = '#ef4444';
      msg.style.color = '#fff';
    }
    msg.classList.remove('show');
    requestAnimationFrame(() => {
      msg.classList.add('show');
      setTimeout(() => msg.classList.remove('show'), 2500);
    });
  }

  // ---------------- Inline Edit ----------------
  document.querySelectorAll('#balancesTable tbody tr').forEach(row => {
    const isAnnual = row.dataset.isAnnual === '1';

    const entitledCell = row.querySelector('.entitled-cell');
    const usedCell = row.querySelector('.used-cell');
    const carryCell = row.querySelector('.carry-cell');
    if (!entitledCell || !usedCell || !carryCell) return;

    const entitledSpan = entitledCell.querySelector('.cell-value');
    const usedSpan = usedCell.querySelector('.cell-value');
    const carrySpan = carryCell.querySelector('.carry-value');

    const entitledInput = entitledCell.querySelector('.entitled-input');
    const usedInput = usedCell.querySelector('.used-input');
    const carryInput = carryCell.querySelector('.carry-input'); // may be null

    const actionCell = row.querySelector('.action-cell');
    const editBtn = actionCell?.querySelector('.action-btn.edit');
    const saveBtn = actionCell?.querySelector('.action-btn.save');
    if (!editBtn || !saveBtn) return;

    const leaveId = row.dataset.leaveId;
    const userId = row.dataset.userId;
    const totalStrong = row.querySelector('.total-available');

    editBtn.addEventListener('click', () => {
      [entitledSpan, usedSpan].forEach(s => s.style.display = 'none');
      [entitledInput, usedInput].forEach(i => { if (i) i.style.display = 'inline-block'; });

      if (isAnnual && carryInput && carrySpan) {
        carrySpan.style.display = 'none';
        carryInput.style.display = 'inline-block';
      }

      editBtn.style.display = 'none';
      saveBtn.style.display = 'inline-block';
      entitledInput?.focus();
    });

    saveBtn.addEventListener('click', async () => {
      let entitled = parseInt(entitledInput?.value ?? '0') || 0;
      let used = parseInt(usedInput?.value ?? '0') || 0;

      const formData = new FormData();
      formData.append('user_id', userId);
      formData.append('leave_id', leaveId);
      formData.append('entitled_days', entitled);
      formData.append('used_days', used);

      if (isAnnual && carryInput) {
        let carry = parseInt(carryInput.value) || 0;
        carry = Math.max(0, Math.min(5, carry));
        formData.append('carry_forward', carry);
      }

      try {
        const response = await fetch('update_balance_ajax.php', { method: 'POST', body: formData });

        if (!response.ok) {
          const text = await response.text().catch(() => '');
          console.error('Non-OK response:', response.status, text);
          showStatus('Error updating leave balance (network/server).', 'error');
          return;
        }

        // Read once then parse
        const raw = await response.text();
        let data;
        try {
          data = JSON.parse(raw);
        } catch (e) {
          console.error('Invalid JSON response:', raw);
          showStatus('Error updating leave balance (invalid server response).', 'error');
          return;
        }

        if (data && data.success) {
          entitledSpan.textContent = data.entitled_days ?? entitledSpan.textContent;
          usedSpan.textContent = data.used_days ?? usedSpan.textContent;
          if (isAnnual && carrySpan && typeof data.carry_forward !== 'undefined') {
            carrySpan.textContent = data.carry_forward;
          }
          if (totalStrong && typeof data.total_available !== 'undefined') {
            totalStrong.textContent = data.total_available;
          }

          [entitledSpan, usedSpan].forEach(s => s.style.display = 'inline-block');
          [entitledInput, usedInput].forEach(i => { if (i) i.style.display = 'none'; });

          if (isAnnual && carryInput && carrySpan) {
            carrySpan.style.display = 'inline-block';
            carryInput.style.display = 'none';
          }

          saveBtn.style.display = 'none';
          editBtn.style.display = 'inline-block';
          showStatus('Leave balance updated ✅', 'success');
        } else {
          console.error('Update failed payload:', data);
          showStatus((data && data.message) ? data.message : 'Error updating leave balance.', 'error');
        }
      } catch (err) {
        console.error('Fetch error:', err);
        showStatus('Error updating leave balance.', 'error');
      }
    });

    // Keyboard shortcuts
    [entitledInput, usedInput, carryInput].forEach(inp => {
      if (!inp) return;
      inp.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          e.preventDefault();
          saveBtn.click();
        }
        if (e.key === 'Escape') {
          // cancel: reset inputs to current values
          if (entitledInput) entitledInput.value = entitledSpan.textContent;
          if (usedInput) usedInput.value = usedSpan.textContent;
          if (isAnnual && carryInput && carrySpan) carryInput.value = carrySpan.textContent;

          [entitledSpan, usedSpan].forEach(s => s.style.display = 'inline-block');
          [entitledInput, usedInput].forEach(i => { if (i) i.style.display = 'none'; });

          if (isAnnual && carryInput && carrySpan) {
            carrySpan.style.display = 'inline-block';
            carryInput.style.display = 'none';
          }

          saveBtn.style.display = 'none';
          editBtn.style.display = 'inline-block';
        }
      });
    });
  });

  // Uncomment to test toast quickly:
  // showStatus('Test success ✅', 'success');
  // showStatus('Test error ❌', 'error');
});
</script>
</html>