CREATE DATABASE personnel_db;
USE personnel_db;

CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS personnel (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_number VARCHAR(5) UNIQUE NOT NULL,
    surname VARCHAR(50) NOT NULL,
    firstname VARCHAR(50) NOT NULL,
    middlename VARCHAR(50),
    date_of_birth DATE NOT NULL,
    gender ENUM('MALE', 'FEMALE') NOT NULL,
    rank VARCHAR(10) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS postings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_number VARCHAR(5) NOT NULL,
    posting_type ENUM('Previous', 'Current') NOT NULL,
    formation VARCHAR(100) NOT NULL,
    date_of_posting DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_number) REFERENCES personnel(service_number)
);

INSERT INTO users (username, password) VALUES ('admin', '12345');