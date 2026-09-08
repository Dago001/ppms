-- Add roles table
CREATE TABLE roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Add default roles
INSERT INTO roles (name, description) VALUES
('superadmin', 'Full system access with user management capabilities'),
('admin', 'Administrative access without user management'),
('user', 'Basic system access for data entry and viewing');

-- Modify users table to add more fields
ALTER TABLE users 
ADD COLUMN role_id INT,
ADD COLUMN full_name VARCHAR(100),
ADD COLUMN email VARCHAR(100),
ADD COLUMN photo_path VARCHAR(255),
ADD COLUMN is_active BOOLEAN DEFAULT TRUE,
ADD COLUMN last_login DATETIME,
ADD FOREIGN KEY (role_id) REFERENCES roles(id);

-- Create user permissions table
CREATE TABLE permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Create role_permissions table
CREATE TABLE role_permissions (
    role_id INT,
    permission_id INT,
    PRIMARY KEY (role_id, permission_id),
    FOREIGN KEY (role_id) REFERENCES roles(id),
    FOREIGN KEY (permission_id) REFERENCES permissions(id)
);

-- Add basic permissions
INSERT INTO permissions (name, description) VALUES
('manage_users', 'Can create, edit, and delete users'),
('manage_roles', 'Can manage user roles'),
('view_records', 'Can view personnel records'),
('add_records', 'Can add new personnel records'),
('edit_records', 'Can edit personnel records'),
('delete_records', 'Can delete personnel records');

-- Assign permissions to roles
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'superadmin';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'admin'
AND p.name != 'manage_users' AND p.name != 'manage_roles';

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles r
CROSS JOIN permissions p
WHERE r.name = 'user'
AND p.name IN ('view_records', 'add_records');

-- Create user activity log
CREATE TABLE activity_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    action VARCHAR(100),
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);
