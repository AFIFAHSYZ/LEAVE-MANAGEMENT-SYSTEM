-- schema.sql

CREATE TABLE users (
    id SERIAL PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) UNIQUE NOT NULL,
    password TEXT NOT NULL,
    position VARCHAR(20) DEFAULT 'employee',
);

CREATE TABLE leave_types (
    id SERIAL PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    annual_limit INT DEFAULT 0
);

CREATE TABLE leave_requests (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    leave_type_id INT REFERENCES leave_types(id) ON DELETE SET NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT,
    status VARCHAR(20) DEFAULT 'pending',
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INT REFERENCES users(id),
    decision_date TIMESTAMP
);

CREATE TABLE leave_balances (
    id SERIAL PRIMARY KEY,
    user_id INT REFERENCES users(id) ON DELETE CASCADE,
    leave_type_id INT REFERENCES leave_types(id),
    year INT DEFAULT EXTRACT(YEAR FROM CURRENT_DATE),
    used_days INT DEFAULT 0
);
