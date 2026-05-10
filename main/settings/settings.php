<?php
  session_start();
  include_once "../../config/bd.php";
  include_once "../../config/security.php";

  require_login("../../index.php");

  $user_id  = $_SESSION['user_id'];
  $success  = '';
  $error    = '';

  // --- ALTERAR PASSWORD ---
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
      verify_csrf_token();

      if ($_POST['action'] === 'change_password') {
          $current  = $_POST['current_password'] ?? '';
          $new      = $_POST['new_password'] ?? '';
          $confirm  = $_POST['confirm_password'] ?? '';

          $stmt = $conn->prepare("SELECT password_hash FROM users WHERE id = ?");
          $stmt->bind_param("i", $user_id);
          $stmt->execute();
          $row = $stmt->get_result()->fetch_assoc();

          if (!$row || !password_verify($current, $row['password_hash'])) {
              $error = 'A password atual esta incorreta.';
          } elseif (strlen($new) < 6) {
              $error = 'A nova password deve ter pelo menos 6 caracteres.';
          } elseif ($new !== $confirm) {
              $error = 'As passwords nao coincidem.';
          } else {
              $hash = password_hash($new, PASSWORD_DEFAULT);
              $stmt = $conn->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
              $stmt->bind_param("si", $hash, $user_id);
              $stmt->execute();
              $success = 'Password alterada com sucesso.';
          }
      }

      // --- ADICIONAR CATEGORIA ---
      if ($_POST['action'] === 'add_category') {
          $name = trim($_POST['category_name'] ?? '');
          if ($name === '') {
              $error = 'O nome da categoria não pode estar vazio.';
          } elseif (strlen($name) > 60) {
              $error = 'O nome da categoria deve ter no maximo 60 caracteres.';
          } else {
              $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ?");
              $stmt->bind_param("s", $name);
              $stmt->execute();
              $exists = $stmt->get_result()->fetch_assoc();
              if ($exists) {
                  $error = 'Já existe uma categoria com esse nome.';
              } else {
                  $stmt = $conn->prepare("INSERT INTO categories (name) VALUES (?)");
                  $stmt->bind_param("s", $name);
                  $stmt->execute();
                  $success = "Categoria \"$name\" adicionada com sucesso.";
              }
          }
      }

      // --- RENOMEAR CATEGORIA ---
      if ($_POST['action'] === 'rename_category') {
          $cat_id = intval($_POST['cat_id'] ?? 0);
          $name = trim($_POST['category_name'] ?? '');

          if ($cat_id <= 0) {
              $error = 'Categoria invalida.';
          } elseif ($name === '') {
              $error = 'O nome da categoria nao pode estar vazio.';
          } elseif (strlen($name) > 60) {
              $error = 'O nome da categoria deve ter no maximo 60 caracteres.';
          } else {
              $stmt = $conn->prepare("SELECT id FROM categories WHERE name = ? AND id <> ?");
              $stmt->bind_param("si", $name, $cat_id);
              $stmt->execute();
              $exists = $stmt->get_result()->fetch_assoc();

              if ($exists) {
                  $error = 'Ja existe uma categoria com esse nome.';
              } else {
                  $stmt = $conn->prepare("UPDATE categories SET name = ? WHERE id = ?");
                  $stmt->bind_param("si", $name, $cat_id);
                  $stmt->execute();
                  $success = flash_message('category_renamed');
              }
          }
      }

      // --- REMOVER CATEGORIA ---
      if ($_POST['action'] === 'delete_category') {
          $cat_id     = intval($_POST['cat_id'] ?? 0);
          $move_to_id = intval($_POST['move_to_id'] ?? 0);

          if ($cat_id === $move_to_id) {
              $error = 'Tens de escolher uma categoria diferente para mover as despesas.';
          } elseif ($cat_id <= 0 || $move_to_id <= 0) {
              $error = 'Categoria inválida.';
          } else {
              $stmt = $conn->prepare("UPDATE transactions SET category_id = ? WHERE category_id = ? AND user_id = ?");
              $stmt->bind_param("iii", $move_to_id, $cat_id, $user_id);
              $stmt->execute();

              $stmt = $conn->prepare("SELECT id FROM transactions WHERE category_id = ? LIMIT 1");
              $stmt->bind_param("i", $cat_id);
              $stmt->execute();
              $stillUsed = $stmt->get_result()->fetch_assoc();

              if ($stillUsed) {
                  $error = 'As tuas despesas foram movidas, mas a categoria continua em uso por outro utilizador.';
              } else {
                  $stmt = $conn->prepare("DELETE FROM categories WHERE id = ?");
                  $stmt->bind_param("i", $cat_id);
                  $stmt->execute();
                  $success = flash_message('category_deleted');
              }
          }
      }
  }

  // --- BUSCAR TODAS AS CATEGORIAS ---
  $stmt_cats = $conn->prepare("SELECT id, name FROM categories ORDER BY name ASC");
  $stmt_cats->execute();
  $all_categories = [];
  $res_cats = $stmt_cats->get_result();
  while ($c = $res_cats->fetch_assoc()) {
      $all_categories[] = $c;
  }

  $category_counts = [];
  $stmt_counts = $conn->prepare("SELECT category_id, COUNT(*) AS total FROM transactions WHERE user_id = ? GROUP BY category_id");
  $stmt_counts->bind_param("i", $user_id);
  $stmt_counts->execute();
  $res_counts = $stmt_counts->get_result();
  while ($row = $res_counts->fetch_assoc()) {
      $category_counts[(int)$row['category_id']] = (int)$row['total'];
  }

  // --- BUSCAR DADOS DO UTILIZADOR ---
  $stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $user = $stmt->get_result()->fetch_assoc();

  // --- NAVLINKS DINÂMICOS ---
  $navLinks = [["../dashboard/dashboard.php", "Inicio"]];
  foreach ($all_categories as $c) {
      $navLinks[] = ["../options/option.php?cat=" . $c['id'], $c['name']];
  }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Definições — LifePlanner</title>
    <link rel="stylesheet" href="./stylesSettings.css">
    <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>"><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php" class="active">Definições</a></li>
    <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
  </ul>
