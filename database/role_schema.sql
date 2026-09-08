-- Add new columns to users table
ALTER TABLE users
ADD COLUMN role_id INT NOT NULL DEFAULT 1,
ADD COLUMN email VARCHAR(255),
ADD COLUMN full_name VARCHAR(255),
ADD COLUMN photo_path VARCHAR(255),
ADD COLUMN last_login DATETIME;

-- Create roles table
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

-- Create permissions table
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT
);

-- Create role_permissions table
CREATE TABLE role_permissions (
    role_id INT,
    permission_id INT,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
);

-- Insert default roles
INSERT INTO roles (name, description) VALUES
('user', 'Regular user with basic access'),
('admin', 'Administrator with enhanced privileges'),
('superadmin', 'Super Administrator with full system access');

-- Insert permissions
INSERT INTO permissions (name, description) VALUES
('add_report', 'Can add new reports'),
('view_report', 'Can view reports'),
('edit_report', 'Can edit reports'),
('delete_report', 'Can delete reports'),
('manage_users', 'Can manage users'),
('manage_roles', 'Can manage roles'),
('delete_users', 'Can delete users');

-- Assign permissions to roles
-- User role permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'user' AND p.name IN ('add_report');

-- Admin role permissions
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'admin' AND p.name IN (
    'add_report',
    'view_report',
    'edit_report',
    'manage_users'
);

-- Super Admin role permissions (all permissions)
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r, permissions p
WHERE r.name = 'superadmin';

-- Create the first superadmin user (password: admin123)
INSERT INTO users (username, password, email, full_name, role_id)
SELECT 'superadmin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@example.com', 'Super Administrator', r.id
FROM roles r WHERE r.name = 'superadmin';
