<?php
    function run_migrations($conn) {
        $schemaFile = __DIR__ . "/schema.sql";
        if (!file_exists($schemaFile)) {
            return;
        }

        $schema = file_get_contents($schemaFile);
        $statements = array_filter(array_map('trim', explode(';', $schema)));

        foreach ($statements as $sql) {
            try {
                $stmt = $conn->prepare($sql);
                if ($stmt) {
                    $stmt->execute();
                }
            } catch (Exception $e) {
                // ignore: statement may already be applied (e.g. ALTER TABLE on existing column)
            }
        }
    }
?>