</nav>

<div class="page">

  <h1 class="page-title">Definições</h1>
  <p class="page-subtitle">Gere a tua conta e preferências.</p>

  <?php if ($success): ?>
  <div class="alert alert-success"><?= e($success) ?></div>
  <?php elseif ($error): ?>
  <div class="alert alert-error"><?= e($error) ?></div>
  <?php endif; ?>

  <!-- Conta -->
  <div class="card">
    <div class="card-title">Conta</div>
    <div class="user-row">
      <div class="user-avatar"><?= e(strtoupper(substr($user['username'], 0, 1))) ?></div>
      <div>
        <div class="user-name"><?= e($user['username']) ?></div>
        <div class="user-label">Utilizador</div>
      </div>
    </div>
  </div>

  <!-- Alterar Password -->
  <div class="card">
    <div class="card-title">Alterar Password</div>
    <form method="post" action="">
      <input type="hidden" name="action" value="change_password">
      <?= csrf_field() ?>
      <div class="form-stack">
        <div class="form-field">
          <label for="current_password">Password atual</label>
          <input type="password" id="current_password" name="current_password" required autocomplete="current-password">
        </div>
        <div class="form-field">
          <label for="new_password">Nova password</label>
          <input type="password" id="new_password" name="new_password" required autocomplete="new-password">
        </div>
        <div class="form-field">
          <label for="confirm_password">Confirmar nova password</label>
          <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password">
        </div>
        <button type="submit" class="btn">Guardar password</button>
      </div>
    </form>
  </div>

  <!-- Categorias -->
  <div class="card">
    <div class="card-title">Categorias</div>

    <!-- Adicionar categoria -->
    <p class="section-label">Adicionar nova categoria</p>
    <form method="post" action="" class="inline-form" autocomplete="off">
      <input type="hidden" name="action" value="add_category">
      <?= csrf_field() ?>
      <input type="text" name="category_name" placeholder="Nome da categoria" maxlength="60" required>
      <button type="submit" class="btn">Adicionar</button>
    </form>

    <!-- Lista de categorias existentes -->
    <?php if (!empty($all_categories)): ?>
    <p class="section-label" style="margin-top:20px;">Renomear categoria</p>
    <form method="post" action="" class="delete-cat-form" autocomplete="off">
      <input type="hidden" name="action" value="rename_category">
      <?= csrf_field() ?>
      <div class="delete-cat-row">
        <select name="cat_id" required>
          <option value="" disabled selected>Categoria</option>
          <?php foreach ($all_categories as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <input type="text" name="category_name" placeholder="Novo nome" maxlength="60" required>
        <button type="submit" class="btn">Renomear</button>
      </div>
    </form>

    <p class="section-label" style="margin-top:20px;">Remover categoria</p>
    <p class="section-hint">As despesas da categoria removida serão movidas para a categoria que escolheres.</p>
    <form method="post" action="" class="delete-cat-form">
      <input type="hidden" name="action" value="delete_category">
      <?= csrf_field() ?>
      <div class="delete-cat-row">
        <select name="cat_id" required>
          <option value="" disabled selected>Categoria a remover</option>
          <?php foreach ($all_categories as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['name']) ?> (<?= $category_counts[(int)$c['id']] ?? 0 ?> despesas)</option>
          <?php endforeach; ?>
        </select>
        <span class="arrow-label">→ mover para →</span>
        <select name="move_to_id" required>
          <option value="" disabled selected>Categoria destino</option>
          <?php foreach ($all_categories as $c): ?>
          <option value="<?= $c['id'] ?>"><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-remove"
          onclick="return confirm('Tens a certeza? Esta ação não pode ser desfeita.')">
          Remover
        </button>
      </div>
    </form>

    <!-- Lista atual -->
    <div class="cat-list">
      <?php foreach ($all_categories as $c): ?>
      <span class="cat-badge"><?= e($c['name']) ?></span>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Exportar/Importar Dados -->
  <div class="card">
    <div class="card-title">Exportar/Importar Dados</div>
    <p style="font-size:0.84rem; color:var(--muted); margin-bottom:14px;">
      Exporta os teus dados para um ficheiro JSON ou importa de volta.
    </p>
    <div class="export-import-row">
      <a href="./import.php" class="btn btn-ghost">Gerir dados (Import/Export)</a>
    </div>
  </div>



  <!-- Zona de perigo -->
  <div class="card">
    <div class="card-title">Zona de Perigo</div>
    <p style="font-size:0.84rem; color:var(--muted); margin-bottom:14px;">
      Terminar sessao remove o teu acesso imediatamente.
    </p>
    <a href="../../config/logout.php" class="btn btn-danger">Terminar sessao</a>
  </div>

</div>
</body>
</html>
