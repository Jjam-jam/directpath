-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Apr 06, 2026 at 04:20 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `directpath_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `announcements`
--

CREATE TABLE `announcements` (
  `id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `priority` enum('normal','important','urgent') DEFAULT 'normal',
  `posted_by` varchar(150) NOT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `date_posted` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `recipient_employee_id` varchar(20) DEFAULT NULL,
  `recipient_role` varchar(30) DEFAULT NULL,
  `message` varchar(255) NOT NULL,
  `target_url` varchar(500) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_notification_employee` (`recipient_employee_id`,`read_at`,`created_at`),
  KEY `idx_notification_role` (`recipient_role`,`read_at`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `announcement_reads` (
  `announcement_id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `read_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`announcement_id`,`employee_id`),
  CONSTRAINT `fk_announcement_reads_announcement` FOREIGN KEY (`announcement_id`) REFERENCES `announcements` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `announcements`
--

INSERT INTO `announcements` (`id`, `title`, `message`, `priority`, `posted_by`, `is_active`, `date_posted`, `updated_at`) VALUES
(13, 'wala', 'wala', 'important', 'Jose Reyes', 1, '2026-03-29 12:30:58', '2026-03-29 12:30:58'),
(14, 'Company Holiday Notice', 'The office will be closed on December 25-26 for Christmas. Operations resume December 27.', 'important', 'HR Department', 1, '2026-03-30 05:43:02', '2026-03-30 05:43:02'),
(15, 'Payroll Cutoff Reminder', 'Payroll cutoff for Jan 1-15 is on January 15. Submit all OT and leave applications before cutoff.', 'urgent', 'HR Department', 1, '2026-03-30 05:43:02', '2026-03-30 05:43:02');

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `late_minutes` int(11) DEFAULT 0,
  `undertime_mins` int(11) DEFAULT 0,
  `hours_worked` decimal(5,2) DEFAULT 0.00,
  `status` enum('present','absent','half-day','on-leave') DEFAULT 'present',
  `captured_photo` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`id`, `employee_id`, `date`, `time_in`, `time_out`, `late_minutes`, `undertime_mins`, `hours_worked`, `status`, `captured_photo`, `notes`, `created_at`, `updated_at`) VALUES
(31, 'EMP-0001', '2026-04-06', '15:33:47', '15:34:02', 453, 540, 0.00, 'present', 'att_EMP-0001_1775482427.jpg', NULL, '2026-04-06 13:33:47', '2026-04-06 13:34:02');

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `user_id` varchar(20) NOT NULL,
  `user_name` varchar(150) NOT NULL,
  `action` varchar(255) NOT NULL,
  `module` varchar(100) NOT NULL,
  `details` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- --------------------------------------------------------

--
-- Table structure for table `bug_tickets`
--

CREATE TABLE `bug_tickets` (
  `id` int(11) NOT NULL,
  `reported_by_id` varchar(20) NOT NULL,
  `reported_by_name` varchar(150) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text NOT NULL,
  `priority` enum('low','medium','high','critical') DEFAULT 'medium',
  `status` enum('open','in-progress','resolved','closed') DEFAULT 'open',
  `resolution_notes` text DEFAULT NULL,
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `date_resolved` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `certificates`
--

CREATE TABLE `certificates` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `purpose` text NOT NULL,
  `requested_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `issued_by` varchar(150) DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `document_requests`
--

