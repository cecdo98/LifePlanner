<?php
    session_start();

    include_once "../../config/bd.php";

    $year = isset($_GET['year']) ? $_GET['year'] : date('Y');
    $nif = isset($_GET['nif']) ? $_GET['nif'] : '1';

    $stmt = $conn->prepare("
        SELECT 
            ? AS ano,
            c.name AS categoria,
            COALESCE(SUM(t.amount), 0) AS total
        FROM categories c
        LEFT JOIN transactions t 
            ON t.category_id = c.id
            AND YEAR(t.date) = ?
            AND t.nif = ?
        GROUP BY c.id, c.name
        ORDER BY c.name;
    ");

    $stmt->bind_param("iii", $year, $year, $nif);

    $stmt->execute();
    $result = $stmt->get_result();

?>

<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="../../styles.css">
</head>
<body>
    <ul>
        <li><a href="../dashboard/dashboard.php">Home</a></li>
        <li><a href="../options/option.php?cat=1">Carro</a></li>
        <li><a href="../options/option.php?cat=2">Ginásio</a></li>
        <li><a href="../options/option.php?cat=3">Entretenimento</a></li>
        <li><a href="../options/option.php?cat=4">Saúde</a></li>
        <li><a href="../options/option.php?cat=5">Educação</a></li>
        <li><a href="../options/option.php?cat=6">Outros</a></li>
    </ul>

    <h1>Dashboard</h1>

    <form action="" method="get">
        <table>
            <tr>
                <td><label for="year">Ano:</label></td>
                <td>
                    <select name="year" id="year">
                        <?php for ($i = date('Y'); $i <= 2080; $i++): ?>
                            <option value="<?php echo $i; ?>" <?php if ($i == $year) echo 'selected'; ?>><?php echo $i; ?></option>
                        <?php endfor; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <td><label for="nif">NIF:</label></td>
                <td>
                    <input type="radio" name="nif" value="0" <?php if ($nif == '0') echo 'checked'; ?>> Não
                    <input type="radio" name="nif" value="1" <?php if ($nif == '1') echo 'checked'; ?>> Sim
                </td>
            </tr>
        </table>
        <input type="submit" value="Submit">
    </form>


    <table style="width:30%">
        <tr>
            <th>Ano</th>
            <th>Categoria</th>
            <th>Valor</th>
        </tr>
        <?php while($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['ano']; ?></td>
            <td><?php echo $row['categoria']; ?></td>
            <td><?php echo $row['total']." €"; ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</body>
</html>