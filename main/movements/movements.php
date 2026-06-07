<?php
  session_start();
  include_once "../../config/bd.php";
  include_once "../../config/security.php";
  include_once "../../config/repository.php";

  require_login("../../index.php");

  $user_id = $_SESSION['user_id'];
  $categories = get_categories($conn);
  $navLinks = build_nav_links($categories);

  $q = trim($_GET['q'] ?? '');
  $category_id = isset($_GET['category_id']) && $_GET['category_id'] !== '' ? max(1, (int)$_GET['category_id']) : null;
  $nif = validate_nif_filter($_GET['nif'] ?? 'all');
  $date_from = $_GET['date_from'] ?? '';
  $date_to = $_GET['date_to'] ?? '';

  if ($date_from !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
      $date_from = '';
  }
  if ($date_to !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
      $date_to = '';
  }

  $conditions = ["t.user_id = ?"];
  $types = "i";
  $params = [$user_id];

  if ($q !== '') {
      $like = '%' . $q . '%';
      $conditions[] = "(t.description LIKE ? OR t.detail LIKE ? OR t.date LIKE ? OR CAST(t.amount AS TEXT) LIKE ?)";
      $types .= "ssss";
      array_push($params, $like, $like, $like, $like);
  }
  if ($category_id) {
      $conditions[] = "t.category_id = ?";
      $types .= "i";
      $params[] = $category_id;
  }
  if ($nif !== 'all') {
      $conditions[] = "t.nif = ?";
      $types .= "i";
      $params[] = (int)$nif;
  }
  if ($date_from !== '') {
      $conditions[] = "t.date >= ?";
      $types .= "s";
      $params[] = $date_from;
  }
  if ($date_to !== '') {
      $conditions[] = "t.date <= ?";
      $types .= "s";
      $params[] = $date_to;
  }

  $where = implode(" AND ", $conditions);
  $stmt = $conn->prepare("
      SELECT t.id, t.amount, t.date, t.description, t.detail, t.nif, c.name AS categoria, c.id AS category_id
      FROM transactions t
      LEFT JOIN categories c ON c.id = t.category_id
      WHERE $where
      ORDER BY t.date DESC, t.id DESC
      LIMIT 300
  ");
  $refs = [];
  foreach ($params as $key => $value) {
      $refs[$key] = &$params[$key];
  }
  $stmt->bind_param($types, ...$refs);
  $stmt->execute();
  $result = $stmt->get_result();

  $transactions = [];
  $total = 0;
  while ($row = $result->fetch_assoc()) {
      $row['amount'] = (float)$row['amount'];
      $total += $row['amount'];
      $transactions[] = $row;
  }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Movimentos - LifePlanner</title>
  <link rel="stylesheet" href="../options/stylesOption.css">
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]): ?>
    <li><a href="<?= e($href) ?>" <?= strpos($href, 'movements') !== false ? 'class="active"' : '' ?>><?= e($label) ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php">Definicoes</a></li>
    <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
  </ul>
</nav>

<div class="page">
  <h1 class="page-title">Movimentos</h1>

  <div class="kpi-row">
    <div class="kpi-card red">
      <div class="kpi-label">Total Filtrado</div>
      <div class="kpi-value"><?= number_format($total, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">Registos</div>
      <div class="kpi-value"><?= count($transactions) ?></div>
    </div>
  </div>

  <div class="card">
    <div class="card-title">Filtros</div>
    <form method="get" action="" class="movement-filters" autocomplete="off">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Pesquisar">
      <select name="category_id">
        <option value="">Todas as categorias</option>
        <?php foreach ($categories as $cat): ?>
        <option value="<?= $cat['id'] ?>" <?= $category_id === (int)$cat['id'] ? 'selected' : '' ?>><?= e($cat['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <select name="nif">
        <option value="all" <?= $nif === 'all' ? 'selected' : '' ?>>Todos NIF</option>
        <option value="1" <?= $nif === '1' ? 'selected' : '' ?>>Com NIF</option>
        <option value="0" <?= $nif === '0' ? 'selected' : '' ?>>Sem NIF</option>
      </select>
      <input type="date" name="date_from" value="<?= e($date_from) ?>">
      <input type="date" name="date_to" value="<?= e($date_to) ?>">
      <button type="submit" class="btn">Filtrar</button>
      <a href="movements.php" class="btn-ghost">Limpar</a>
    </form>
  </div>

  <div class="card">
    <div class="card-title">Historico</div>
    <?php if (empty($transactions)): ?>
      <p class="text-muted">Sem movimentos para os filtros selecionados.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Valor</th>
          <th>Data</th>
          <th>Categoria</th>
          <th>Descricao</th>
          <th>Detalhes</th>
          <th>NIF</th>
          <th>Acoes</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($transactions as $row): ?>
        <tr>
          <td class="amount"><?= number_format($row['amount'], 2, ',', '.') ?> €</td>
          <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
          <td><?= e($row['categoria'] ?? 'Sem categoria') ?></td>
          <td><?= e($row['description']) ?></td>
          <td><?= e($row['detail']) ?></td>
          <td><?= (int)$row['nif'] === 1 ? 'Sim' : 'Nao' ?></td>
          <td style="white-space:nowrap">
            <a class="action-link action-edit" href="../options/option.php?cat=<?= (int)$row['category_id'] ?>&edit_id=<?= (int)$row['id'] ?>&year=<?= (int)date('Y', strtotime($row['date'])) ?>">Editar</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
