CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS categories (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE
);

CREATE TABLE IF NOT EXISTS transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    category_id INTEGER,
    amount REAL NOT NULL,
    date TEXT NOT NULL,
    description TEXT NOT NULL,
    detail TEXT DEFAULT '',
    nif INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS monthly_summary (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    total_spent REAL NOT NULL DEFAULT 0,
    salary REAL NOT NULL DEFAULT 0,
    final_balance REAL NOT NULL DEFAULT 0,
    UNIQUE(user_id, year, month)
);

CREATE TABLE IF NOT EXISTS category_budgets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    category_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    budget REAL NOT NULL DEFAULT 0,
    UNIQUE(user_id, category_id, year, month)
);

CREATE TABLE IF NOT EXISTS savings_goals (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    target_amount REAL NOT NULL DEFAULT 0,
    current_amount REAL NOT NULL DEFAULT 0,
    deadline TEXT DEFAULT NULL,
    description TEXT DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (date('now'))
);

CREATE TABLE IF NOT EXISTS recurring_expenses (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    category_id INTEGER,
    amount REAL NOT NULL,
    description TEXT NOT NULL,
    detail TEXT DEFAULT '',
    nif INTEGER NOT NULL DEFAULT 0,
    day_of_month INTEGER NOT NULL DEFAULT 1,
    active INTEGER NOT NULL DEFAULT 1
);

CREATE TABLE IF NOT EXISTS recurring_applied (
    recurring_id INTEGER NOT NULL,
    user_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    PRIMARY KEY (recurring_id, user_id, year, month)
);

CREATE TABLE IF NOT EXISTS tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    UNIQUE(user_id, name)
);

CREATE TABLE IF NOT EXISTS transaction_tags (
    transaction_id INTEGER NOT NULL,
    tag_id INTEGER NOT NULL,
    PRIMARY KEY (transaction_id, tag_id)
);

CREATE INDEX IF NOT EXISTS idx_transactions_user_date ON transactions(user_id, date);
CREATE INDEX IF NOT EXISTS idx_transactions_category_user ON transactions(category_id, user_id);
CREATE INDEX IF NOT EXISTS idx_budgets_user_month ON category_budgets(user_id, year, month);
CREATE INDEX IF NOT EXISTS idx_goals_user ON savings_goals(user_id);
CREATE INDEX IF NOT EXISTS idx_recurring_user ON recurring_expenses(user_id);
CREATE INDEX IF NOT EXISTS idx_tags_user ON tags(user_id);

CREATE TABLE IF NOT EXISTS income_entries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    amount REAL NOT NULL,
    description TEXT NOT NULL,
    date TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS recurring_auto_applied (
    user_id INTEGER NOT NULL,
    year INTEGER NOT NULL,
    month INTEGER NOT NULL,
    PRIMARY KEY (user_id, year, month)
);

CREATE INDEX IF NOT EXISTS idx_income_user_month ON income_entries(user_id, year, month);