CREATE TABLE `document_requests` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `doc_type` enum('payslip','certificate') NOT NULL,
  `payroll_id` int(11) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` varchar(150) DEFAULT NULL,
  `review_date` timestamp NULL DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `department` varchar(100) NOT NULL,
  `position` varchar(100) NOT NULL,
  `date_hired` date NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `role` enum('employee','admin','superadmin') DEFAULT 'employee',
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `reference_photo` varchar(255) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `civil_status` enum('single','married','widowed','separated') DEFAULT 'single',
  `sss_no` varchar(30) DEFAULT NULL,
  `philhealth_no` varchar(30) DEFAULT NULL,
  `pagibig_no` varchar(30) DEFAULT NULL,
  `tin_no` varchar(30) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `employee_id`, `name`, `email`, `password`, `department`, `position`, `date_hired`, `status`, `role`, `basic_salary`, `allowances`, `reference_photo`, `phone`, `address`, `civil_status`, `sss_no`, `philhealth_no`, `pagibig_no`, `tin_no`, `created_at`, `updated_at`) VALUES
(33, 'SA-0001', 'superadmin', 'superadmin@gmail.com', 'password', 'Administration', 'Super Admin', '2026-04-06', 'active', 'superadmin', 80000.00, 5000.00, NULL, NULL, NULL, 'single', NULL, NULL, NULL, NULL, '2026-04-06 13:13:17', '2026-04-06 13:13:17'),
(34, 'AD-0001', 'admin', 'admin@gmail.com', 'password', 'Human Resources', 'HR Manager', '2026-04-06', 'active', 'admin', 55000.00, 3000.00, NULL, NULL, NULL, 'single', NULL, NULL, NULL, NULL, '2026-04-06 13:13:17', '2026-04-06 13:13:17'),
(35, 'EMP-0001', 'employee', 'msatiozon.2@gmail.com', 'password', 'Department', 'Position', '2026-04-06', 'active', 'employee', 45000.00, 3000.00, NULL, NULL, NULL, 'single', NULL, NULL, NULL, NULL, '2026-04-06 13:13:17', '2026-04-06 13:24:19');

-- --------------------------------------------------------

--
-- Table structure for table `employee_requests`
--

CREATE TABLE `employee_requests` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `request_type` enum('payslip','coe') NOT NULL,
  `payroll_id` int(11) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `admin_notes` text DEFAULT NULL,
  `reviewed_by` varchar(150) DEFAULT NULL,
  `review_date` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `coe_sent` tinyint(1) DEFAULT 0,
  `coe_file` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `leave_type` enum('vacation','sick','emergency','maternity','paternity','others') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `total_days` int(11) DEFAULT 1,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewed_by` varchar(150) DEFAULT NULL,
  `review_date` timestamp NULL DEFAULT NULL,
  `review_remarks` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `id` int(11) NOT NULL,
  `sender_id` varchar(20) NOT NULL,
  `receiver_id` varchar(20) NOT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `id` int(11) NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `payroll_period_start` date NOT NULL,
  `payroll_period_end` date NOT NULL,
  `basic_salary` decimal(12,2) DEFAULT 0.00,
  `allowances` decimal(12,2) DEFAULT 0.00,
  `overtime_pay` decimal(12,2) DEFAULT 0.00,
  `bonuses` decimal(12,2) DEFAULT 0.00,
  `gross_pay` decimal(12,2) DEFAULT 0.00,
  `sss` decimal(12,2) DEFAULT 0.00,
  `philhealth` decimal(12,2) DEFAULT 0.00,
  `pagibig` decimal(12,2) DEFAULT 0.00,
  `sss_employer` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss_ec_employer` decimal(12,2) NOT NULL DEFAULT 0.00,
  `philhealth_employer` decimal(12,2) NOT NULL DEFAULT 0.00,
  `pagibig_employer` decimal(12,2) NOT NULL DEFAULT 0.00,
  `government_contributions_snapshot` longtext DEFAULT NULL,
  `withholding_tax` decimal(12,2) DEFAULT 0.00,
  `other_deductions` decimal(12,2) DEFAULT 0.00,
  `total_deductions` decimal(12,2) DEFAULT 0.00,
  `net_salary` decimal(12,2) DEFAULT 0.00,
  `days_worked` int(11) DEFAULT 0,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `late_deduction` decimal(12,2) DEFAULT 0.00,
  `undertime_deduction` decimal(12,2) DEFAULT 0.00,
  `status` enum('draft','generated','released') DEFAULT 'draft',
  `generated_by` varchar(150) DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payroll_previous_employer_ytd` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `employee_id` varchar(20) NOT NULL,
  `tax_year` smallint(5) unsigned NOT NULL,
  `taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00,
  `non_taxable_income` decimal(12,2) NOT NULL DEFAULT 0.00,
  `sss` decimal(12,2) NOT NULL DEFAULT 0.00,
  `philhealth` decimal(12,2) NOT NULL DEFAULT 0.00,
  `hdmf` decimal(12,2) NOT NULL DEFAULT 0.00,
  `withholding_tax` decimal(12,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_employee_tax_year` (`employee_id`,`tax_year`),
  KEY `idx_previous_employer_ytd_employee` (`employee_id`),
  CONSTRAINT `fk_previous_employer_ytd_employee` FOREIGN KEY (`employee_id`) REFERENCES `users` (`employee_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE `payroll_change_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `change_type` enum('compensation','ytd') NOT NULL,
  `employee_id` varchar(20) NOT NULL,
  `reference_year` smallint(5) unsigned DEFAULT NULL,
  `payload` longtext NOT NULL,
  `status` enum('pending_ceo','approved_pending_release','rejected','released') NOT NULL DEFAULT 'pending_ceo',
  `submitted_by` varchar(20) DEFAULT NULL,
  `submitted_by_name` varchar(150) NOT NULL,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_by` varchar(20) DEFAULT NULL,
  `reviewed_by_name` varchar(150) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reject_reason` text DEFAULT NULL,
  `released_by` varchar(20) DEFAULT NULL,
  `released_by_name` varchar(150) DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_payroll_change_status` (`status`,`submitted_at`),
  KEY `idx_payroll_change_employee` (`employee_id`,`change_type`,`reference_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `announcements`
--
ALTER TABLE `announcements`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_attendance` (`employee_id`,`date`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `bug_tickets`
--
ALTER TABLE `bug_tickets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_bug_employee` (`reported_by_id`);

--
-- Indexes for table `certificates`
--
ALTER TABLE `certificates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `employee_id` (`employee_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- Indexes for table `employee_requests`
--
ALTER TABLE `employee_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_employee` (`employee_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_payroll` (`employee_id`,`payroll_period_start`,`payroll_period_end`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `announcements`
--
ALTER TABLE `announcements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `bug_tickets`
--
ALTER TABLE `bug_tickets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `certificates`
--
ALTER TABLE `certificates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `document_requests`
--
ALTER TABLE `document_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `employee_requests`
--
ALTER TABLE `employee_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `bug_tickets`
--
ALTER TABLE `bug_tickets`
  ADD CONSTRAINT `fk_bug_employee` FOREIGN KEY (`reported_by_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `certificates`
--
ALTER TABLE `certificates`
  ADD CONSTRAINT `fk_cert_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `document_requests`
--
ALTER TABLE `document_requests`
  ADD CONSTRAINT `document_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `employee_requests`
--
ALTER TABLE `employee_requests`
  ADD CONSTRAINT `employee_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `fk_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `fk_payroll_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
