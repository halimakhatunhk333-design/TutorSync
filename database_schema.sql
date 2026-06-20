CREATE DATABASE tutorsync;
USE tutorsync;

CREATE TABLE students (
    student_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE tutors (
    tutor_id INT AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    subject VARCHAR(100) NOT NULL,
    experience_years INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE sessions (
    session_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    tutor_id INT NOT NULL,
    session_date DATE NOT NULL,
    session_time TIME NOT NULL,
    booking_status VARCHAR(20) DEFAULT 'Booked',
    FOREIGN KEY (student_id) REFERENCES students(student_id),
    FOREIGN KEY (tutor_id) REFERENCES tutors(tutor_id)
);

CREATE TABLE attendance (
    attendance_id INT AUTO_INCREMENT PRIMARY KEY,
    session_id INT NOT NULL,
    attendance_status VARCHAR(20) NOT NULL,
    FOREIGN KEY (session_id) REFERENCES sessions(session_id)
);

CREATE TABLE progress_reports (
    report_id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    remarks TEXT,
    progress_date DATE,
    FOREIGN KEY (student_id) REFERENCES students(student_id)
);
