<?php
session_start();
require_once '../../config/conn1.php';

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../../login.php");
    exit();
}

// Check HR role
$stmt = $pdo->prepare("SELECT name, position FROM users WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user || ($user['position'] ?? '') !== 'hr') {
    header("Location: ../../unauthorized.php");
    exit();
}

// Get employee ID
$emp_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($emp_id <= 0) {
    header("Location: employees.php");
    exit();
}

// Fetch employee info
$stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
$stmt->execute([':id' => $emp_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die("Employee not found.");
}

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
    ORDER BY lb.year DESC, lt.id ASC
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
<title><?= htmlspecialchars($employee['name'] ?? 'Employee') ?> | Leave Balances</title>
<link rel="stylesheet" href="../../assets/css/style.css">
<style>
  body { font-family: 'Inter', sans-serif; background: #f9fafb; color: #111827; }
  .card { background: #fff; border-radius: 16px; box-shadow: 0 2px 8px rgba(0,0,0,0.05); padding: 20px; margin-bottom: 30px; }
  .card h2 { margin-bottom: 5px; font-size: 1.5rem; color: #1e293b; }
  .employee-info { margin-bottom: 20px; background: #f8fafc; padding: 15px; border-radius: 10px; }
  .employee-info p { margin: 5px 0; }

  table.leave-table { width: 100%; border-collapse: collapse; margin-top: 10px; border-radius: 10px; overflow: hidden; font-size: 0.9rem; }
  table.leave-table th, table.leave-table td { border: 1px solid #e2e8f0; padding: 8px 10px; text-align: center; }
  table.leave-table th { background: #334155; color: #fff; text-transform: uppercase; font-size: 0.8rem; letter-spacing: 0.5px; }
  table.leave-table tr:nth-child(even) { background: #f1f5f9; }
  table.leave-table tr:hover { background: #e2e8f0; }

  .editable-cell { position: relative; min-width: 110px; }
  .cell-value { display: inline-block; font-weight: 500; }
  .cell-input { width: 90px; text-align: center; padding: 4px; border: 1px solid #cbd5e1; border-radius: 6px; display: none; }

  .action-cell { min-width: 140px; }
  .action-btn { background: none; border: none; cursor: pointer; font-size: 1.1rem; transition: 0.2s; padding: 4px 6px; }
  .action-btn.edit { color: #2563eb; }
  .action-btn.save { color: #16a34a; display: none; }
  .action-btn:hover { transform: scale(1.1); }

  .status-msg {position: fixed;top: 16px;right: 16px;background: #16a34a;color: #fff;padding: 10px 15px;border-radius: 8px;box-shadow: 0 2px 10px rgba(0,0,0,0.1);opacity: 0;transform: translateY(-6px);transition: opacity .25s ease, transform .25s ease;z-index: 10000;pointer-events: none;font-weight: 600;}
  .status-msg.show { opacity: 1; transform: translateY(0); }

  .record-table th { background: #334155; color: #fff; }
  .status-badge { display: inline-block; padding: 4px 8px; border-radius: 6px; font-size: 0.85rem; text-transform: capitalize; font-weight: 500; }
  .status-verified { background: #dcfce7; color: #166534; }
  .status-pending { background: #fef9c3; color: #854d0e; }
  .status-rejected { background: #fee2e2; color: #991b1b; }

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

    <div class="card">
      <h2>Leave Balances</h2>
      <h2><?= htmlspecialchars($employee['name'] ?? '') ?></h2>

      <div class="employee-info">
        <p><strong>Email:</strong> <?= htmlspecialchars($employee['email'] ?? '') ?></p>
        <p><strong>Position:</strong> <?= htmlspecialchars(ucfirst((string)($employee['position'] ?? ''))) ?></p>
        <p><strong>Date Joined:</strong> <?= htmlspecialchars((string)($employee['date_joined'] ?? '')) ?></p>
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
        <?php if (count($balances) === 0): ?>
          <tr><td colspan="7" style="text-align:center; color:#6b7280;">No leave balances found.</td></tr>
        <?php else: ?>
          <?php foreach ($balances as $b):
            $isAnnual = (strtolower((string)$b['leave_type']) === 'annual leave');
          ?>
            <tr
              data-leave-id="<?= htmlspecialchars((string)$b['leave_type_id']) ?>"
              data-user-id="<?= htmlspecialchars((string)$emp_id) ?>"
              data-year="<?= htmlspecialchars((string)$b['year']) ?>"
              data-is-annual="<?= $isAnnual ? '1' : '0' ?>"
            >
              <td><?= htmlspecialchars((string)$b['leave_type']) ?></td>
              <td><?= htmlspecialchars((string)$b['year']) ?></td>

              <td class="editable-cell entitled-cell">
                <span class="cell-value entitled-value"><?= htmlspecialchars((string)$b['entitled_days']) ?></span>
                <input type="number" step="0.1" min="0" class="cell-input entitled-input" value="<?= htmlspecialchars((string)$b['entitled_days']) ?>">
              </td>

              <td class="editable-cell used-cell">
                <span class="cell-value used-value"><?= htmlspecialchars((string)$b['used_days']) ?></span>
                <input type="number" step="0.1" min="0" class="cell-input used-input" value="<?= htmlspecialchars((string)$b['used_days']) ?>">
              </td>

              <td class="editable-cell carry-cell">
                <span class="cell-value carry-value"><?= htmlspecialchars((string)$b['carry_forward']) ?></span>
                <?php if ($isAnnual): ?>
                  <input type="number" step="0.1" min="0" max="5" class="cell-input carry-input" value="<?= htmlspecialchars((string)$b['carry_forward']) ?>">
                <?php endif; ?>
              </td>

              <td><strong class="total-available"><?= htmlspecialchars((string)$b['total_available']) ?></strong></td>

              <td class="action-cell">
                <button type="button" class="action-btn edit" title="Edit">✏️</button>
                <button type="button" class="action-btn save" title="Save">✅</button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="card">
      <h2>Leave Records</h2>

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
              <?php $st = strtolower((string)$r['status']); ?>
              <tr>
                <td><?= htmlspecialchars((string)$r['leave_type']) ?></td>
                <td><?= htmlspecialchars((string)$r['start_date']) ?></td>
                <td><?= htmlspecialchars((string)$r['end_date']) ?></td>
                <td><?= htmlspecialchars((string)$r['total_days']) ?></td>
                <td><?= htmlspecialchars((string)$r['reason']) ?></td>
                <td>
                  <span class="status-badge status-<?= htmlspecialchars($st) ?>">
                    <?= htmlspecialchars((string)$r['status']) ?>
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

<div id="status" class="status-msg" role="status" aria-live="polite"></div>

<script src="../../assets/js/sidebar.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  function showStatus(message, type = 'success') {
    const msg = document.getElementById('status');
    if (!msg) return;
    msg.textContent = message;
    msg.style.background = (type === 'success') ? '#16a34a' : '#ef4444';
    msg.style.color = '#fff';
    msg.classList.remove('show');
    requestAnimationFrame(() => {
      msg.classList.add('show');
      setTimeout(() => msg.classList.remove('show'), 2500);
    });
  }

  document.querySelectorAll('#balancesTable tbody tr').forEach(row => {
    const isAnnual = row.dataset.isAnnual === '1';

    const entitledSpan = row.querySelector('.entitled-value');
    const usedSpan = row.querySelector('.used-value');
    const carrySpan = row.querySelector('.carry-value');

    const entitledInput = row.querySelector('.entitled-input');
    const usedInput = row.querySelector('.used-input');
    const carryInput = row.querySelector('.carry-input');

    const editBtn = row.querySelector('.action-btn.edit');
    const saveBtn = row.querySelector('.action-btn.save');
    const totalStrong = row.querySelector('.total-available');

    if (!entitledSpan || !usedSpan || !entitledInput || !usedInput || !editBtn || !saveBtn || !totalStrong) return;

    function enterEditMode() {
      entitledInput.value = (entitledSpan.textContent || '').trim();
      usedInput.value = (usedSpan.textContent || '').trim();
      if (isAnnual && carryInput && carrySpan) carryInput.value = (carrySpan.textContent || '').trim();

      entitledSpan.style.display = 'none';
      usedSpan.style.display = 'none';
      entitledInput.style.display = 'inline-block';
      usedInput.style.display = 'inline-block';

      if (isAnnual && carryInput && carrySpan) {
        carrySpan.style.display = 'none';
        carryInput.style.display = 'inline-block';
      }

      editBtn.style.display = 'none';
      saveBtn.style.display = 'inline-block';
      entitledInput.focus();
    }

    function exitEditMode() {
      entitledSpan.style.display = 'inline-block';
      usedSpan.style.display = 'inline-block';
      entitledInput.style.display = 'none';
      usedInput.style.display = 'none';

      if (isAnnual && carryInput && carrySpan) {
        carrySpan.style.display = 'inline-block';
        carryInput.style.display = 'none';
      }

      saveBtn.style.display = 'none';
      editBtn.style.display = 'inline-block';
    }

    editBtn.addEventListener('click', enterEditMode);

    saveBtn.addEventListener('click', async () => {
      const entitledStr = (entitledInput.value ?? '').trim();
      const usedStr = (usedInput.value ?? '').trim();
      const carryStr = (carryInput ? (carryInput.value ?? '').trim() : '');

      if (entitledStr === '' || isNaN(Number(entitledStr)) || Number(entitledStr) < 0) {
        showStatus('Entitled must be a valid number (>= 0).', 'error');
        return;
      }
      if (usedStr === '' || isNaN(Number(usedStr)) || Number(usedStr) < 0) {
        showStatus('Used must be a valid number (>= 0).', 'error');
        return;
      }

      const formData = new FormData();
      formData.append('user_id', row.dataset.userId);
      formData.append('leave_id', row.dataset.leaveId);
      formData.append('year', row.dataset.year);
      formData.append('entitled_days', entitledStr);
      formData.append('used_days', usedStr);

      if (isAnnual && carryInput) {
        formData.append('carry_forward', carryStr === '' ? '0' : carryStr);
      }

      try {
        const response = await fetch('update_balance_ajax.php', { method: 'POST', body: formData });
        const raw = await response.text();

        let data;
        try { data = JSON.parse(raw); } catch (e) {
          console.error('Invalid JSON:', raw);
          showStatus('Invalid server response.', 'error');
          return;
        }

        if (!response.ok || !data.success) {
          showStatus(data.message || 'Error updating.', 'error');
          return;
        }

        entitledSpan.textContent = data.entitled_days;
        usedSpan.textContent = data.used_days;
        if (isAnnual && carrySpan && typeof data.carry_forward !== 'undefined') {
          carrySpan.textContent = data.carry_forward;
        }
        if (typeof data.total_available !== 'undefined') {
          totalStrong.textContent = data.total_available;
        }

        exitEditMode();
        showStatus('Leave balance updated ✅', 'success');
      } catch (err) {
        console.error(err);
        showStatus('Network/server error.', 'error');
      }
    });
  });
});
</script>
</body>
</html>