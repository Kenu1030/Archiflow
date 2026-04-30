-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 21, 2025 at 06:18 AM
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
-- Database: `archiflow_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `attendance`
--

CREATE TABLE `attendance` (
  `attendance_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `work_date` date NOT NULL,
  `time_in` time DEFAULT NULL,
  `time_out` time DEFAULT NULL,
  `hours_worked` decimal(4,2) DEFAULT 0.00,
  `overtime_hours` decimal(4,2) DEFAULT 0.00,
  `status` enum('present','absent','late') DEFAULT 'present',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance`
--

INSERT INTO `attendance` (`attendance_id`, `employee_id`, `work_date`, `time_in`, `time_out`, `hours_worked`, `overtime_hours`, `status`, `created_at`) VALUES
(1, 3, '2025-09-20', '08:19:00', '20:19:00', NULL, NULL, 'present', '2025-09-20 12:20:04'),
(2, 1, '2025-10-17', '18:55:00', '23:55:00', 12.00, 12.00, 'present', '2025-10-17 09:55:27'),
(3, 2, '2025-10-22', '07:29:17', '07:34:04', 0.08, 0.00, 'present', '2025-10-22 05:29:17'),
(4, 1, '2025-10-22', '11:44:49', '11:44:51', 0.00, 0.00, 'late', '2025-10-22 09:44:49'),
(5, 1, '2025-10-27', '06:42:58', '16:43:17', 10.01, 2.01, 'present', '2025-10-27 05:42:58'),
(6, 1, '2025-10-28', '07:33:23', NULL, 0.00, 0.00, 'present', '2025-10-28 06:33:23'),
(7, 3, '2025-11-11', '11:15:58', '11:16:06', 0.00, 0.00, 'late', '2025-11-11 10:15:58'),
(8, 1, '2025-11-11', '11:16:49', '11:22:15', 0.09, 0.00, 'late', '2025-11-11 10:16:49'),
(9, 4, '2025-11-11', '18:35:37', '18:35:42', 0.00, 0.00, 'late', '2025-11-11 10:35:37');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_corrections`
--

