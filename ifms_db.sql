-- Create database if it doesn't exist
CREATE DATABASE IF NOT EXISTS ifms_db;
USE ifms_db;

-- Equipment Table (This table is for the actual equipment listings)
CREATE TABLE IF NOT EXISTS equipment (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    serial_number VARCHAR(100) UNIQUE NOT NULL,
    purchase_date DATE NOT NULL,
    purchase_cost DECIMAL(10, 2) NOT NULL,
    lifespan_years INT NOT NULL,
    salvage_value DECIMAL(10, 2) NOT NULL,
    total_operating_hours DECIMAL(10, 2) DEFAULT 0.00,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Equipment Allocations Table (Tracking Tasks and Operating Hours)
CREATE TABLE IF NOT EXISTS equipment_allocations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    task_name VARCHAR(150) NOT NULL,
    operator_name VARCHAR(100) NOT NULL,
    hours_logged DECIMAL(6, 2) NOT NULL,
    allocation_date DATE NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);

-- Maintenance Logs Table (Routine Services, Repairs and Reminders)
CREATE TABLE IF NOT EXISTS maintenance_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    service_type ENUM('Routine', 'Repair') NOT NULL,
    description TEXT NOT NULL,
    cost DECIMAL(10, 2) NOT NULL,
    service_date DATE NOT NULL,
    next_due_hours DECIMAL(10, 2) DEFAULT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);

-- Fuel Logs Table (Fuel Consumption Tracking)
CREATE TABLE IF NOT EXISTS fuel_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    equipment_id INT NOT NULL,
    litres_consumed DECIMAL(8, 2) NOT NULL,
    total_cost DECIMAL(10, 2) NOT NULL,
    log_date DATE NOT NULL,
    FOREIGN KEY (equipment_id) REFERENCES equipment(id) ON DELETE CASCADE
);