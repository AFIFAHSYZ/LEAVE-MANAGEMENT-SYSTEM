<?php
session_start();
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Teraju LMS</title>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
* { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Inter', sans-serif; }
body { background: linear-gradient(135deg, #e8f0f7, #f6fbff); color: #1f2937; min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; }
.container { text-align: center; background: #ffffffcc; backdrop-filter: blur(8px); border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.08); padding: 50px 40px; width: 90%; max-width: 450px; transition: transform 0.3s ease; }
.container:hover { transform: translateY(-4px); }
h1 { font-size: 2rem; color: #1e40af; margin-bottom: 10px; letter-spacing: 1px; }
p.subtitle { color: #6b7280; font-size: 1rem; margin-bottom: 30px; }
.buttons { display: flex; flex-direction: column; gap: 15px; margin-top: 20px; }
.btn { display: block; text-decoration: none; font-weight: 600; border-radius: 10px; padding: 14px 0; font-size: 1rem; transition: all 0.25s ease; }
.btn-full { background: #2563eb; color: #fff; box-shadow: 0 4px 12px rgba(37,99,235,0.3); }
.btn-full:hover { background: #1d4ed8; transform: translateY(-2px); }
.btn-outline { border: 2px solid #2563eb; color: #2563eb; background: transparent; }
.btn-outline:hover { background: #2563eb; color: #fff; transform: translateY(-2px); }
footer { text-align: center; margin-top: 40px; font-size: 0.9rem; color: #94a3b8; }
@media (max-width: 480px) { .container { padding: 40px 25px; } h1 { font-size: 1.6rem; } }

</style>
</head>
<body>

  <div class="container">
    <h1>Welcome to Teraju LMS</h1>
    <p class="subtitle">Manage your leaves efficiently and effortlessly.</p>

    <div class="buttons">
      <a href="login.php" class="btn btn-full">Login</a>
      <a href="register.php" class="btn btn-outline">Register</a>
    </div>
  </div>

  <footer>
    &copy; <?php echo date('Y'); ?> Teraju HR System
  </footer>

</body>
</html>
