-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 13, 2025 at 11:08 AM
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
-- Database: `qms`
--

-- --------------------------------------------------------

--
-- Table structure for table `departments`
--

CREATE TABLE `departments` (
  `id` int(11) NOT NULL,
  `dept_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `departments`
--

INSERT INTO `departments` (`id`, `dept_name`, `created_at`) VALUES
(39, '2D Echo', '2025-10-25 07:44:51'),
(40, 'Biochemistry', '2025-10-25 07:44:51'),
(41, 'BMD', '2025-10-25 07:44:51'),
(42, 'C.T', '2025-10-25 07:44:51'),
(43, 'CBCT', '2025-10-25 07:44:51'),
(44, 'Consultation', '2025-10-25 07:44:51'),
(45, 'CT', '2025-10-25 07:44:51'),
(46, 'Cytology', '2025-10-25 07:44:51'),
(47, 'ECG', '2025-10-25 07:44:51'),
(48, 'EEG', '2025-10-25 07:44:51'),
(49, 'ENDOSCOPY', '2025-10-25 07:44:51'),
(50, 'ENMG', '2025-10-25 07:44:51'),
(52, 'Genetic', '2025-10-25 07:44:51'),
(53, 'Haematology', '2025-10-25 07:44:51'),
(54, 'Histopathology', '2025-10-25 07:44:51'),
(55, 'M.R.I', '2025-10-25 07:44:51'),
(56, 'Mamography', '2025-10-25 07:44:51'),
(57, 'MICROBIOLOGY', '2025-10-25 07:44:51'),
(58, 'Molecular Biology', '2025-10-25 07:44:51'),
(59, 'MRI', '2025-10-25 07:44:51'),
(60, 'OPG', '2025-10-25 07:44:51'),
(61, 'others', '2025-10-25 07:44:51'),
(62, 'Out source', '2025-10-25 07:44:51'),
(63, 'Pathology', '2025-10-25 07:44:51'),
(64, 'PFT', '2025-10-25 07:44:51'),
(65, 'Physio', '2025-10-25 07:44:51'),
(66, 'PTA', '2025-10-25 07:44:51'),
(67, 'Sample Collection', '2025-10-25 07:44:51'),
(68, 'Serology', '2025-10-25 07:44:51'),
(69, 'SURGEON CONSULTATION', '2025-10-25 07:44:51'),
(70, 'TMT', '2025-10-25 07:44:51'),
(71, 'UltraSound', '2025-10-25 07:44:51'),
(72, 'UROFLOMETRY', '2025-10-25 07:44:51'),
(73, 'Xray', '2025-10-25 07:44:51'),
(74, 'LAB', '2025-10-25 08:02:47');

-- --------------------------------------------------------

--
-- Table structure for table `popup_notifications`
--

CREATE TABLE `popup_notifications` (
  `id` int(11) NOT NULL,
  `token_id` int(11) NOT NULL,
  `dept_id` int(11) NOT NULL,
  `token_number` varchar(20) NOT NULL,
  `pat_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tokens`
--

CREATE TABLE `tokens` (
  `id` int(11) NOT NULL,
  `sid_no` varchar(50) NOT NULL,
  `pat_name` varchar(100) NOT NULL,
  `pat_age` int(11) DEFAULT NULL,
  `pat_sex` varchar(10) DEFAULT NULL,
  `ref_name` varchar(100) DEFAULT NULL,
  `dept_id` int(11) NOT NULL,
  `token_number` varchar(20) NOT NULL,
  `status` enum('pending','called','completed') DEFAULT 'pending',
  `accepted_date` datetime DEFAULT NULL,
  `created_date` date NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `countdown_duration` int(11) DEFAULT NULL COMMENT 'Time in seconds between accept and complete',
  `completed_date` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tokens`
--

INSERT INTO `tokens` (`id`, `sid_no`, `pat_name`, `pat_age`, `pat_sex`, `ref_name`, `dept_id`, `token_number`, `status`, `accepted_date`, `created_date`, `created_at`, `countdown_duration`, `completed_date`) VALUES
(4, '053720', 'Mrs. ARUNA.T 50 Y/F', 50, 'F', 'DR.RAVI SHANKAR.B MD,DNB,DM', 71, 'UL-001', 'completed', NULL, '2025-10-25', '2025-10-25 07:48:46', NULL, '2025-10-25 17:20:29'),
(65, '058607', 'Mr. SAI SOHAN CH 17 Y/M', 17, 'M', 'STATE BANK OF INDIA (AMARAVATHI CIRCLE) CREDIT ', 74, 'LA-004', 'completed', '2025-11-13 15:22:28', '2025-11-13', '2025-11-13 08:43:10', NULL, '2025-11-13 15:24:08');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('admin','department_user','admin1') NOT NULL,
  `department_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `password`, `role`, `department_id`, `created_at`) VALUES
(1, 'admin', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'admin1', NULL, '2025-10-25 07:28:14'),
(2, 'lab_user', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 74, '2025-10-25 08:10:28'),
(3, 'xray', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 73, '2025-10-25 08:42:14'),
(4, 'token', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'admin', NULL, '2025-10-25 11:07:24'),
(5, 'echo', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 39, '2025-10-25 11:28:40'),
(6, 'ecg', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 47, '2025-10-25 11:45:57'),
(7, 'usg', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 71, '2025-10-25 11:47:40'),
(9, 'ct', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 45, '2025-10-27 06:29:47'),
(10, 'mri', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 55, '2025-11-13 09:54:52'),
(11, 'tmt', '$2y$10$30zTQu2xYUk/m1atCPfNNOdmbK0R0Q5TkHlUGnbgNTHlDNdKvsUdS', 'department_user', 70, '2025-11-13 09:55:09');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `departments`
--
ALTER TABLE `departments`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `dept_name` (`dept_name`);

--
-- Indexes for table `popup_notifications`
--
ALTER TABLE `popup_notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `token_id` (`token_id`),
  ADD KEY `dept_id` (`dept_id`);

--
-- Indexes for table `tokens`
--
ALTER TABLE `tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `dept_id` (`dept_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `departments`
--
ALTER TABLE `departments`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=75;

--
-- AUTO_INCREMENT for table `popup_notifications`
--
ALTER TABLE `popup_notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=113;

--
-- AUTO_INCREMENT for table `tokens`
--
ALTER TABLE `tokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=66;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `popup_notifications`
--
ALTER TABLE `popup_notifications`
  ADD CONSTRAINT `popup_notifications_ibfk_2` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`);

--
-- Constraints for table `tokens`
--
ALTER TABLE `tokens`
  ADD CONSTRAINT `tokens_ibfk_1` FOREIGN KEY (`dept_id`) REFERENCES `departments` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
