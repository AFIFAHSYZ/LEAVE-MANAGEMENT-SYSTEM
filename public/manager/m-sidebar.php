<aside class="sidebar">
  <div class="user-profile">
    <h2>LMS</h2>
    <div class="avatar"><?= strtoupper(substr($user['name'], 0, 1)); ?></div>
    <p class="user-name"><?= htmlspecialchars($user['name']); ?></p>
  </div>

  <nav>
    <ul class="menu">
      <li><a href="manager-dashboard.php"> Dashboard</a></li>
          <li><a href="team-leaves.php" >Team Leave</a></li>
          <li><a href="m-reports.php">Leave Reports</a></li>
        <li><a href="m-profile.php" >Profile</a></li>
        <li><a href="../logout.php" class="logout">Logout</a></li>

    </ul>
  </nav>

  <div class="sidebar-footer">&copy; <?= date('Y'); ?> Teraju LMS</div>
</aside>

</script>
<html><style>    
    .sidebar ul li {position: relative;}
    .sidebar ul li a {display: flex; justify-content: space-between; align-items: center; padding: 10px 20px; font-size: 0.9rem; color: #fff; text-decoration: none;}
    .sidebar ul li a .arrow {font-size: 0.8rem; transition: transform 0.3s ease;}
    .sidebar ul li.active > a .arrow {transform: rotate(180deg);}
    .sidebar ul li .dropdown-menu {display: none; flex-direction: column; background: #223b62ff; padding-left: 0;}
    .sidebar ul li.active .dropdown-menu {display: flex !important;flex-direction: column;}
    .sidebar ul li .dropdown-menu li a {padding: 8px 30px; font-size: 0.85rem;}
</style></html>

