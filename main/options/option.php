<?php
  session_start();
  include_once "../../config/bd.php";

  if (!isset($_SESSION['user_id'])) {
      header("Location: ../../index.php");
      exit();
  }

  $category_id = isset($_GET['cat'])  ? intval($_GET['cat'])  : 1;
  $year        = isset($_GET['year']) ? intval($_GET['year']) : (int)date('Y');
  $user_id     = $_SESSION['user_id'];

  // --- APAGAR ---
  if (isset($_GET['delete_id'])) {
      $id_to_delete = intval($_GET['delete_id']);
      $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
      $stmt->bind_param("ii", $id_to_delete, $user_id);
      $stmt->execute();
      header("Location: option.php?cat=" . $category_id . "&year=" . $year);
      exit();
  }

  // --- INSERIR / EDITAR ---
  if ($_SERVER["REQUEST_METHOD"] === "POST") {
      $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 1;
      $amount      = $_POST["amount"];
      $date        = $_POST["date"];
      $description = $_POST["description"];
      $detail      = $_POST["detail"];
      $nif         = $_POST["nif"];
      $cat_to_save = (int)$_POST["category_id"];

      if (!empty($_POST['edit_id'])) {
          $edit_id = intval($_POST['edit_id']);
          $stmt = $conn->prepare("UPDATE transactions SET category_id=?, amount=?, date=?, description=?, detail=?, nif=? WHERE id=? AND user_id=?");
          $stmt->bind_param("isssssii",$category_id, $amount, $date, $description, $detail, $nif, $edit_id, $user_id);
      } else {
          $stmt = $conn->prepare("INSERT INTO transactions (user_id, category_id, amount, date, description, detail, nif) VALUES (?, ?, ?, ?, ?, ?, ?)");
          $stmt->bind_param("iissssi", $user_id, $cat_to_save, $amount, $date, $description, $detail, $nif);
      }
      $stmt->execute();
      header("Location: option.php?cat=" . $cat_to_save . "&year=" . $year);
      exit();
  }


  // --- BUSCAR DADOS PARA EDITAR ---
  $edit_data = null;
  if (isset($_GET['edit_id'])) {
      $id_to_fetch = intval($_GET['edit_id']);
      $stmt_edit = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
      $stmt_edit->bind_param("ii", $id_to_fetch, $user_id);
      $stmt_edit->execute();
      $edit_data = $stmt_edit->get_result()->fetch_assoc();
  }

  // --- NOME DA CATEGORIA ---
  $stmt_cat = $conn->prepare("SELECT name FROM categories WHERE id = ?");
  $stmt_cat->bind_param("i", $category_id);
  $stmt_cat->execute();
  $nome_categoria = $stmt_cat->get_result()->fetch_assoc()['name'] ?? "Desconhecida";

  // --- TOTAL GASTO NESTA CATEGORIA ---
  $stmt_total = $conn->prepare("SELECT SUM(amount) AS total, COUNT(*) AS cnt FROM transactions WHERE category_id = ? AND user_id = ?");
  $stmt_total->bind_param("ii", $category_id, $user_id);
  $stmt_total->execute();
  $totals = $stmt_total->get_result()->fetch_assoc();
  $totalCategoria = (float)($totals['total'] ?? 0);
  $numRegistos    = (int)($totals['cnt'] ?? 0);

  // --- DADOS MENSAIS PARA O GRAFICO (ano selecionado) ---
  $stmtMensal = $conn->prepare("
      SELECT MONTH(date) AS mes, SUM(amount) AS total
      FROM transactions
      WHERE category_id = ? AND user_id = ? AND YEAR(date) = ?
      GROUP BY MONTH(date)
      ORDER BY mes ASC
  ");
  $stmtMensal->bind_param("iii", $category_id, $user_id, $year);
  $stmtMensal->execute();
  $resMensal = $stmtMensal->get_result();

  // Preencher array indexado por número do mês
  $mensalMap = [];
  while ($r = $resMensal->fetch_assoc()) {
      $mensalMap[(int)$r['mes']] = (float)$r['total'];
  }

  $mesesAbrev = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',
                7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
  $chartLabels = [];
  $chartTotals = [];
  $chartDeltas = [];

  for ($m = 1; $m <= 12; $m++) {
      $chartLabels[] = $mesesAbrev[$m];
      $chartTotals[] = $mensalMap[$m] ?? 0;
  }

  // Variação % em relação ao mês anterior (null se mês anterior = 0)
  for ($i = 0; $i < 12; $i++) {
      $prev = $i > 0 ? $chartTotals[$i - 1] : null;
      $curr = $chartTotals[$i];
      if ($prev === null || $prev == 0) {
          $chartDeltas[] = null;
      } else {
          $chartDeltas[] = round((($curr - $prev) / $prev) * 100, 1);
      }
  }

  $jsLabels  = json_encode($chartLabels);
  $jsTotals  = json_encode($chartTotals);
  $jsDeltas  = json_encode($chartDeltas);
  // Cores das barras: verde se desceu ou igual, vermelho se subiu
  $jsColors = json_encode(array_map(function($d) {
      if ($d === null || $d <= 0) return 'rgba(22,163,74,0.75)';
      return 'rgba(220,38,38,0.75)';
  }, $chartDeltas));
  $jsBorderColors = json_encode(array_map(function($d) {
      if ($d === null || $d <= 0) return '#16a34a';
      return '#dc2626';
  }, $chartDeltas));

  $navLinks = [
      ["../dashboard/dashboard.php", "Inicio"],
      ["../options/option.php?cat=1", "Carro"],
      ["../options/option.php?cat=2", "Ginásio"],
      ["../options/option.php?cat=3", "Entretenimento"],
      ["../options/option.php?cat=4", "Saúde"],
      ["../options/option.php?cat=5", "Educação"],
      ["../options/option.php?cat=6", "Outros"],
  ];
?>
<!DOCTYPE html>
<html lang="pt">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($nome_categoria) ?> — LifePlanner</title>
  <link rel="stylesheet" href="./stylesOption.css">
  <link rel="icon" type="image/x-icon" href="../../assets/favicon.ico">
</head>
<body>

<nav>
  <span class="nav-brand">LifePlanner</span>
  <ul class="nav-links">
    <?php foreach ($navLinks as [$href, $label]):
      $catNum = (int)filter_var($href, FILTER_SANITIZE_NUMBER_INT);
      $isActive = (strpos($href, 'cat=') !== false && $catNum === $category_id)
               || (strpos($href, 'dashboard') !== false && false);
    ?>
    <li><a href="<?= $href ?>" <?= $isActive ? 'class="active"' : '' ?>><?= $label ?></a></li>
    <?php endforeach; ?>
  </ul>
  <ul class="nav-right">
    <li><a href="../settings/settings.php">Definições</a></li>
    <li><a href="../../config/logout.php" class="btn-danger">Sair</a></li>
  </ul>
</nav>

<div class="page">

  <h1 class="page-title"><?= htmlspecialchars($nome_categoria) ?></h1>

  <!-- KPIs -->
  <div class="kpi-row">
    <div class="kpi-card red">
      <div class="kpi-label">Total Gasto</div>
      <div class="kpi-value"><?= number_format($totalCategoria, 2, ',', '.') ?> €</div>
    </div>
    <div class="kpi-card blue">
      <div class="kpi-label">Registos</div>
      <div class="kpi-value"><?= $numRegistos ?></div>
    </div>
  </div>

  <!-- Formulario -->
  <div class="card">
    <div class="card-title"><?= $edit_data ? 'Editar Despesa' : 'Nova Despesa' ?></div>
    <form action="option.php?cat=<?= $category_id ?>" method="post">
      <input type="hidden" name="category_id" value="<?= $category_id ?>">
      <input type="hidden" name="edit_id"     value="<?= $edit_data['id'] ?? '' ?>">

      <div class="form-grid">

        <!-- Col 1: Categoria + Valor -->
        <label for="category_sel">Categoria</label>
        <select name="category_id" id="category_sel" required>
          <option value="1" <?= ($category_id == 1) ? 'selected' : '' ?>>Carro</option>
          <option value="2" <?= ($category_id == 2) ? 'selected' : '' ?>>Ginásio</option>
          <option value="3" <?= ($category_id == 3) ? 'selected' : '' ?>>Entretenimento</option>
          <option value="4" <?= ($category_id == 4) ? 'selected' : '' ?>>Saúde</option>
          <option value="5" <?= ($category_id == 5) ? 'selected' : '' ?>>Educação</option>
          <option value="6" <?= ($category_id == 6) ? 'selected' : '' ?>>Outros</option>
        </select>

        <!-- Col 2: Valor + Data -->
        <label for="amount">Valor (€)</label>
        <input type="number" id="amount" name="amount" step="0.01" min="0"
               value="<?= htmlspecialchars($edit_data['amount'] ?? '') ?>" required>

        <label for="date">Data</label>
        <input type="date" id="date" name="date"
               value="<?= htmlspecialchars($edit_data['date'] ?? date('Y-m-d')) ?>" required>

        <!-- NIF na 2a coluna, mesma linha que Data -->
        <label>NIF</label>
        <div class="radio-group">
          <label>
            <input type="radio" name="nif" value="0"
              <?= (!$edit_data || (isset($edit_data['nif']) && $edit_data['nif'] == 0)) ? 'checked' : '' ?>>
            Nao
          </label>
          <label>
            <input type="radio" name="nif" value="1"
              <?= (isset($edit_data['nif']) && $edit_data['nif'] == 1) ? 'checked' : '' ?>>
            Sim
          </label>
        </div>

        <!-- Descrição — linha completa -->
        <div class="full-row">
          <label for="description">Descrição</label>
          <textarea id="description" name="description" required><?= htmlspecialchars($edit_data['description'] ?? '') ?></textarea>
        </div>

        <!-- Detalhes — linha completa -->
        <div class="full-row">
          <label for="detail">Detalhes</label>
          <textarea id="detail" name="detail"><?= htmlspecialchars($edit_data['detail'] ?? '') ?></textarea>
        </div>

        <!-- Ações -->
        <div class="form-actions-row">
          <div class="form-actions" style="padding-left: 120px;">
            <button type="submit" class="btn"><?= $edit_data ? 'Atualizar' : 'Adicionar' ?></button>
            <?php if ($edit_data): ?>
            <a href="option.php?cat=<?= $category_id ?>&year=<?= $year ?>" class="btn-ghost">Cancelar</a>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </form>
  </div>

  <!-- Tabela -->
  <div class="card">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
      <span>Historico de Despesas — <?= $year ?></span>
      <form method="get" action="" style="display:flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="cat" value="<?= $category_id ?>">
        <select name="year" onchange="this.form.submit()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:5px;font-family:var(--font);font-size:0.8rem;outline:none;cursor:pointer;">
          <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>
    <?php
    $stmt = $conn->prepare("SELECT id, amount, date, description, detail, nif FROM transactions WHERE category_id = ? AND user_id = ? AND YEAR(date) = ? ORDER BY date DESC");
    $stmt->bind_param("iii", $category_id, $user_id, $year);
    $stmt->execute();
    $result = $stmt->get_result();
    ?>
    <?php if ($result->num_rows === 0): ?>
      <p class="text-muted">Sem registos para <?= $year ?>.</p>
    <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th>Valor</th>
          <th>Data</th>
          <th>Descrição</th>
          <th>Detalhes</th>
          <th>NIF</th>
          <th>Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
          <td class="amount"><?= number_format($row['amount'], 2, ',', '.') ?> €</td>
          <td style="white-space:nowrap"><?= date('d/m/Y', strtotime($row['date'])) ?></td>
          <td>
            <?= htmlspecialchars($row['description']) ?>
            <?php if (!empty($row['detail'])): ?>
            <div class="detail-text"><?= htmlspecialchars($row['detail']) ?></div>
            <?php endif; ?>
          </td>
          <td>
            <?= htmlspecialchars($row['detail']) ?>
          </td>
          <td>
              <?= htmlspecialchars($row['nif']) == 1 ? 'Sim' : 'Nao' ?>
          </td>
          <td style="white-space:nowrap">
            <a class="action-link action-edit"
               href="option.php?cat=<?= $category_id ?>&edit_id=<?= $row['id'] ?>&year=<?= $year ?>">Editar
            </a>
            <a class="action-link action-delete"
               href="option.php?cat=<?= $category_id ?>&delete_id=<?= $row['id'] ?>&year=<?= $year ?>"
               onclick="return confirm('Tem a certeza que quer apagar este registo?')">Apagar
            </a>
          </td>
        </tr>
        <?php endwhile; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>

  <!-- Grafico mensal -->
  <div class="card">
    <div class="card-title" style="display:flex; justify-content:space-between; align-items:center;">
      <span>Gasto Mensal — <?= $year ?></span>
      <form method="get" action="" style="display:flex; gap:8px; align-items:center; margin:0;">
        <input type="hidden" name="cat" value="<?= $category_id ?>">
        <select name="year" onchange="this.form.submit()" style="background:var(--bg);border:1px solid var(--border);color:var(--text);padding:4px 8px;border-radius:5px;font-family:var(--font);font-size:0.8rem;outline:none;cursor:pointer;">
          <?php for ($y = (int)date('Y'); $y >= 2024; $y--): ?>
          <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
          <?php endfor; ?>
        </select>
      </form>
    </div>
    <div style="position:relative; height:220px;">
      <canvas id="monthlyChart"></canvas>
    </div>
  </div>

</div><!-- /page -->

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
const labels      = <?= $jsLabels ?>;
const totals      = <?= $jsTotals ?>;
const deltas      = <?= $jsDeltas ?>;
const barColors   = <?= $jsColors ?>;
const borderColors= <?= $jsBorderColors ?>;

Chart.defaults.color       = '#6b7280';
Chart.defaults.font.family = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif";
Chart.defaults.font.size   = 11;

new Chart(document.getElementById('monthlyChart'), {
  type: 'bar',
  data: {
    labels,
    datasets: [{
      label: 'Total Gasto',
      data: totals,
      backgroundColor: barColors,
      borderColor: borderColors,
      borderWidth: 1,
      borderRadius: 4,
    }]
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: ctx => {
            const val = ctx.parsed.y;
            const delta = deltas[ctx.dataIndex];
            const valStr = ' ' + val.toLocaleString('pt-PT', {minimumFractionDigits:2}) + ' €';
            if (delta === null) return valStr;
            const sign = delta > 0 ? '+' : '';
            return [valStr, ` vs mes anterior: ${sign}${delta}%`];
          }
        }
      }
    },
    scales: {
      x: {
        grid: { color: 'rgba(0,0,0,0.04)' },
        ticks: { color: '#6b7280', maxRotation: 45 }
      },
      y: {
        grid: { color: 'rgba(0,0,0,0.04)' },
        ticks: {
          color: '#6b7280',
          callback: v => '€' + v.toLocaleString('pt-PT')
        },
        beginAtZero: true
      }
    }
  },
  plugins: [{
    // Mostrar a % de variação em cima de cada barra
    id: 'deltaLabels',
    afterDatasetsDraw(chart) {
      const { ctx, data } = chart;
      const meta = chart.getDatasetMeta(0);
      meta.data.forEach((bar, i) => {
        const delta = deltas[i];
        if (delta === null) return;
        const sign  = delta > 0 ? '+' : '';
        const color = delta > 0 ? '#dc2626' : '#16a34a';
        const text  = `${sign}${delta}%`;
        ctx.save();
        ctx.font        = 'bold 10px ' + Chart.defaults.font.family;
        ctx.fillStyle   = color;
        ctx.textAlign   = 'center';
        ctx.textBaseline= 'bottom';
        ctx.fillText(text, bar.x, bar.y - 3);
        ctx.restore();
      });
    }
  }]
});
</script>