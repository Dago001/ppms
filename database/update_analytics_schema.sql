-- Create table for NIS formations
CREATE TABLE IF NOT EXISTS formations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    formation_type ENUM('HQ', 'Command', 'Control Post', 'Border Patrol', 'Other') NOT NULL,
    zone VARCHAR(50) NOT NULL,
    state VARCHAR(50) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for zones
CREATE TABLE IF NOT EXISTS zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    code VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Modify postings table to include more detailed information
ALTER TABLE postings
ADD COLUMN formation_id INT AFTER location,
ADD COLUMN posting_status ENUM('Active', 'Completed', 'Pending') DEFAULT 'Active',
ADD COLUMN created_by INT,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD FOREIGN KEY (formation_id) REFERENCES formations(id),
ADD FOREIGN KEY (created_by) REFERENCES users(id);


-- Create table for NIS locations
CREATE TABLE IF NOT EXISTS locations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    location_type ENUM('Service Headquarters', 'Zonal Command', 'State Command', 'Border Command', 'Training School', 'Special Command', 'Other') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert predefined locations
INSERT INTO location (name, location_type) VALUES
('Service Headquarters (SHQ)', 'Service Headquarters'),
('Zone \'A\' Lagos', 'Zonal Command'),
('Zone \'B\' Kaduna', 'Zonal Command'),
('Zone \'C\' Bauchi', 'Zonal Command'),
('Zone \'D\' Minna', 'Zonal Command'),
('Zone \'E\' Owerri', 'Zonal Command'),
('Zone \'F\' Ibadan', 'Zonal Command'),
('Zone \'G\' Benin City', 'Zonal Command'),
('Zone \'H\' Makurdi', 'Zonal Command'),
('FCT Command', 'State Command'),
('Abia State Command', 'State Command'),
('Adamawa State Command', 'State Command'),
('Akwa Ibom State Command', 'State Command'),
('Anambra State Command', 'State Command'),
('Bauchi State Command', 'State Command'),
('Bayelsa State Command', 'State Command'),
('Benue State Command', 'State Command'),
('Borno State Command', 'State Command'),
('Cross River State Command', 'State Command'),
('Delta State Command', 'State Command'),
('Ebonyi State Command', 'State Command'),
('Edo State Command', 'State Command'),
('Ekiti State Command', 'State Command'),
('Enugu State Command', 'State Command'),
('Gombe State Command', 'State Command'),
('Imo State Command', 'State Command'),
('Jigawa State Command', 'State Command'),
('Kaduna State Command', 'State Command'),
('Kano State Command', 'State Command'),
('Katsina State Command', 'State Command'),
('Kebbi State Command', 'State Command'),
('Kogi State Command', 'State Command'),
('Kwara State Command', 'State Command'),
('Lagos State Command', 'State Command'),
('Nasarawa State Command', 'State Command'),
('Niger State Command', 'State Command'),
('Ogun State Command', 'State Command'),
('Ondo State Command', 'State Command'),
('Osun State Command', 'State Command'),
('Oyo State Command', 'State Command'),
('Plateau State Command', 'State Command'),
('Rivers State Command', 'State Command'),
('Sokoto State Command', 'State Command'),
('Taraba State Command', 'State Command'),
('Yobe State Command', 'State Command'),
('Zamfara State Command', 'State Command'),
('Lagos Border Patrol Command', 'Border Command'),
('MMIA', 'Special Command'),
('Lagos Passport Command', 'Special Command'),
('Seme Border Command', 'Border Command'),
('Idiroko Border Command', 'Border Command'),
('Illela Border Command', 'Border Command'),
('Jibiya Border Command', 'Border Command'),
('Immigration Training School Kano', 'Training School'),
('Immigration Command and Staff College', 'Training School'),
('Nigeria Immigration Training School Ahoada', 'Training School'),
('Nigeria Immigration Training School Orlu', 'Training School'),
('Mfum Border Command', 'Border Command'),
('River Marine Command', 'Special Command');
('Other', 'Other'),

-- Modify postings table to include more detailed information
ALTER TABLE postings
ADD COLUMN formation_id INT AFTER location,
ADD COLUMN posting_status ENUM('Active', 'Completed', 'Pending') DEFAULT 'Active',
ADD COLUMN created_by INT,
ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
ADD FOREIGN KEY (formation_id) REFERENCES formations(id),
ADD FOREIGN KEY (created_by) REFERENCES users(id);