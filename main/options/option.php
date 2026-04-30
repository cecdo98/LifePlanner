<?php
    session_start();
    include_once "../../config/bd.php";

    $category_id = isset($_GET['cat']) ? intval($_GET['cat']) : 1; 
    $user_id = 1; 

    // --- LÓGICA DE APAGAR ---
    if (isset($_GET['delete_id'])) {
        $id_to_delete = intval($_GET['delete_id']);
        $stmt = $conn->prepare("DELETE FROM transactions WHERE id = ? AND user_id = ?");
        $stmt->bind_param("ii", $id_to_delete, $user_id);
        $stmt->execute();
        header("Location: option.php?cat=" . $category_id);
        exit();
    }

    // --- LÓGICA DE EDITAR / INSERIR ---
    if ($_SERVER["REQUEST_METHOD"] == "POST") {
        $amount = $_POST["amount"];
        $date = $_POST["date"];
        $description = $_POST["description"];
        $nif = $_POST["nif"];
        $cat_to_save = $_POST["category_id"];
        
        if (isset($_POST['edit_id']) && !empty($_POST['edit_id'])) {
            // Update
            $edit_id = intval($_POST['edit_id']);
            $stmt = $conn->prepare("UPDATE transactions SET amount=?, date=?, description=?, nif=? WHERE id=? AND user_id=?");
            $stmt->bind_param("sssiii", $amount, $date, $description, $nif, $edit_id, $user_id);
        } else {
            // Insert
            $stmt = $conn->prepare("INSERT INTO transactions (user_id, category_id, amount, date, description, nif) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param("iisssi", $user_id, $cat_to_save, $amount, $date, $description, $nif);
        }

        $stmt->execute();
        header("Location: option.php?cat=" . $cat_to_save);
        exit();
    }

    // --- BUSCAR DADOS PARA O FORMULÁRIO SE ESTIVER A EDITAR ---
    $edit_data = null;
    if (isset($_GET['edit_id'])) {
        $id_to_fetch = intval($_GET['edit_id']);
        $stmt_edit = $conn->prepare("SELECT * FROM transactions WHERE id = ? AND user_id = ?");
        $stmt_edit->bind_param("ii", $id_to_fetch, $user_id);
        $stmt_edit->execute();
        $edit_data = $stmt_edit->get_result()->fetch_assoc();
    }

    // Nome da categoria
    $stmt_cat = $conn->prepare("SELECT name FROM categories WHERE id = ?");
    $stmt_cat->bind_param("i", $category_id);
    $stmt_cat->execute();
    $nome_categoria = $stmt_cat->get_result()->fetch_assoc()['name'] ?? "Desconhecida";
?>

<html>
<head>
    <title>Life Planner - <?php echo $nome_categoria; ?></title>
    <link rel="stylesheet" href="../../styles.css">
</head>
<body>
    <ul>
        <li><a href="../dashboard/dashboard.php">Home</a></li>
        <li><a href="option.php?cat=1">Carro</a></li>
        <li><a href="option.php?cat=2">Ginásio</a></li>
        <li><a href="option.php?cat=3">Entretenimento</a></li>
        <li><a href="option.php?cat=4">Saúde</a></li>
        <li><a href="option.php?cat=5">Educação</a></li>
        <li><a href="option.php?cat=6">Outros</a></li>
    </ul>

    <h1><?php echo $nome_categoria; ?></h1>

    <h2><?php echo $edit_data ? "Editar Despesa" : "Nova Despesa"; ?></h2>
    <form action="option.php?cat=<?php echo $category_id; ?>" method="post">
        <input type="hidden" name="category_id" value="<?php echo $category_id; ?>">

        <input type="hidden" name="edit_id" value="<?php echo $edit_data['id'] ?? ''; ?>">
        <table> 
            <tr>
                <td><label for="amount">Valor:</label></td>
                <td><input type="number" name="amount" step="0.01" value="<?php echo $edit_data['amount'] ?? ''; ?>" required></td>
            </tr>
            <tr>
                <td><label for="date">Data:</label></td>
                <td><input type="date" name="date" value="<?php echo $edit_data['date'] ?? ''; ?>" required></td>
            </tr>
            <tr>
                <td><label for="description">Descrição:</label></td>
                <td><textarea name="description" required><?php echo $edit_data['description'] ?? ''; ?></textarea></td>
            </tr>
            <tr>
                <td><label for="nif">NIF:</label></td>
                <td>
                    <input type="radio" name="nif" value="0" <?php echo (!$edit_data || (isset($edit_data['nif']) && $edit_data['nif'] == 0)) ? 'checked' : ''; ?>> Não
                    <input type="radio" name="nif" value="1" <?php echo (isset($edit_data['nif']) && $edit_data['nif'] == 1) ? 'checked' : ''; ?>> Sim
                </td>
            </tr>
            <tr>
                <td colspan="2">
                    <input type="submit" value="<?php echo $edit_data ? 'Atualizar' : 'Adicionar'; ?>">
                    <?php if($edit_data): ?> <a href="option.php?cat=<?php echo $category_id; ?>">Cancelar</a> <?php endif; ?> 
                </td>
            </tr>
        </table>
    </form>



    <hr>

    <table border="1" style="width:50%">
        <tr>
            <th>Valor</th>
            <th>Data</th>
            <th>Descrição</th>
            <th>NIF</th>
            <th>Ações</th>
        </tr>
        <?php
            // IMPORTANTE: Incluí o 'id' no SELECT para podermos apagar/editar
            $stmt = $conn->prepare("SELECT id, amount, date, description, nif FROM transactions WHERE category_id = ? AND user_id = ? ORDER BY date DESC");
            $stmt->bind_param("ii", $category_id, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo number_format($row["amount"], 2); ?> €</td>
                    <td><?php echo date('d/m/Y', strtotime($row["date"])); ?></td>
                    <td><?php echo htmlspecialchars($row["description"]); ?></td>
                    <td><?php echo $row["nif"] ? "Sim" : "Não"; ?></td>
                    <td>
                        <a class="edit" href="option.php?cat=<?php echo $category_id; ?>&edit_id=<?php echo $row['id']; ?>">Editar</a> | 
                        <a class="delete" href="option.php?cat=<?php echo $category_id; ?>&delete_id=<?php echo $row['id']; ?>" 
                           onclick="return confirm('Tem a certeza?')">Apagar</a>
                    </td>
                </tr>
            <?php endwhile; ?>
    </table>
</body>
</html>