CREATE TABLE `attendance_corrections` (
  `id` int(11) NOT NULL,
  `employee_id` int(11) NOT NULL,
  `work_date` date NOT NULL,
  `new_time_in` time DEFAULT NULL,
  `new_time_out` time DEFAULT NULL,
  `reason` text NOT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `reviewer_id` int(11) DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_corrections`
--

INSERT INTO `attendance_corrections` (`id`, `employee_id`, `work_date`, `new_time_in`, `new_time_out`, `reason`, `status`, `reviewer_id`, `reviewed_at`, `created_at`) VALUES
(1, 4, '2025-11-11', NULL, NULL, 'sgsdgf', 'pending', NULL, NULL, '2025-11-11 10:48:47');

-- --------------------------------------------------------

--
-- Table structure for table `attendance_logs`
--

CREATE TABLE `attendance_logs` (
  `log_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `attendance_id` int(10) UNSIGNED DEFAULT NULL,
  `work_date` date NOT NULL,
  `action` enum('clock_in','clock_out') NOT NULL,
  `logged_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `latitude` decimal(9,6) DEFAULT NULL,
  `longitude` decimal(9,6) DEFAULT NULL,
  `network_allowed` tinyint(1) DEFAULT NULL,
  `geofence_ok` tinyint(1) DEFAULT NULL,
  `client_time_utc` datetime DEFAULT NULL,
  `client_tz_offset_min` int(11) DEFAULT NULL,
  `time_skew_min` int(11) DEFAULT NULL,
  `clock_skew_ok` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `attendance_logs`
--

INSERT INTO `attendance_logs` (`log_id`, `employee_id`, `attendance_id`, `work_date`, `action`, `logged_at`, `ip_address`, `user_agent`, `latitude`, `longitude`, `network_allowed`, `geofence_ok`, `client_time_utc`, `client_tz_offset_min`, `time_skew_min`, `clock_skew_ok`) VALUES
(1, 2, 3, '2025-10-22', 'clock_out', '2025-10-22 05:34:04', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(2, 1, 4, '2025-10-22', 'clock_in', '2025-10-22 09:44:49', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 10.312090, 123.908915, NULL, NULL, NULL, NULL, NULL, NULL),
(3, 1, 4, '2025-10-22', 'clock_out', '2025-10-22 09:44:51', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 10.312090, 123.908915, NULL, NULL, NULL, NULL, NULL, NULL),
(4, 1, 5, '2025-10-27', 'clock_in', '2025-10-27 05:42:58', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 10.315366, 123.908915, NULL, NULL, NULL, NULL, NULL, NULL),
(5, 1, 5, '2025-10-27', 'clock_out', '2025-10-27 15:43:17', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 10.315366, 123.908915, NULL, NULL, NULL, NULL, NULL, NULL),
(6, 1, 6, '2025-10-28', 'clock_in', '2025-10-28 06:33:23', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36', 10.312090, 123.895808, NULL, NULL, '2025-10-28 06:33:23', -480, 0, 1),
(7, 3, NULL, '2025-11-10', 'clock_in', '2025-11-10 20:15:28', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, '2025-11-11 10:15:32', -480, 840, 0),
(8, 3, 7, '2025-11-11', 'clock_in', '2025-11-11 10:15:58', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:15:57', -480, 0, 1),
(9, 3, 7, '2025-11-11', 'clock_out', '2025-11-11 10:16:06', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:16:06', -480, 0, 1),
(10, 3, NULL, '2025-11-11', 'clock_in', '2025-11-11 10:16:14', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(11, 3, NULL, '2025-11-11', 'clock_in', '2025-11-11 10:16:17', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(12, 3, NULL, '2025-11-11', 'clock_in', '2025-11-11 10:16:21', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(13, 3, NULL, '2025-11-11', 'clock_in', '2025-11-11 10:16:29', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0),
(14, 1, 8, '2025-11-11', 'clock_in', '2025-11-11 10:16:49', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:16:49', -480, 0, 1),
(15, 1, 8, '2025-11-11', 'clock_out', '2025-11-11 10:22:15', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:22:15', -480, 0, 1),
(16, 4, 9, '2025-11-11', 'clock_in', '2025-11-11 10:35:37', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:35:37', -480, 0, 1),
(17, 4, 9, '2025-11-11', 'clock_out', '2025-11-11 10:35:42', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', 10.312090, 123.899085, NULL, NULL, '2025-11-11 10:35:42', -480, 0, 1),
(18, 4, NULL, '2025-11-11', 'clock_in', '2025-11-11 10:35:44', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/142.0.0.0 Safari/537.36', NULL, NULL, NULL, NULL, NULL, NULL, NULL, 0);

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` int(11) NOT NULL,
  `actor_user_id` int(11) NOT NULL,
  `action` varchar(100) NOT NULL,
  `entity_type` varchar(100) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `before_data` text DEFAULT NULL,
  `after_data` text DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `chat`
--

CREATE TABLE `chat` (
  `chat_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `senior_architect_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_message_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat`
--

INSERT INTO `chat` (`chat_id`, `client_id`, `senior_architect_id`, `created_at`, `last_message_at`) VALUES
(1, 1, 7, '2025-09-17 14:49:09', '2025-09-18 12:49:50'),
(2, 13, 7, '2025-09-17 14:56:29', '2025-09-18 12:53:14'),
(3, 6, 7, '2025-09-18 12:57:04', '2025-09-18 12:57:19');

-- --------------------------------------------------------

--
-- Table structure for table `chat_messages`
--

CREATE TABLE `chat_messages` (
  `message_id` int(10) UNSIGNED NOT NULL,
  `chat_id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `chat_messages`
--

INSERT INTO `chat_messages` (`message_id`, `chat_id`, `sender_id`, `body`, `sent_at`, `read_at`) VALUES
(1, 1, 5, 'gsadfa', '2025-09-17 14:49:09', NULL),
(2, 1, 5, 'asdad', '2025-09-17 14:49:12', NULL),
(3, 1, 5, 'asasd', '2025-09-17 14:49:15', NULL),
(4, 1, 5, 'hi', '2025-09-17 14:49:39', NULL),
(5, 1, 5, 'hi', '2025-09-17 14:49:52', NULL),
(6, 1, 5, 'yo', '2025-09-17 14:52:30', NULL),
(7, 1, 7, 'hi', '2025-09-17 14:53:34', NULL),
(8, 2, 7, 'hi', '2025-09-17 14:56:29', NULL),
(9, 1, 5, 'hi', '2025-09-17 14:58:11', NULL),
(10, 2, 7, 'Hello Carl', '2025-09-18 12:40:06', NULL),
(11, 1, 5, 'Hello kenu', '2025-09-18 12:48:36', NULL),
(12, 1, 5, 'Hello Raven', '2025-09-18 12:49:50', NULL),
(13, 2, 5, 'Hi Raven', '2025-09-18 12:52:39', NULL),
(14, 2, 5, 'What\'s up?', '2025-09-18 12:53:14', NULL),
(15, 3, 7, 'Hi Queenie!', '2025-09-18 12:57:04', NULL),
(16, 3, 6, 'Hi there!', '2025-09-18 12:57:19', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `clients`
--

CREATE TABLE `clients` (
  `client_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `client_code` varchar(20) NOT NULL,
  `company_name` varchar(100) DEFAULT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `client_type` enum('individual','company') DEFAULT 'individual',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `clients`
--

INSERT INTO `clients` (`client_id`, `user_id`, `client_code`, `company_name`, `contact_person`, `client_type`, `created_at`) VALUES
(1, 5, 'CLI001', 'Thompson Enterprises', 'Carl Thompson', 'company', '2025-09-13 06:31:31'),
(2, NULL, 'CLI002', NULL, 'Quennie Martinez', 'individual', '2025-09-13 06:31:31'),
(3, NULL, '', NULL, NULL, 'individual', '2025-10-11 08:24:20'),
(4, 9, '', NULL, NULL, 'individual', '2025-10-11 08:26:47'),
(5, 13, '', NULL, NULL, 'individual', '2025-11-11 10:03:29');

-- --------------------------------------------------------

--
-- Table structure for table `client_inquiries`
--

CREATE TABLE `client_inquiries` (
  `id` int(11) NOT NULL,
  `client_id` int(11) DEFAULT NULL,
  `project_id` int(11) DEFAULT NULL,
  `subject` varchar(255) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `status` varchar(50) DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `recipient_id` int(11) DEFAULT NULL,
  `category` varchar(50) DEFAULT 'general',
  `request_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `client_inquiries`
--

INSERT INTO `client_inquiries` (`id`, `client_id`, `project_id`, `subject`, `message`, `status`, `created_at`, `recipient_id`, `category`, `request_id`) VALUES
(37, 4, 21, 'Project Request: Alexandra\'s House Design', 'Include a home office, small garden, and balcony with glass railing, facing east, the family wants energy-efficient materials and a rooftop solar option.', 'in_progress', '2025-10-29 07:57:13', 7, 'project_request', 37),
(38, 4, 23, 'Project Request: Alexa Project', 'Details Example', 'in_progress', '2025-11-11 08:56:02', 11, 'project_request', 38),
(39, 5, 24, 'Project Request: Alexa Project', 'Project details.', 'in_progress', '2025-11-11 10:04:51', 12, 'project_request', 39),
(40, 4, 25, 'Project Request: KenuAlexa (Group 1 – Simple Structures)', 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', 'in_progress', '2025-11-19 07:28:18', 7, 'project_request', 40),
(41, 4, 26, 'Project Request: Project Request (Group 1 – Simple Structures)', 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', 'in_progress', '2025-11-19 09:07:16', 12, 'project_request', 41),
(42, 4, 29, 'Project Request: 123123', 'Client requested new project: 123123', 'in_progress', '2025-11-19 09:12:45', 7, 'project_request', 42),
(43, 4, 30, 'Project Request: ME AND ALEXANDRA YIE (Group 1 – Simple Structures)', 'Preliminary design fee estimate\r\nArea: 123 sqm\r\nProject Cost: ₱4,305,000\r\nDesign Fee (Group 1 – Simple Structures): ₱258,300\r\nPlease refine scope, inclusions, and assumptions.', 'in_progress', '2025-11-19 12:03:01', 11, 'project_request', 43);

-- --------------------------------------------------------

--
-- Table structure for table `contractors`
--

CREATE TABLE `contractors` (
  `contractor_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `contractor_code` varchar(20) NOT NULL,
  `company_name` varchar(100) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `specialization` varchar(100) DEFAULT NULL,
  `license_number` varchar(50) DEFAULT NULL,
  `rating` decimal(3,2) DEFAULT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contractor_updates`
--

CREATE TABLE `contractor_updates` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `contractor_id` int(11) NOT NULL,
  `update_details` text NOT NULL,
  `update_time` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `contracts`
--

CREATE TABLE `contracts` (
  `contract_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `contract_number` varchar(50) NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `contract_value` decimal(15,2) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('draft','signed','completed') DEFAULT 'draft',
  `contract_file` longblob DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `design_reviews`
--

CREATE TABLE `design_reviews` (
  `review_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `milestone_id` int(10) UNSIGNED DEFAULT NULL,
  `document_id` int(10) UNSIGNED DEFAULT NULL,
  `reviewer_id` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','approved','changes_requested','rejected') DEFAULT 'pending',
  `comments` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `design_services`
--

CREATE TABLE `design_services` (
  `service_id` int(10) UNSIGNED NOT NULL,
  `service_name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dm`
--

CREATE TABLE `dm` (
  `dm_id` int(10) UNSIGNED NOT NULL,
  `user_one_id` int(10) UNSIGNED NOT NULL,
  `user_two_id` int(10) UNSIGNED NOT NULL,
  `last_message_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dm`
--

INSERT INTO `dm` (`dm_id`, `user_one_id`, `user_two_id`, `last_message_at`) VALUES
(1, 3, 7, '2025-09-18 13:44:28'),
(2, 2, 7, '2025-09-18 13:52:15'),
(3, 2, 3, '2025-09-18 13:52:13'),
(4, 3, 4, '2025-09-18 13:51:44'),
(5, 1, 3, '2025-09-18 13:52:40'),
(6, 3, 5, '2025-09-18 13:44:47'),
(7, 2, 4, '2025-09-18 13:52:19'),
(8, 4, 7, '2025-09-18 13:51:45'),
(9, 1, 4, '2025-09-18 13:52:47'),
(10, 4, 5, '2025-09-18 13:51:51'),
(11, 4, 6, '2025-09-18 13:51:53'),
(12, 1, 2, '2025-09-18 13:52:45'),
(13, 2, 5, '2025-09-18 13:52:25'),
(14, 2, 6, '2025-09-18 13:52:27'),
(15, 1, 7, '2025-09-18 13:52:43'),
(16, 1, 5, '2025-09-18 13:52:50'),
(17, 1, 6, '2025-09-18 13:52:52'),
(18, 3, 6, '2025-09-18 13:53:25'),
(19, 1, 11, '2025-10-17 13:23:32'),
(20, 4, 11, '2025-11-11 16:19:20');

-- --------------------------------------------------------

--
-- Table structure for table `dm_messages`
--

CREATE TABLE `dm_messages` (
  `id` int(10) UNSIGNED NOT NULL,
  `dm_id` int(10) UNSIGNED NOT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `body` text NOT NULL,
  `sent_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `dm_messages`
--

INSERT INTO `dm_messages` (`id`, `dm_id`, `sender_id`, `body`, `sent_at`, `read_at`) VALUES
(1, 1, 7, 'Renato hello!', '2025-09-18 13:33:44', NULL),
(2, 2, 2, 'Hi Raven, Sonny here!', '2025-09-18 13:43:44', NULL),
(3, 2, 7, 'Hi Sonny!', '2025-09-18 13:44:09', NULL),
(4, 1, 3, 'Hi Raven!', '2025-09-18 13:44:28', NULL),
(5, 3, 3, 'Hi Sonny!', '2025-09-18 13:44:32', NULL),
(6, 4, 3, 'Hi Kenu!', '2025-09-18 13:44:38', NULL),
(7, 5, 3, 'Hi Admin!', '2025-09-18 13:44:43', NULL),
(8, 6, 3, 'Hi!', '2025-09-18 13:44:47', NULL),
(9, 7, 4, 'Hi Sonny! How are you!?', '2025-09-18 13:51:40', NULL),
(10, 4, 4, 'hi', '2025-09-18 13:51:44', NULL),
(11, 8, 4, 'hi', '2025-09-18 13:51:45', NULL),
(12, 9, 4, 'hi', '2025-09-18 13:51:48', NULL),
(13, 10, 4, 'hi', '2025-09-18 13:51:51', NULL),
(14, 11, 4, 'hi', '2025-09-18 13:51:53', NULL),
(15, 3, 2, 'hi', '2025-09-18 13:52:13', NULL),
(16, 2, 2, 'hi', '2025-09-18 13:52:15', NULL),
(17, 7, 2, 'hi', '2025-09-18 13:52:19', NULL),
(18, 12, 2, 'hi', '2025-09-18 13:52:21', NULL),
(19, 12, 2, 'hi', '2025-09-18 13:52:23', NULL),
(20, 13, 2, 'hi', '2025-09-18 13:52:25', NULL),
(21, 14, 2, 'hi', '2025-09-18 13:52:27', NULL),
(22, 5, 1, 'hi', '2025-09-18 13:52:40', NULL),
(23, 15, 1, 'hi', '2025-09-18 13:52:43', NULL),
(24, 12, 1, 'hi', '2025-09-18 13:52:45', NULL),
(25, 9, 1, 'hi', '2025-09-18 13:52:47', NULL),
(26, 16, 1, 'hi', '2025-09-18 13:52:50', NULL),
(27, 17, 1, 'hi', '2025-09-18 13:52:52', NULL),
(28, 18, 3, 'hi', '2025-09-18 13:53:25', NULL),
(29, 19, 1, 'biot', '2025-10-17 13:23:32', NULL),
(30, 20, 11, 'sdfadfdfadfaf', '2025-10-17 15:03:10', NULL),
(31, 20, 11, 'sdfsaf', '2025-10-17 15:03:11', NULL),
(32, 20, 11, 'sadfa', '2025-10-17 15:03:12', NULL),
(33, 20, 11, 'sfsdfsa', '2025-10-17 15:03:12', NULL),
(34, 20, 11, 'sfs', '2025-10-17 15:03:13', NULL),
(35, 20, 11, 'fsadf', '2025-10-17 15:03:13', NULL),
(36, 20, 11, 'sf', '2025-10-17 15:03:13', NULL),
(37, 20, 11, 'sa', '2025-10-17 15:03:14', NULL),
(38, 20, 4, 'hello', '2025-11-11 16:19:11', NULL),
(39, 20, 4, 'daviid byot', '2025-11-11 16:19:20', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `documents`
--

CREATE TABLE `documents` (
  `document_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `uploaded_by` int(10) UNSIGNED NOT NULL,
  `document_name` varchar(200) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `upload_date` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `employee_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `employee_code` varchar(20) NOT NULL,
  `position` enum('architect','senior_architect','project_manager') NOT NULL,
  `department` varchar(100) DEFAULT 'Architecture',
  `hire_date` date NOT NULL,
  `salary` decimal(10,2) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `bank_name` varchar(100) DEFAULT NULL,
  `account_name` varchar(150) DEFAULT NULL,
  `account_number` varchar(50) DEFAULT NULL,
  `sss_no` varchar(50) DEFAULT NULL,
  `philhealth_no` varchar(50) DEFAULT NULL,
  `hdmf_no` varchar(50) DEFAULT NULL,
  `exclude_sss` tinyint(1) NOT NULL DEFAULT 0,
  `exclude_philhealth` tinyint(1) NOT NULL DEFAULT 0,
  `exclude_pagibig` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`employee_id`, `user_id`, `employee_code`, `position`, `department`, `hire_date`, `salary`, `status`, `created_at`, `bank_name`, `account_name`, `account_number`, `sss_no`, `philhealth_no`, `hdmf_no`, `exclude_sss`, `exclude_philhealth`, `exclude_pagibig`) VALUES
(1, 2, 'EMP001', 'architect', 'Architecture', '2024-01-15', 45000.00, 'active', '2025-09-13 06:31:31', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0),
(2, 3, 'EMP002', 'project_manager', 'Architecture', '2023-08-20', 55000.00, 'active', '2025-09-13 06:31:31', 'BDO', 'Renato Hernandez Jr.', '123123123', NULL, NULL, NULL, 0, 0, 0),
(3, 7, 'EMP003', 'senior_architect', 'Architecture', '2022-03-10', 65000.00, 'active', '2025-09-13 06:31:31', NULL, NULL, NULL, NULL, NULL, NULL, 0, 0, 0),
(4, 11, 'EMP-11', 'senior_architect', 'Architecture', '2025-10-17', 0.00, 'active', '2025-10-17 06:03:55', 'Land Bank', 'David Baylosis', '00124540', NULL, NULL, NULL, 0, 0, 0),
(5, 12, 'EMP-12', 'senior_architect', 'Architecture', '2025-10-19', 0.00, 'active', '2025-10-19 13:08:30', '123123', '1231', '123', '1231', '1231', '1232', 0, 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `employee_bank_change_requests`
--

CREATE TABLE `employee_bank_change_requests` (
  `request_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `requested_bank_name` varchar(100) DEFAULT NULL,
  `requested_account_name` varchar(150) DEFAULT NULL,
  `requested_account_number` varchar(50) DEFAULT NULL,
  `attachment_path` varchar(255) DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `submitted_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `employee_bank_change_requests`
--

INSERT INTO `employee_bank_change_requests` (`request_id`, `employee_id`, `requested_bank_name`, `requested_account_name`, `requested_account_number`, `attachment_path`, `status`, `submitted_at`) VALUES
(1, 2, 'BPI', 'Renato Hernandez Jr.', '12233412', 'uploads/bank_changes/req_2_20251023_084816_55c710b5.png', 'rejected', '2025-10-23 06:48:16'),
(2, 2, 'BPI', 'Renato Hernandez Jr.', '12233412', 'uploads/bank_changes/req_2_20251023_085038_b9cdb723.png', 'rejected', '2025-10-23 06:50:38'),
(3, 2, 'BPI', 'Renato Hernandez Jr.', '12233412', 'uploads/bank_changes/req_2_20251023_085045_bee15a0f.png', 'approved', '2025-10-23 06:50:45'),
(4, 2, 'BDO', 'Renato Hernandez Jr.', '123123123', 'uploads/bank_changes/req_2_20251111_131110_983c8e5d.jpeg', 'approved', '2025-11-11 12:11:10'),
(5, 2, 'BDO', 'Renato Hernandez Jr.', '12233412', NULL, 'pending', '2025-11-11 12:12:25'),
(6, 4, 'Land Bank', 'David Baylosis', '00124540', NULL, 'approved', '2025-11-11 12:16:15');

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `invoice_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `invoice_number` varchar(50) NOT NULL,
  `invoice_date` date NOT NULL,
  `due_date` date DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT 0.00,
  `paid_amount` decimal(15,2) DEFAULT 0.00,
  `status` enum('draft','sent','paid') DEFAULT 'draft',
  `invoice_file` longblob DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `leave_requests`
--

CREATE TABLE `leave_requests` (
  `leave_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `leave_type` enum('sick','vacation','personal') NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date NOT NULL,
  `days_count` int(10) UNSIGNED NOT NULL,
  `reason` text DEFAULT NULL,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `applied_date` timestamp NOT NULL DEFAULT current_timestamp(),
  `attachment_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `leave_requests`
--

INSERT INTO `leave_requests` (`leave_id`, `employee_id`, `leave_type`, `start_date`, `end_date`, `days_count`, `reason`, `status`, `applied_date`, `attachment_path`) VALUES
(1, 3, 'sick', '2025-10-25', '2025-10-31', 0, 'reason', 'approved', '2025-10-25 06:58:05', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `materials`
--

CREATE TABLE `materials` (
  `material_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `default_unit` varchar(20) DEFAULT 'pcs',
  `description` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `materials`
--

INSERT INTO `materials` (`material_id`, `name`, `category_id`, `default_unit`, `description`, `is_active`, `created_at`) VALUES
(1, 'Steel', NULL, 'pcs', NULL, 1, '2025-11-19 06:24:50'),
(2, 'Concrete', NULL, 'pcs', NULL, 1, '2025-11-19 13:58:27'),
(3, 'asdasd', NULL, 'pcs', NULL, 1, '2025-11-20 08:25:03'),
(4, 'Stone', NULL, 'pcs', NULL, 1, '2025-11-20 08:25:12');

-- --------------------------------------------------------

--
-- Table structure for table `material_categories`
--

CREATE TABLE `material_categories` (
  `category_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `parent_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `messages`
--

CREATE TABLE `messages` (
  `message_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED DEFAULT NULL,
  `sender_id` int(10) UNSIGNED NOT NULL,
  `recipient_id` int(10) UNSIGNED NOT NULL,
  `subject` varchar(200) DEFAULT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `sent_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `messages`
--

INSERT INTO `messages` (`message_id`, `project_id`, `sender_id`, `recipient_id`, `subject`, `message`, `is_read`, `sent_at`) VALUES
(1, NULL, 5, 7, 'hello i wuold like to request a project', 'asdad', 0, '2025-09-17 06:01:36');

-- --------------------------------------------------------

--
-- Table structure for table `milestones`
--

CREATE TABLE `milestones` (
  `milestone_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `milestone_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `target_date` date DEFAULT NULL,
  `completion_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `is_read` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `title` varchar(255) DEFAULT NULL,
  `type` varchar(50) DEFAULT 'general'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `message`, `is_read`, `created_at`, `title`, `type`) VALUES
(1, 7, 'Leave #1 was approved. Notes: asda', 0, '2025-10-25 07:00:13', 'Leave Approved', 'general'),
(2, 12, 'A new project request (House) has been assigned to you.', 0, '2025-10-25 07:06:57', 'New Project Request Assigned', 'project'),
(3, 7, 'A new project request (Alexandra\'s House Design) has been assigned to you.', 0, '2025-10-29 07:57:13', 'New Project Request Assigned', 'project'),
(4, 11, 'A new project request (Alexa Project) has been assigned to you.', 0, '2025-11-11 08:56:02', 'New Project Request Assigned', 'project'),
(5, 12, 'A new project request (Alexa Project) has been assigned to you.', 0, '2025-11-11 10:04:51', 'New Project Request Assigned', 'project'),
(6, 7, 'A new project request (KenuAlexa (Group 1 – Simple Structures)) has been assigned to you.', 0, '2025-11-19 07:28:18', 'New Project Request Assigned', 'project'),
(7, 12, 'A new project request (Project Request (Group 1 – Simple Structures)) has been assigned to you.', 0, '2025-11-19 09:07:16', 'New Project Request Assigned', 'project'),
(8, 7, 'A new project request (123123) has been assigned to you.', 0, '2025-11-19 09:12:45', 'New Project Request Assigned', 'project'),
(9, 11, 'A new project request (ME AND ALEXANDRA YIE (Group 1 – Simple Structures)) has been assigned to you.', 0, '2025-11-19 12:03:01', 'New Project Request Assigned', 'project');

-- --------------------------------------------------------

--
-- Table structure for table `payroll`
--

CREATE TABLE `payroll` (
  `payroll_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `month_year` varchar(7) NOT NULL,
  `regular_hours` decimal(5,2) DEFAULT 0.00,
  `overtime_hours` decimal(5,2) DEFAULT 0.00,
  `gross_pay` decimal(15,2) DEFAULT 0.00,
  `deductions` decimal(15,2) DEFAULT 0.00,
  `net_pay` decimal(15,2) DEFAULT 0.00,
  `status` enum('pending','paid') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `payroll`
--

INSERT INTO `payroll` (`payroll_id`, `employee_id`, `month_year`, `regular_hours`, `overtime_hours`, `gross_pay`, `deductions`, `net_pay`, `status`, `created_at`) VALUES
(1, 1, '2025-09', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-09-20 12:19:21'),
(2, 2, '2025-09', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-09-20 12:19:21'),
(3, 3, '2025-09', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-09-20 12:19:21'),
(4, 1, '2025-12', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 06:38:58'),
(5, 2, '2025-12', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 06:38:58'),
(6, 3, '2025-12', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 06:38:58'),
(7, 4, '2025-12', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 06:38:58'),
(8, 1, '2025-10', 22.01, 14.01, 10105.18, 0.00, 10105.18, '', '2025-10-17 09:54:55'),
(9, 2, '2025-10', 0.08, 0.00, 25.00, 0.00, 25.00, '', '2025-10-17 09:54:55'),
(10, 3, '2025-10', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 09:54:55'),
(11, 4, '2025-10', 0.00, 0.00, 0.00, 0.00, 0.00, '', '2025-10-17 09:54:55'),
(12, 5, '2025-10', 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', '2025-10-22 08:33:46'),
(13, 1, '2025-11', 0.09, 0.00, 23.01, 0.00, 23.01, 'pending', '2025-11-11 08:17:44'),
(14, 2, '2025-11', 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', '2025-11-11 08:17:45'),
(15, 3, '2025-11', 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', '2025-11-11 08:17:45'),
(16, 4, '2025-11', 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', '2025-11-11 08:17:45'),
(17, 5, '2025-11', 0.00, 0.00, 0.00, 0.00, 0.00, 'pending', '2025-11-11 08:17:45');

-- --------------------------------------------------------

--
-- Table structure for table `pm_senior_files`
--

CREATE TABLE `pm_senior_files` (
  `id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `uploaded_by_employee_id` int(10) UNSIGNED NOT NULL,
  `design_phase` varchar(100) DEFAULT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `relative_path` varchar(255) NOT NULL,
  `mime_type` varchar(150) DEFAULT NULL,
  `size` bigint(20) UNSIGNED DEFAULT NULL,
  `note` text DEFAULT NULL,
  `status` enum('pending','reviewed','revisions_requested') NOT NULL DEFAULT 'pending',
  `senior_comment` text DEFAULT NULL,
  `reviewed_by_employee_id` int(11) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `pm_senior_files`
--

INSERT INTO `pm_senior_files` (`id`, `project_id`, `uploaded_by_employee_id`, `design_phase`, `original_name`, `stored_name`, `relative_path`, `mime_type`, `size`, `note`, `status`, `senior_comment`, `reviewed_by_employee_id`, `reviewed_at`, `uploaded_at`) VALUES
(6, 23, 2, 'Schematic Design', 'NewTechArchitecturalDesign-scaled.jpeg', 'pmproj_23_1762853637_5a3598_NewTechArchitecturalDesign-scaled.jpeg', 'PMuploads/pmproj_23_1762853637_5a3598_NewTechArchitecturalDesign-scaled.jpeg', 'image/jpeg', 417786, '123', 'pending', NULL, NULL, NULL, '2025-11-11 17:33:57'),
(7, 23, 2, 'Pre-Design / Programming', 'NewTechArchitecturalDesign-scaled.jpeg', 'pmproj_23_1762854124_bc6d56_NewTechArchitecturalDesign-scaled.jpeg', 'PMuploads/pmproj_23_1762854124_bc6d56_NewTechArchitecturalDesign-scaled.jpeg', 'image/jpeg', 417786, '123', 'revisions_requested', 'fefadasda', 4, '2025-11-11 17:50:47', '2025-11-11 17:42:04');

-- --------------------------------------------------------

--
-- Table structure for table `pm_senior_file_comments`
--

CREATE TABLE `pm_senior_file_comments` (
  `id` int(10) UNSIGNED NOT NULL,
  `file_id` int(10) UNSIGNED NOT NULL,
  `author_employee_id` int(11) DEFAULT NULL,
  `author_role` varchar(50) NOT NULL,
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `projects`
--

CREATE TABLE `projects` (
  `project_id` int(10) UNSIGNED NOT NULL,
  `project_code` varchar(50) DEFAULT NULL,
  `project_name` varchar(255) NOT NULL,
  `client_id` int(10) UNSIGNED DEFAULT NULL,
  `architect_id` int(10) UNSIGNED DEFAULT NULL,
  `project_manager_id` int(10) UNSIGNED DEFAULT NULL,
  `project_type` varchar(100) DEFAULT NULL,
  `status` varchar(50) NOT NULL DEFAULT 'pending',
  `description` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT NULL,
  `project_image` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `created_by` int(11) DEFAULT NULL,
  `size_sq_m` decimal(10,2) DEFAULT NULL,
  `location_text` varchar(255) DEFAULT NULL,
  `estimated_end_date` date DEFAULT NULL,
  `budget_amount` decimal(14,2) DEFAULT NULL,
  `phase` varchar(64) DEFAULT 'Pre-Design / Programming',
  `is_archived` tinyint(1) NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `projects`
--

INSERT INTO `projects` (`project_id`, `project_code`, `project_name`, `client_id`, `architect_id`, `project_manager_id`, `project_type`, `status`, `description`, `location`, `start_date`, `end_date`, `budget`, `project_image`, `created_at`, `created_by`, `size_sq_m`, `location_text`, `estimated_end_date`, `budget_amount`, `phase`, `is_archived`, `is_deleted`) VALUES
(20, NULL, 'Project Request: Alexandra\'s House Design', 4, NULL, 3, 'design_only', 'planning', 'Include a home office, small garden, and balcony with glass railing, facing east, the family wants energy-efficient materials and a rooftop solar option.', 'Talisay', '2025-10-29', NULL, 9000000.00, NULL, '2025-10-29 07:57:29', 7, 99999999.99, 'Talisay', '2027-01-29', 9000000.00, 'Pre-Design / Programming', 0, 1),
(21, NULL, 'Project Request: Alexandra\'s House Design', 4, NULL, NULL, NULL, 'pending', 'Include a home office, small garden, and balcony with glass railing, facing east, the family wants energy-efficient materials and a rooftop solar option.', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-11 08:57:14', 7, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 1),
(22, NULL, 'Project Request: Alexa Project', 4, NULL, NULL, NULL, 'pending', 'Details Example', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-11 08:59:09', 11, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 1),
(23, NULL, 'Project Request: Alexa Project', 4, NULL, 3, NULL, 'pending', 'Details Example', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-11 09:04:13', 11, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(24, NULL, 'Project Request: Alexa Project', 5, NULL, 3, NULL, 'pending', 'Project details.', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-11 11:40:41', 12, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(25, NULL, 'Project Request: KenuAlexa (Group 1 – Simple Structures)', 4, NULL, 3, 'design_only', 'planning', 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 07:28:45', 7, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(26, NULL, 'Project Request: Project Request (Group 1 – Simple Structures)', 4, NULL, 3, NULL, 'pending', 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 09:07:43', 12, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(27, NULL, 'Project Request: 123123', 4, NULL, 3, NULL, 'pending', 'Client requested new project: 123123', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 09:13:13', 7, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(28, NULL, 'Project Request: 123123', 4, NULL, 3, NULL, 'pending', 'Client requested new project: 123123', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 09:13:51', 7, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(29, NULL, 'Project Request: 123123', 4, NULL, 3, NULL, 'pending', 'Client requested new project: 123123', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 09:13:51', 7, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0),
(30, NULL, 'Project Request: ME AND ALEXANDRA YIE (Group 1 – Simple Structures)', 4, NULL, 3, NULL, 'pending', 'Preliminary design fee estimate\r\nArea: 123 sqm\r\nProject Cost: ₱4,305,000\r\nDesign Fee (Group 1 – Simple Structures): ₱258,300\r\nPlease refine scope, inclusions, and assumptions.', NULL, '0000-00-00', NULL, NULL, NULL, '2025-11-19 12:03:28', 11, NULL, NULL, NULL, NULL, 'Pre-Design / Programming', 0, 0);

-- --------------------------------------------------------

--
-- Table structure for table `project_client_review_files`
--

CREATE TABLE `project_client_review_files` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_name` varchar(255) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `version` int(11) NOT NULL DEFAULT 1,
  `review_status` enum('pending','approved','changes_requested') NOT NULL DEFAULT 'pending',
  `uploaded_by` int(11) NOT NULL,
  `reviewer_user_id` int(11) DEFAULT NULL,
  `client_feedback` text DEFAULT NULL,
  `internal_notes` text DEFAULT NULL,
  `hash` char(40) DEFAULT NULL,
  `group_token` char(16) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_client_review_files`
--

INSERT INTO `project_client_review_files` (`id`, `project_id`, `original_name`, `stored_name`, `file_path`, `version`, `review_status`, `uploaded_by`, `reviewer_user_id`, `client_feedback`, `internal_notes`, `hash`, `group_token`, `created_at`, `updated_at`) VALUES
(1, 3, 'NewTechArchitecturalDesign-scaled.jpeg', '41d02d875dfa_NewTechArchitecturalDesign-scaled.jpeg', 'uploads/SeniortoClientUploads/3/41d02d875dfa_NewTechArchitecturalDesign-scaled.jpeg', 1, 'pending', 7, NULL, NULL, NULL, 'eaa46e0da112d1b7de3cf6c3e226dd027eec123b', 'aec5d1eac7b65e82', '2025-10-02 11:46:36', '2025-10-02 11:46:36'),
(2, 3, 'NewTechArchitecturalDesign-scaled.jpeg', '9ba2c98d5346_NewTechArchitecturalDesign-scaled.jpeg', 'uploads/SeniortoClientUploads/3/9ba2c98d5346_NewTechArchitecturalDesign-scaled.jpeg', 2, 'pending', 7, NULL, NULL, NULL, '40c1a124f31a37a7d6bb5f873b6778b1da1dfd7e', 'aec5d1eac7b65e82', '2025-10-02 11:59:36', '2025-10-02 11:59:36'),
(3, 6, 'NewTechArchitecturalDesign-scaled.jpeg', '9a12bac94f87_NewTechArchitecturalDesign-scaled.jpeg', 'uploads/SeniortoClientUploads/6/9a12bac94f87_NewTechArchitecturalDesign-scaled.jpeg', 1, 'pending', 7, NULL, NULL, NULL, '746a379ca37306005272768b47e5cf5a5bffc9b0', '47c50b85cdbbdc49', '2025-10-08 05:26:05', '2025-10-08 05:26:05'),
(4, 6, 'NewTechArchitecturalDesign-scaled.jpeg', 'fe3cc65a49b3_NewTechArchitecturalDesign-scaled.jpeg', 'SeniortoClientUploads/6/fe3cc65a49b3_NewTechArchitecturalDesign-scaled.jpeg', 2, 'changes_requested', 7, NULL, NULL, NULL, '9d98d156b24e8b64f76a4e7226a8b5b5c207fa15', '47c50b85cdbbdc49', '2025-10-08 05:42:42', '2025-10-09 11:35:36'),
(5, 19, 'NewTechArchitecturalDesign-scaled.jpeg', 'e638dbc0e99e_NewTechArchitecturalDesign-scaled.jpeg', 'SeniortoClientUploads/19/e638dbc0e99e_NewTechArchitecturalDesign-scaled.jpeg', 1, 'changes_requested', 12, NULL, NULL, NULL, '26d6326b43a847da60990312b95dc5945173edfc', 'bc2967b6fcc76277', '2025-10-25 07:15:43', '2025-10-25 07:18:09'),
(6, 24, 'task_29_1762853339_691301db6f4e0.jpeg', 'd904fb4770e1_task_29_1762853339_691301db6f4e0.jpeg', 'SeniortoClientUploads/24/d904fb4770e1_task_29_1762853339_691301db6f4e0.jpeg', 1, 'pending', 12, NULL, NULL, NULL, '57c5c13c71e84e9076a4d2a4a3cacc355cd8d721', '2894b167767b4249', '2025-11-11 11:40:59', '2025-11-11 11:40:59'),
(7, 25, 'task_29_1762853339_691301db6f4e0.jpeg', 'c9b48fca0009_task_29_1762853339_691301db6f4e0.jpeg', 'SeniortoClientUploads/25/c9b48fca0009_task_29_1762853339_691301db6f4e0.jpeg', 1, 'pending', 7, NULL, NULL, NULL, '7fac91b4920e9852b75acecd1a202973dfac1dc9', '4fb22fd587740338', '2025-11-21 03:51:25', '2025-11-21 03:51:25');

-- --------------------------------------------------------

--
-- Table structure for table `project_client_review_file_messages`
--

CREATE TABLE `project_client_review_file_messages` (
  `id` int(11) NOT NULL,
  `review_file_id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `author_user_id` int(11) NOT NULL,
  `author_role` varchar(40) NOT NULL,
  `action` enum('comment','request_changes','approve') NOT NULL DEFAULT 'comment',
  `message` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_client_review_file_messages`
--

INSERT INTO `project_client_review_file_messages` (`id`, `review_file_id`, `project_id`, `author_user_id`, `author_role`, `action`, `message`, `created_at`) VALUES
(1, 3, 6, 1, 'client', 'comment', 'comment', '2025-10-08 05:49:50'),
(2, 4, 6, 1, 'client', 'comment', 'Comment', '2025-10-08 05:58:34'),
(3, 4, 6, 1, 'client', 'comment', 'hello', '2025-10-09 03:51:07'),
(4, 4, 6, 1, 'client', 'request_changes', 'chanfe', '2025-10-09 04:13:32'),
(5, 4, 6, 1, 'client', 'comment', 'fafsd', '2025-10-09 04:13:36'),
(6, 4, 6, 1, 'client', 'comment', 'faffaf', '2025-10-09 05:26:13'),
(7, 4, 6, 1, 'client', 'approve', 'sasa', '2025-10-09 11:35:33'),
(8, 4, 6, 1, 'client', 'request_changes', 'asd', '2025-10-09 11:35:36'),
(9, 4, 6, 1, 'client', 'comment', 'asd', '2025-10-09 11:39:54'),
(10, 4, 6, 1, 'client', 'comment', 'asd', '2025-10-09 11:39:56'),
(11, 4, 6, 1, 'client', 'comment', 'asda', '2025-10-09 11:39:58'),
(12, 4, 6, 1, 'client', 'comment', 'asdasd', '2025-10-09 11:46:23'),
(13, 4, 6, 7, 'senior_architect', 'request_changes', 'asdasd', '2025-10-11 07:47:45'),
(14, 4, 6, 7, 'senior_architect', 'comment', 'sdafadsaf', '2025-10-11 07:47:53'),
(15, 4, 6, 7, 'senior_architect', 'request_changes', 'asdasdasd', '2025-10-11 07:53:28'),
(16, 5, 19, 12, 'senior_architect', 'comment', 'pls check client if all good for pre desing phase', '2025-10-25 07:16:46'),
(17, 5, 19, 6, 'client', 'request_changes', 'asfsaafsdfsfssdfas', '2025-10-25 07:18:09'),
(18, 5, 19, 12, 'senior_architect', 'comment', 'ok noted changes will be made', '2025-10-25 07:18:47'),
(19, 6, 24, 13, 'client', 'comment', 'Hello', '2025-11-11 11:51:13');

-- --------------------------------------------------------

--
-- Table structure for table `project_contractors`
--

CREATE TABLE `project_contractors` (
  `id` int(11) NOT NULL,
  `project_id` int(11) DEFAULT NULL,
  `contractor_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_contractors`
--

INSERT INTO `project_contractors` (`id`, `project_id`, `contractor_id`) VALUES
(3, 3, 18);

-- --------------------------------------------------------

--
-- Table structure for table `project_estimates`
--

CREATE TABLE `project_estimates` (
  `estimate_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(10) UNSIGNED NOT NULL,
  `quantity` decimal(10,2) DEFAULT 1.00,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `total_amount` decimal(15,2) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_files`
--

CREATE TABLE `project_files` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_path` varchar(255) NOT NULL,
  `audience` enum('internal','client') NOT NULL DEFAULT 'internal',
  `review_status` enum('pending','approved','changes_requested') NOT NULL DEFAULT 'pending',
  `version` int(11) NOT NULL DEFAULT 1,
  `uploaded_by` int(11) DEFAULT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `project_materials`
--

CREATE TABLE `project_materials` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `material_id` int(11) DEFAULT NULL,
  `custom_name` varchar(150) DEFAULT NULL,
  `quantity` decimal(12,3) DEFAULT 0.000,
  `unit` varchar(20) DEFAULT 'pcs',
  `cost_per_unit` decimal(12,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `added_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_materials`
--

INSERT INTO `project_materials` (`id`, `project_id`, `material_id`, `custom_name`, `quantity`, `unit`, `cost_per_unit`, `notes`, `added_by`, `created_at`) VALUES
(1, 23, 1, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-19 06:24:51'),
(2, 23, NULL, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-19 13:02:30'),
(3, 23, NULL, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-19 13:02:37'),
(4, 30, NULL, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-19 13:58:19'),
(5, 30, 2, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-19 13:58:27'),
(10, 25, 4, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-20 08:25:12'),
(11, 25, 1, NULL, 0.000, 'pcs', NULL, NULL, 2, '2025-11-21 02:53:57');

-- --------------------------------------------------------

--
-- Table structure for table `project_requests`
--

CREATE TABLE `project_requests` (
  `id` int(11) NOT NULL,
  `client_id` int(11) NOT NULL,
  `senior_architect_id` int(11) DEFAULT NULL,
  `project_name` varchar(255) NOT NULL,
  `project_type` varchar(100) NOT NULL,
  `preferred_start_date` date DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `budget` decimal(15,2) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `status` enum('pending','review','approved','declined') DEFAULT 'pending',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_requests`
--

INSERT INTO `project_requests` (`id`, `client_id`, `senior_architect_id`, `project_name`, `project_type`, `preferred_start_date`, `location`, `budget`, `details`, `status`, `created_at`) VALUES
(37, 4, 7, 'Alexandra\'s House Design', 'design_only', '2025-10-29', 'Lawaan', 9000000.00, 'Include a home office, small garden, and balcony with glass railing, facing east, the family wants energy-efficient materials and a rooftop solar option.', 'pending', '2025-10-29 07:57:13'),
(38, 4, 11, 'Alexa Project', 'design_only', '2025-11-11', 'Talisay Lawaan', 230000.00, 'Details Example', 'pending', '2025-11-11 08:56:02'),
(39, 5, 12, 'Alexa Project', 'design_only', '2025-11-11', 'Lawaan 3', 12312313.00, 'Project details.', 'pending', '2025-11-11 10:04:51'),
(40, 4, 7, 'KenuAlexa (Group 1 – Simple Structures)', 'design_only', NULL, 'Talisay Lawaan', 525000.00, 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', 'pending', '2025-11-19 07:28:18'),
(41, 4, 12, 'Project Request (Group 1 – Simple Structures)', 'design_only', '2025-11-19', 'Talisay Lawaan', 525000.00, 'Preliminary design fee estimate\r\nArea: 250 sqm\r\nProject Cost: ₱8,750,000\r\nDesign Fee (Group 1 – Simple Structures): ₱525,000\r\nPlease refine scope, inclusions, and assumptions.', 'pending', '2025-11-19 09:07:16'),
(42, 4, 7, '123123', 'design_only', NULL, '12313', 123123123.00, NULL, 'pending', '2025-11-19 09:12:45'),
(43, 4, 11, 'ME AND ALEXANDRA YIE (Group 1 – Simple Structures)', 'design_only', '2025-11-19', 'Talisay', 258300.00, 'Preliminary design fee estimate\r\nArea: 123 sqm\r\nProject Cost: ₱4,305,000\r\nDesign Fee (Group 1 – Simple Structures): ₱258,300\r\nPlease refine scope, inclusions, and assumptions.', 'pending', '2025-11-19 12:03:01');

-- --------------------------------------------------------

--
-- Table structure for table `project_senior_architects`
--

CREATE TABLE `project_senior_architects` (
  `psa_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `employee_id` int(10) UNSIGNED NOT NULL,
  `role` enum('lead','reviewer','advisor') NOT NULL DEFAULT 'advisor',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_senior_architects`
--

INSERT INTO `project_senior_architects` (`psa_id`, `project_id`, `employee_id`, `role`, `assigned_at`) VALUES
(18, 20, 3, 'lead', '2025-10-29 07:57:29'),
(19, 21, 3, 'lead', '2025-11-11 08:57:14'),
(20, 22, 4, 'lead', '2025-11-11 08:59:09'),
(21, 23, 4, 'lead', '2025-11-11 09:04:13'),
(22, 24, 5, 'lead', '2025-11-11 11:40:41'),
(23, 25, 3, 'lead', '2025-11-19 07:28:45'),
(24, 26, 5, 'lead', '2025-11-19 09:07:43'),
(25, 27, 3, 'lead', '2025-11-19 09:13:13'),
(26, 28, 3, 'lead', '2025-11-19 09:13:51'),
(27, 29, 3, 'lead', '2025-11-19 09:13:51'),
(28, 30, 4, 'lead', '2025-11-19 12:03:28');

-- --------------------------------------------------------

--
-- Table structure for table `project_users`
--

CREATE TABLE `project_users` (
  `id` int(11) NOT NULL,
  `project_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `role_in_project` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `project_users`
--

INSERT INTO `project_users` (`id`, `project_id`, `user_id`, `role_in_project`) VALUES
(1, 2, 7, 'architect'),
(2, 2, 3, 'project_manager'),
(3, 3, 7, 'architect'),
(4, 3, 3, 'project_manager'),
(5, 2, 2, 'Architect'),
(6, 4, 7, 'architect'),
(7, 4, 3, 'project_manager'),
(8, 4, 2, 'Architect'),
(9, 5, 7, 'architect'),
(10, 5, 3, 'project_manager'),
(11, 5, 2, 'Architect'),
(12, 6, 7, 'architect'),
(13, 6, 3, 'project_manager'),
(14, 6, 2, 'Architect'),
(15, 7, 7, 'architect'),
(16, 7, 3, 'project_manager'),
(17, 8, 11, 'architect'),
(18, 8, 3, 'project_manager'),
(19, 9, 7, 'architect'),
(20, 10, 7, 'architect'),
(21, 11, 7, 'architect'),
(22, 12, 7, 'architect'),
(23, 12, 3, 'project_manager'),
(24, 13, 7, 'architect'),
(25, 14, 7, 'architect'),
(26, 15, 12, 'architect'),
(27, 16, 12, 'architect'),
(28, 17, 12, 'architect'),
(29, 17, 3, 'project_manager'),
(31, 10, 2, 'Architect'),
(32, 17, 2, 'Architect'),
(33, 7, 2, 'Architect'),
(34, 19, 12, 'architect'),
(35, 19, 3, 'project_manager'),
(36, 19, 2, 'Architect'),
(37, 20, 7, 'architect'),
(38, 20, 3, 'project_manager'),
(39, 20, 2, 'Architect'),
(40, 21, 7, 'architect'),
(41, 22, 11, 'architect'),
(42, 23, 11, 'architect'),
(43, 23, 3, 'project_manager'),
(44, 23, 2, 'Architect'),
(45, 24, 12, 'architect'),
(46, 24, 3, 'project_manager'),
(47, 25, 7, 'architect'),
(48, 25, 3, 'project_manager'),
(49, 26, 12, 'architect'),
(50, 26, 3, 'project_manager'),
(51, 27, 7, 'architect'),
(52, 27, 3, 'project_manager'),
(53, 28, 7, 'architect'),
(54, 28, 3, 'project_manager'),
(55, 29, 7, 'architect'),
(56, 29, 3, 'project_manager'),
(57, 30, 11, 'architect'),
(58, 30, 3, 'project_manager'),
(59, 30, 2, 'Architect'),
(60, 25, 2, 'Architect');

-- --------------------------------------------------------

--
-- Table structure for table `public_inquiries`
--

CREATE TABLE `public_inquiries` (
  `id` int(11) NOT NULL,
  `name` varchar(150) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `inquiry_type` varchar(100) DEFAULT NULL,
  `project_type` varchar(150) DEFAULT NULL,
  `budget_range` varchar(100) DEFAULT NULL,
  `message` text DEFAULT NULL,
  `location` varchar(255) DEFAULT NULL,
  `status` varchar(50) DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `assigned_to` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `public_inquiries`
--

INSERT INTO `public_inquiries` (`id`, `name`, `email`, `phone`, `inquiry_type`, `project_type`, `budget_range`, `message`, `location`, `status`, `created_at`, `assigned_to`) VALUES
(9, 'Jade Mark Vanilla', 'jade@gmail.com', NULL, 'Quote Request: commercial project (100 sqm)', NULL, NULL, 'Budget calculator estimate for a commercial project, area 100 sqm. Estimated total ₱5,282,640. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:36:59', NULL),
(10, 'Jade Mark Vanilla', 'jade@gmail.com', NULL, 'Quote Request: commercial project (100 sqm)', NULL, NULL, 'Budget calculator estimate for a commercial project, area 100 sqm. Estimated total ₱5,282,640. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:40:47', NULL),
(11, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:42:06', NULL),
(12, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:42:47', NULL),
(13, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:43:04', NULL),
(14, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:44:07', NULL),
(15, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 08:46:47', NULL),
(16, 'Jade Mark Vanilla', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 120 sqm. Estimated total ₱6,795,360. Please contact me for a detailed proposal.', NULL, 'in_review', '2025-11-11 08:47:30', 11),
(17, 'Alexandra Rama', 'kenuabadia@gmail.com', NULL, 'Quote Request: commercial project (120 sqm)', NULL, NULL, 'Budget calculator estimate for a commercial project, area 120 sqm. Estimated total ₱5,512,320. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 10:00:07', NULL),
(18, 'Alexandra Rama', 'kenuabadia@gmail.com', NULL, 'Quote Request: residential project (125 sqm)', NULL, NULL, 'Budget calculator estimate for a residential project, area 125 sqm. Estimated total ₱6,649,500. Please contact me for a detailed proposal.', NULL, 'new', '2025-11-11 11:26:31', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_id` int(10) UNSIGNED NOT NULL,
  `setting_name` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `suppliers`
--

CREATE TABLE `suppliers` (
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `supplier_name` varchar(200) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `supplier_type` enum('materials','services','equipment') NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `supplier_items`
--

CREATE TABLE `supplier_items` (
  `item_id` int(10) UNSIGNED NOT NULL,
  `supplier_id` int(10) UNSIGNED NOT NULL,
  `item_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `unit_price` decimal(12,2) DEFAULT NULL,
  `unit` varchar(20) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `task_id` int(10) UNSIGNED NOT NULL,
  `project_id` int(10) UNSIGNED NOT NULL,
  `assigned_to` int(10) UNSIGNED NOT NULL,
  `task_name` varchar(200) NOT NULL,
  `description` text DEFAULT NULL,
  `due_date` date DEFAULT NULL,
  `status` enum('Pending','In Progress','Under Review','Completed','Revise') DEFAULT 'Pending',
  `phase` enum('Pre-Design / Programming','Schematic Design (SD)','Design Development (DD)','Final Design') DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tasks`
--

INSERT INTO `tasks` (`task_id`, `project_id`, `assigned_to`, `task_name`, `description`, `due_date`, `status`, `phase`, `created_by`, `created_at`) VALUES
(28, 20, 1, 'Task', 'Task Description Example', '2025-11-01', 'Pending', 'Schematic Design (SD)', 3, '2025-10-29 09:49:48'),
(29, 23, 1, 'Task', 'TESTING', '2025-11-11', 'Revise', 'Pre-Design / Programming', 3, '2025-11-11 09:24:33');

-- --------------------------------------------------------

--
-- Table structure for table `task_files`
--

CREATE TABLE `task_files` (
  `file_id` int(11) NOT NULL,
  `task_id` int(11) NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by` int(11) NOT NULL,
  `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_files`
--

INSERT INTO `task_files` (`file_id`, `task_id`, `file_path`, `uploaded_by`, `uploaded_at`) VALUES
(13, 22, 'task_22_1759315751_68dd0727ec29d.jpeg', 2, '2025-10-01 10:49:11'),
(14, 23, 'task_23_1759469842_68df6112d37cb.jpeg', 2, '2025-10-03 05:37:22'),
(15, 25, 'task_25_1761313874_68fb8452e224a.jpeg', 2, '2025-10-24 13:51:14'),
(16, 26, 'task_26_1761376357_68fc7865ab5f0.jpeg', 2, '2025-10-25 07:12:37'),
(17, 28, 'task_28_1762853155_69130123e5e1d.jpeg', 2, '2025-11-11 09:25:55'),
(18, 29, 'task_29_1762853339_691301db6f4e0.jpeg', 2, '2025-11-11 09:28:59');

-- --------------------------------------------------------

--
-- Table structure for table `task_messages`
--

CREATE TABLE `task_messages` (
  `id` int(11) NOT NULL,
  `task_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `message` text NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_messages`
--

INSERT INTO `task_messages` (`id`, `task_id`, `user_id`, `message`, `created_at`) VALUES
(30, 28, 2, 'Here is my uploaded file.', '2025-11-11 17:25:50'),
(31, 29, 3, 'pls revise', '2025-11-11 17:40:45');

-- --------------------------------------------------------

--
-- Table structure for table `task_progress`
--

CREATE TABLE `task_progress` (
  `id` int(11) NOT NULL,
  `task_id` int(11) DEFAULT NULL,
  `contractor_id` int(11) DEFAULT NULL,
  `status` varchar(50) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `task_progress`
--

INSERT INTO `task_progress` (`id`, `task_id`, `contractor_id`, `status`, `notes`, `created_at`) VALUES
(1, 1, 18, 'In Progress', 'fuck u', '2025-08-27 04:23:23');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('admin','employee','hr','client','contractor') NOT NULL,
  `position` varchar(50) DEFAULT NULL,
  `first_name` varchar(100) NOT NULL,
  `last_name` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `profile_image` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `username`, `email`, `password`, `user_type`, `position`, `first_name`, `last_name`, `phone`, `address`, `profile_image`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@archiflow.com', '240be518fabd2724ddb6f04eeb1da5967448d7e831c08c8fa822809f74c720a9', 'admin', 'System Administrator', 'System', 'Administrator', '+63-917-123-4567', 'ArchiFlow Office, Iloilo City', NULL, 1, '2025-09-13 06:29:42'),
(2, 'SonnyDreke', 'Sonny@archiflow.com', '4a0eecfd190c926053428562e74a26acb1c6b971efb7d31aeaf9e7bdd7a1a8b1', 'employee', 'architect', 'Sonny', 'Dreke', '+63-917-234-5678', 'Iloilo City', NULL, 1, '2025-09-13 06:31:31'),
(3, 'Renato', 'renato@archiflow.com', '09914b29dcfd0933bd7b6a85314986253a6a7ef0f207bdc61c32da23b4bdb5aa', 'employee', 'Project Manager', 'Renato', 'Hernandez', '+63-917-345-6789', 'Iloilo City', NULL, 1, '2025-09-13 06:31:31'),
(4, 'Kenu', 'kenu@archiflow.com', '427fcab2b48c462dbf4227081a44e54eb5d6f0b7788e4593c7864f0c6a271486', 'hr', 'HR Manager', 'Kenu', 'Rodriguez', '+63-917-456-7890', 'Iloilo City', NULL, 1, '2025-09-13 06:31:31'),
(5, 'Carl', 'carl@archiflow.com', '1665602a2092f0a3d5de77495d54e1977461cb1e3dea04b587cc9c4e5edef9df', 'client', NULL, 'Carl', 'Thompson', '+63-917-567-8901', 'Iloilo City', NULL, 1, '2025-09-13 06:31:31'),
(7, 'Raven', 'dianon@archiflow.com', '839f3417700e8485b87a78ccff83766afbfd11520506677308cd81a13f6ef1ba', 'employee', 'Senior Architect', 'Raven', 'Dianon', '+63-917-789-0123', 'Iloilo City', '????\0JFIF\0\0`\0`\0\0??\0?\0				\r\r\n\Z!\'\"#%%%),($+!$%$				$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$$??\0?\n\0\"\0??\06\0\0\0\0\0\0\0\0\0\0\0\0	\0\0\0\0\0\0\0\0\0??\0\0\0\0\0??\0\0\0\0\0\0\0\0\0\0', 1, '2025-09-13 06:31:31'),
(9, 'Alexandra', 'alexandra@gmail.com', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'client', NULL, 'Alexa', 'Rama', '09668259553', 'Talisay', 'uploads/avatars/9/avatar_9_dc0a0653.jpg', 1, '2025-10-11 08:26:47'),
(11, 'Dabedbiot', 'dabed@gmail.com', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'employee', 'senior_architect', 'David', 'Baylosis', '123', '123', NULL, 1, '2025-10-17 05:10:15'),
(12, 'Enna', 'enna@gmail.com', 'a665a45920422f9d417e4867efdc4fb8a04a1f3fff1fa07e998e86f7f7a27ae3', 'employee', 'senior_architect', 'Anne', 'Cans', '123123', '123123', NULL, 1, '2025-10-19 13:07:41'),
(13, 'Alexa', 'kenuabadia@gmail.com', 'a54a55a53b28e09229ec6bfe59b3532bdd471f6aadbee3901d451126ae8c618e', 'client', NULL, 'Alexandra ', 'Rama', '09668259553 ', 'Countryside Village Lipata Minglanilla', NULL, 1, '2025-11-11 10:03:29');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `attendance`
--
ALTER TABLE `attendance`
  ADD PRIMARY KEY (`attendance_id`),
  ADD KEY `attendance_ibfk_1` (`employee_id`);

--
-- Indexes for table `attendance_corrections`
--
ALTER TABLE `attendance_corrections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `employee_id` (`employee_id`),
  ADD KEY `status` (`status`),
  ADD KEY `work_date` (`work_date`);

--
-- Indexes for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  ADD PRIMARY KEY (`log_id`),
  ADD KEY `idx_emp_date` (`employee_id`,`work_date`),
  ADD KEY `idx_attendance` (`attendance_id`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `actor_user_id` (`actor_user_id`),
  ADD KEY `entity_type` (`entity_type`),
  ADD KEY `created_at` (`created_at`);

--
-- Indexes for table `chat`
--
ALTER TABLE `chat`
  ADD PRIMARY KEY (`chat_id`),
  ADD UNIQUE KEY `uniq_client_sa` (`client_id`,`senior_architect_id`),
  ADD KEY `idx_sa` (`senior_architect_id`),
  ADD KEY `idx_last` (`last_message_at`);

--
-- Indexes for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `idx_chat` (`chat_id`),
  ADD KEY `idx_chat_time` (`chat_id`,`sent_at`);

--
-- Indexes for table `clients`
--
ALTER TABLE `clients`
  ADD PRIMARY KEY (`client_id`),
  ADD KEY `clients_ibfk_1` (`user_id`);

--
-- Indexes for table `client_inquiries`
--
ALTER TABLE `client_inquiries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `contractors`
--
ALTER TABLE `contractors`
  ADD PRIMARY KEY (`contractor_id`),
  ADD KEY `contractors_ibfk_1` (`user_id`);

--
-- Indexes for table `contractor_updates`
--
ALTER TABLE `contractor_updates`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `contractor_id` (`contractor_id`);

--
-- Indexes for table `contracts`
--
ALTER TABLE `contracts`
  ADD PRIMARY KEY (`contract_id`),
  ADD KEY `contracts_ibfk_1` (`project_id`),
  ADD KEY `contracts_ibfk_2` (`client_id`);

--
-- Indexes for table `design_reviews`
--
ALTER TABLE `design_reviews`
  ADD PRIMARY KEY (`review_id`),
  ADD KEY `idx_dr_project` (`project_id`),
  ADD KEY `idx_dr_milestone` (`milestone_id`),
  ADD KEY `idx_dr_document` (`document_id`),
  ADD KEY `idx_dr_reviewer` (`reviewer_id`);

--
-- Indexes for table `design_services`
--
ALTER TABLE `design_services`
  ADD PRIMARY KEY (`service_id`);

--
-- Indexes for table `dm`
--
ALTER TABLE `dm`
  ADD PRIMARY KEY (`dm_id`),
  ADD UNIQUE KEY `uniq_pair` (`user_one_id`,`user_two_id`);

--
-- Indexes for table `dm_messages`
--
ALTER TABLE `dm_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dm` (`dm_id`),
  ADD KEY `idx_dm_time` (`dm_id`,`sent_at`);

--
-- Indexes for table `documents`
--
ALTER TABLE `documents`
  ADD PRIMARY KEY (`document_id`),
  ADD KEY `documents_ibfk_1` (`project_id`),
  ADD KEY `documents_ibfk_2` (`uploaded_by`);

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`employee_id`),
  ADD KEY `employees_ibfk_1` (`user_id`);

--
-- Indexes for table `employee_bank_change_requests`
--
ALTER TABLE `employee_bank_change_requests`
  ADD PRIMARY KEY (`request_id`),
  ADD KEY `idx_emp` (`employee_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`invoice_id`),
  ADD KEY `invoices_ibfk_1` (`project_id`),
  ADD KEY `invoices_ibfk_2` (`client_id`);

--
-- Indexes for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD PRIMARY KEY (`leave_id`),
  ADD KEY `leave_requests_ibfk_1` (`employee_id`);

--
-- Indexes for table `materials`
--
ALTER TABLE `materials`
  ADD PRIMARY KEY (`material_id`),
  ADD KEY `category_id` (`category_id`),
  ADD KEY `is_active` (`is_active`);

--
-- Indexes for table `material_categories`
--
ALTER TABLE `material_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `messages`
--
ALTER TABLE `messages`
  ADD PRIMARY KEY (`message_id`),
  ADD KEY `messages_ibfk_1` (`project_id`),
  ADD KEY `messages_ibfk_2` (`sender_id`),
  ADD KEY `messages_ibfk_3` (`recipient_id`);

--
-- Indexes for table `milestones`
--
ALTER TABLE `milestones`
  ADD PRIMARY KEY (`milestone_id`),
  ADD KEY `milestones_ibfk_1` (`project_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `notifications_ibfk_1` (`user_id`);

--
-- Indexes for table `payroll`
--
ALTER TABLE `payroll`
  ADD PRIMARY KEY (`payroll_id`),
  ADD KEY `payroll_ibfk_1` (`employee_id`);

--
-- Indexes for table `pm_senior_files`
--
ALTER TABLE `pm_senior_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_phase` (`design_phase`),
  ADD KEY `idx_uploaded_at` (`uploaded_at`),
  ADD KEY `idx_status` (`status`);

--
-- Indexes for table `pm_senior_file_comments`
--
ALTER TABLE `pm_senior_file_comments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_file` (`file_id`),
  ADD KEY `idx_created` (`created_at`);

--
-- Indexes for table `projects`
--
ALTER TABLE `projects`
  ADD PRIMARY KEY (`project_id`),
  ADD KEY `projects_ibfk_1` (`client_id`),
  ADD KEY `projects_ibfk_2` (`architect_id`),
  ADD KEY `projects_ibfk_3` (`project_manager_id`);

--
-- Indexes for table `project_client_review_files`
--
ALTER TABLE `project_client_review_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_pcrf_project` (`project_id`),
  ADD KEY `idx_pcrf_project_status` (`project_id`,`review_status`),
  ADD KEY `idx_pcrf_group` (`project_id`,`group_token`),
  ADD KEY `idx_pcrf_project_version` (`project_id`,`version`);

--
-- Indexes for table `project_client_review_file_messages`
--
ALTER TABLE `project_client_review_file_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_review_file` (`review_file_id`),
  ADD KEY `idx_project` (`project_id`),
  ADD KEY `idx_author` (`author_user_id`),
  ADD KEY `idx_action` (`action`);

--
-- Indexes for table `project_contractors`
--
ALTER TABLE `project_contractors`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `fk_project_contractors_user` (`contractor_id`);

--
-- Indexes for table `project_estimates`
--
ALTER TABLE `project_estimates`
  ADD PRIMARY KEY (`estimate_id`),
  ADD KEY `project_estimates_ibfk_1` (`project_id`),
  ADD KEY `project_estimates_ibfk_2` (`service_id`);

--
-- Indexes for table `project_files`
--
ALTER TABLE `project_files`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `uploaded_by` (`uploaded_by`);

--
-- Indexes for table `project_materials`
--
ALTER TABLE `project_materials`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `material_id` (`material_id`),
  ADD KEY `added_by` (`added_by`);

--
-- Indexes for table `project_requests`
--
ALTER TABLE `project_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `client_id` (`client_id`),
  ADD KEY `senior_architect_id` (`senior_architect_id`);

--
-- Indexes for table `project_senior_architects`
--
ALTER TABLE `project_senior_architects`
  ADD PRIMARY KEY (`psa_id`),
  ADD UNIQUE KEY `unique_assignment` (`project_id`,`employee_id`),
  ADD KEY `psa_project_fk` (`project_id`),
  ADD KEY `psa_employee_fk` (`employee_id`);

--
-- Indexes for table `project_users`
--
ALTER TABLE `project_users`
  ADD PRIMARY KEY (`id`),
  ADD KEY `project_id` (`project_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `public_inquiries`
--
ALTER TABLE `public_inquiries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`supplier_id`);

--
-- Indexes for table `supplier_items`
--
ALTER TABLE `supplier_items`
  ADD PRIMARY KEY (`item_id`),
  ADD KEY `supplier_items_ibfk_1` (`supplier_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `tasks_ibfk_1` (`project_id`),
  ADD KEY `tasks_ibfk_2` (`assigned_to`);

--
-- Indexes for table `task_files`
--
ALTER TABLE `task_files`
  ADD PRIMARY KEY (`file_id`);

--
-- Indexes for table `task_messages`
--
ALTER TABLE `task_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `task_progress`
--
ALTER TABLE `task_progress`
  ADD PRIMARY KEY (`id`),
  ADD KEY `task_id` (`task_id`),
  ADD KEY `contractor_id` (`contractor_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `attendance`
--
ALTER TABLE `attendance`
  MODIFY `attendance_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `attendance_corrections`
--
ALTER TABLE `attendance_corrections`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `attendance_logs`
--
ALTER TABLE `attendance_logs`
  MODIFY `log_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chat`
--
ALTER TABLE `chat`
  MODIFY `chat_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `chat_messages`
--
ALTER TABLE `chat_messages`
  MODIFY `message_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

--
-- AUTO_INCREMENT for table `clients`
--
ALTER TABLE `clients`
  MODIFY `client_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `client_inquiries`
--
ALTER TABLE `client_inquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `contractors`
--
ALTER TABLE `contractors`
  MODIFY `contractor_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contractor_updates`
--
ALTER TABLE `contractor_updates`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `contracts`
--
ALTER TABLE `contracts`
  MODIFY `contract_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_reviews`
--
ALTER TABLE `design_reviews`
  MODIFY `review_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `design_services`
--
ALTER TABLE `design_services`
  MODIFY `service_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dm`
--
ALTER TABLE `dm`
  MODIFY `dm_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=21;

--
-- AUTO_INCREMENT for table `dm_messages`
--
ALTER TABLE `dm_messages`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=40;

--
-- AUTO_INCREMENT for table `documents`
--
ALTER TABLE `documents`
  MODIFY `document_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `employee_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `employee_bank_change_requests`
--
ALTER TABLE `employee_bank_change_requests`
  MODIFY `request_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `invoice_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `leave_requests`
--
ALTER TABLE `leave_requests`
  MODIFY `leave_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `materials`
--
ALTER TABLE `materials`
  MODIFY `material_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `material_categories`
--
ALTER TABLE `material_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `messages`
--
ALTER TABLE `messages`
  MODIFY `message_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `milestones`
--
ALTER TABLE `milestones`
  MODIFY `milestone_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- AUTO_INCREMENT for table `payroll`
--
ALTER TABLE `payroll`
  MODIFY `payroll_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `pm_senior_files`
--
ALTER TABLE `pm_senior_files`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `pm_senior_file_comments`
--
ALTER TABLE `pm_senior_file_comments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `projects`
--
ALTER TABLE `projects`
  MODIFY `project_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=31;

--
-- AUTO_INCREMENT for table `project_client_review_files`
--
ALTER TABLE `project_client_review_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `project_client_review_file_messages`
--
ALTER TABLE `project_client_review_file_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=20;

--
-- AUTO_INCREMENT for table `project_contractors`
--
ALTER TABLE `project_contractors`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `project_estimates`
--
ALTER TABLE `project_estimates`
  MODIFY `estimate_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `project_files`
--
ALTER TABLE `project_files`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `project_materials`
--
ALTER TABLE `project_materials`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `project_requests`
--
ALTER TABLE `project_requests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `project_senior_architects`
--
ALTER TABLE `project_senior_architects`
  MODIFY `psa_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `project_users`
--
ALTER TABLE `project_users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `public_inquiries`
--
ALTER TABLE `public_inquiries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `settings`
--
ALTER TABLE `settings`
  MODIFY `setting_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `supplier_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `supplier_items`
--
ALTER TABLE `supplier_items`
  MODIFY `item_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `task_files`
--
ALTER TABLE `task_files`
  MODIFY `file_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `task_messages`
--
ALTER TABLE `task_messages`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `task_progress`
--
ALTER TABLE `task_progress`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `attendance`
--
ALTER TABLE `attendance`
  ADD CONSTRAINT `attendance_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `chat_messages`
--
ALTER TABLE `chat_messages`
  ADD CONSTRAINT `fk_chat_messages_chat` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`chat_id`) ON DELETE CASCADE;

--
-- Constraints for table `clients`
--
ALTER TABLE `clients`
  ADD CONSTRAINT `clients_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `contractors`
--
ALTER TABLE `contractors`
  ADD CONSTRAINT `contractors_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `contracts`
--
ALTER TABLE `contracts`
  ADD CONSTRAINT `contracts_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `contracts_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`);

--
-- Constraints for table `design_reviews`
--
ALTER TABLE `design_reviews`
  ADD CONSTRAINT `dr_document_fk` FOREIGN KEY (`document_id`) REFERENCES `documents` (`document_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `dr_milestone_fk` FOREIGN KEY (`milestone_id`) REFERENCES `milestones` (`milestone_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `dr_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `dr_reviewer_fk` FOREIGN KEY (`reviewer_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE;

--
-- Constraints for table `dm_messages`
--
ALTER TABLE `dm_messages`
  ADD CONSTRAINT `fk_dm_messages_dm` FOREIGN KEY (`dm_id`) REFERENCES `dm` (`dm_id`) ON DELETE CASCADE;

--
-- Constraints for table `documents`
--
ALTER TABLE `documents`
  ADD CONSTRAINT `documents_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `documents_ibfk_2` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `employees`
--
ALTER TABLE `employees`
  ADD CONSTRAINT `employees_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `invoices`
--
ALTER TABLE `invoices`
  ADD CONSTRAINT `invoices_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `invoices_ibfk_2` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`);

--
-- Constraints for table `leave_requests`
--
ALTER TABLE `leave_requests`
  ADD CONSTRAINT `leave_requests_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `messages`
--
ALTER TABLE `messages`
  ADD CONSTRAINT `messages_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `messages_ibfk_2` FOREIGN KEY (`sender_id`) REFERENCES `users` (`user_id`),
  ADD CONSTRAINT `messages_ibfk_3` FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `milestones`
--
ALTER TABLE `milestones`
  ADD CONSTRAINT `milestones_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`);

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`);

--
-- Constraints for table `payroll`
--
ALTER TABLE `payroll`
  ADD CONSTRAINT `payroll_ibfk_1` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `pm_senior_files`
--
ALTER TABLE `pm_senior_files`
  ADD CONSTRAINT `fk_pm_senior_files_project` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `projects`
--
ALTER TABLE `projects`
  ADD CONSTRAINT `projects_ibfk_1` FOREIGN KEY (`client_id`) REFERENCES `clients` (`client_id`),
  ADD CONSTRAINT `projects_ibfk_2` FOREIGN KEY (`architect_id`) REFERENCES `employees` (`employee_id`),
  ADD CONSTRAINT `projects_ibfk_3` FOREIGN KEY (`project_manager_id`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `project_estimates`
--
ALTER TABLE `project_estimates`
  ADD CONSTRAINT `project_estimates_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `project_estimates_ibfk_2` FOREIGN KEY (`service_id`) REFERENCES `design_services` (`service_id`);

--
-- Constraints for table `project_senior_architects`
--
ALTER TABLE `project_senior_architects`
  ADD CONSTRAINT `psa_employee_fk` FOREIGN KEY (`employee_id`) REFERENCES `employees` (`employee_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `psa_project_fk` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `supplier_items`
--
ALTER TABLE `supplier_items`
  ADD CONSTRAINT `supplier_items_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`supplier_id`);

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`project_id`) REFERENCES `projects` (`project_id`),
  ADD CONSTRAINT `tasks_ibfk_2` FOREIGN KEY (`assigned_to`) REFERENCES `employees` (`employee_id`);

--
-- Constraints for table `task_messages`
--
ALTER TABLE `task_messages`
  ADD CONSTRAINT `task_messages_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `task_messages_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
