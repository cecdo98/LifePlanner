# LifePlanner

Aplicacao PHP/SQLite para gestao financeira pessoal.

## Funcionalidades

- Login com hash de password.
- Dashboard mensal/anual com graficos.
- Categorias e despesas.
- Pesquisa global de movimentos.
- Orcamentos mensais por categoria.
- Importacao/exportacao JSON, com filtros por ano, mes e categoria.

## Configuracao

1. Copia `config/local.example.php` para `config/local.php`.
2. Edita `db_path` com o caminho absoluto para a tua base SQLite.
3. Garante que a extensao PDO SQLite esta ativa no PHP.

Exemplo:

```php
<?php
return [
    'db_path' => 'C:/Users/Carlos/OneDrive/.../financas.sqlite',
];
```

## Base de dados

O ficheiro `config/schema.sql` contem o schema esperado. Ao arrancar, `config/migrations.php` cria as tabelas/indices em falta com `CREATE TABLE IF NOT EXISTS`.

## Estrutura

- `index.php`: login.
- `config/`: conexao, seguranca, schema e helpers.
- `main/dashboard/`: resumo financeiro.
- `main/options/`: despesas por categoria.
- `main/movements/`: pesquisa global de movimentos.
- `main/settings/`: conta, categorias, orcamentos e import/export.

## Notas

O projeto ainda usa categorias globais. Para um sistema multiutilizador completo, o proximo passo estrutural seria associar categorias a utilizadores.
