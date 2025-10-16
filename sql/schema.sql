-- ========================
-- Users Table
-- ========================
CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password TEXT NOT NULL,
    position VARCHAR(20) DEFAULT 'employee', -- employee, manager, HR
    date_joined DATE NOT NULL DEFAULT CURRENT_DATE
);

-- ========================
-- Leave Types Table
-- ========================
CREATE TABLE leave_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,          -- e.g., Annual Leave, Sick Leave
    default_limit INT DEFAULT 0          -- default days per year
);

-- ========================
-- Leave Tenure Policy Table
-- ========================
CREATE TABLE leave_tenure_policy (
    id SERIAL PRIMARY KEY,
    leave_type_id INT REFERENCES leave_types(id),
    min_years INT NOT NULL,
    max_years INT,                       -- NULL = no upper limit
    days_per_year INT NOT NULL           -- days allocated per year
);

-- ========================
-- Leave Requests Table
-- ========================
CREATE TABLE leave_requests (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    leave_type_id INT REFERENCES leave_types(id) ON DELETE SET NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status VARCHAR(20) DEFAULT 'pending', -- pending, approved, rejected
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INT REFERENCES users(id),  -- manager or HR who approves
    decision_date TIMESTAMP
);

-- ========================
-- Leave Balances Table
-- ========================
CREATE TABLE leave_balances (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    leave_type_id INT REFERENCES leave_types(id),
    year INT DEFAULT EXTRACT(YEAR FROM CURRENT_DATE),
    used_days INT DEFAULT 0,
    carry_forward INT DEFAULT 0,
    total_available INT GENERATED ALWAYS AS (carry_forward + entitled_days - used_days) STORED
);

