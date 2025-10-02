-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 02, 2025 at 10:01 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.3.6

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `pill-reminder`
--

-- --------------------------------------------------------

--
-- Table structure for table `app`
--

CREATE TABLE `app` (
  `app_id` int(11) NOT NULL,
  `pill_slot` int(11) NOT NULL,
  `connect_id` int(11) NOT NULL,
  `status` int(11) NOT NULL,
  `timing` time NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `connect`
--

CREATE TABLE `connect` (
  `connect_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `machine_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `day_app`
--

CREATE TABLE `day_app` (
  `day_app_id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `sunday` tinyint(1) DEFAULT NULL,
  `monday` tinyint(1) DEFAULT NULL,
  `tuesday` tinyint(1) DEFAULT NULL,
  `wednesday` tinyint(1) DEFAULT NULL,
  `thursday` tinyint(1) DEFAULT NULL,
  `friday` tinyint(1) DEFAULT NULL,
  `saturday` tinyint(1) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dosage_form`
--

CREATE TABLE `dosage_form` (
  `dosage_form_id` int(11) NOT NULL,
  `dosage_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `machinesn`
--

CREATE TABLE `machinesn` (
  `machine_id` int(11) NOT NULL,
  `machine_SN` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medication`
--

CREATE TABLE `medication` (
  `medication_id` int(11) NOT NULL,
  `connect_id` int(11) NOT NULL,
  `medication_nickname` varchar(255) NOT NULL,
  `medication_name` varchar(255) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `picture` varchar(255) DEFAULT NULL,
  `dosage_form_id` int(11) NOT NULL,
  `unit_type_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medication_link`
--

CREATE TABLE `medication_link` (
  `medication_link_id` int(11) NOT NULL,
  `app_id` int(11) NOT NULL,
  `medication_id` int(11) NOT NULL,
  `amount` decimal(3,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medication_snapshot`
--

CREATE TABLE `medication_snapshot` (
  `medication_snapshot_id` int(11) NOT NULL,
  `reminder_medical_id` int(11) DEFAULT NULL,
  `medication_name` varchar(255) DEFAULT NULL,
  `amount_taken` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `medication_timing`
--

CREATE TABLE `medication_timing` (
  `medication_timing_id` int(11) NOT NULL,
  `medication_id` int(11) NOT NULL,
  `timing_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reminder_medication`
--

CREATE TABLE `reminder_medication` (
  `reminder_medical_id` int(11) NOT NULL,
  `connect_id` int(11) NOT NULL,
  `day` varchar(255) DEFAULT NULL,
  `receive_time` datetime DEFAULT NULL,
  `time` datetime DEFAULT NULL,
  `status` varchar(255) DEFAULT NULL,
  `timing_id` int(11) NOT NULL,
  `picture` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timing`
--

CREATE TABLE `timing` (
  `timing_id` int(11) NOT NULL,
  `timing` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO `timing` (`timing_id`, `timing`) VALUES
(1, 'มื้อเช้าก่อนอาหาร'),
(2, 'มื้อเช้าหลังอาหาร'),
(3, 'มื้อกลางวันก่อนอาหาร'),
(4, 'มื้อกลางวันหลังอาหาร'),
(5, 'มื้อเย็นก่อนอาหาร'),
(6, 'มื้อเย็นหลังอาหาร'),
(7, 'ก่อนนอน');
-- --------------------------------------------------------

--
-- Table structure for table `unit_type`
--

CREATE TABLE `unit_type` (
  `unit_type_id` int(11) NOT NULL,
  `dosage_form_id` int(11) NOT NULL,
  `unit_type_name` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `user_id` int(11) NOT NULL,
  `user` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `user_otp`
--

CREATE TABLE `user_otp` (
  `user_otp_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `otp_code` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `expires_at` timestamp NULL DEFAULT NULL,
  `failed_attempts` int(11) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `volume`
--

CREATE TABLE `volume` (
  `volume_id` int(11) NOT NULL,
  `volume` varchar(255) DEFAULT NULL,
  `delay` time DEFAULT NULL,
  `alert_offset` time DEFAULT NULL,
  `connect_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `app`
--
ALTER TABLE `app`
  ADD PRIMARY KEY (`app_id`),
  ADD KEY `idx_app_connect` (`connect_id`);

--
-- Indexes for table `connect`
--
ALTER TABLE `connect`
  ADD PRIMARY KEY (`connect_id`),
  ADD KEY `idx_connect_user` (`user_id`),
  ADD KEY `idx_connect_machine` (`machine_id`);

--
-- Indexes for table `day_app`
--
ALTER TABLE `day_app`
  ADD PRIMARY KEY (`day_app_id`),
  ADD KEY `app_id` (`app_id`);

--
-- Indexes for table `dosage_form`
--
ALTER TABLE `dosage_form`
  ADD PRIMARY KEY (`dosage_form_id`);

--
-- Indexes for table `machinesn`
--
ALTER TABLE `machinesn`
  ADD PRIMARY KEY (`machine_id`),
  ADD KEY `idx_machine_sn` (`machine_SN`);

--
-- Indexes for table `medication`
--
ALTER TABLE `medication`
  ADD PRIMARY KEY (`medication_id`),
  ADD KEY `dosage_form_id` (`dosage_form_id`),
  ADD KEY `idx_medication_connect` (`connect_id`),
  ADD KEY `fk_medication_unit_type` (`unit_type_id`);

--
-- Indexes for table `medication_link`
--
ALTER TABLE `medication_link`
  ADD PRIMARY KEY (`medication_link_id`),
  ADD KEY `medication_id` (`medication_id`),
  ADD KEY `fk_medication_link_app` (`app_id`);

--
-- Indexes for table `medication_snapshot`
--
ALTER TABLE `medication_snapshot`
  ADD PRIMARY KEY (`medication_snapshot_id`),
  ADD KEY `fk_medication_keep_reminder` (`reminder_medical_id`);

--
-- Indexes for table `medication_timing`
--
ALTER TABLE `medication_timing`
  ADD PRIMARY KEY (`medication_timing_id`),
  ADD KEY `idx_medication_timing_medication` (`medication_id`),
  ADD KEY `idx_medication_timing_timing` (`timing_id`);

--
-- Indexes for table `reminder_medication`
--
ALTER TABLE `reminder_medication`
  ADD PRIMARY KEY (`reminder_medical_id`),
  ADD KEY `idx_reminder_medication_connect` (`connect_id`),
  ADD KEY `idx_reminder_medication_timing` (`timing_id`);

--
-- Indexes for table `timing`
--
ALTER TABLE `timing`
  ADD PRIMARY KEY (`timing_id`);

--
-- Indexes for table `unit_type`
--
ALTER TABLE `unit_type`
  ADD PRIMARY KEY (`unit_type_id`),
  ADD KEY `dosage_form_id` (`dosage_form_id`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`),
  ADD KEY `idx_user_user` (`user`);

--
-- Indexes for table `user_otp`
--
ALTER TABLE `user_otp`
  ADD PRIMARY KEY (`user_otp_id`),
  ADD KEY `idx_user_otp_user_id` (`user_id`),
  ADD KEY `idx_user_otp_code` (`otp_code`),
  ADD KEY `idx_user_otp_expires` (`expires_at`),
  ADD KEY `idx_user_otp_active` (`user_id`,`expires_at`),
  ADD KEY `idx_user_otp_cleanup` (`expires_at`);

--
-- Indexes for table `volume`
--
ALTER TABLE `volume`
  ADD PRIMARY KEY (`volume_id`),
  ADD KEY `connect_id` (`connect_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `app`
--
ALTER TABLE `app`
  MODIFY `app_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `connect`
--
ALTER TABLE `connect`
  MODIFY `connect_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `day_app`
--
ALTER TABLE `day_app`
  MODIFY `day_app_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `dosage_form`
--
ALTER TABLE `dosage_form`
  MODIFY `dosage_form_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `machinesn`
--
ALTER TABLE `machinesn`
  MODIFY `machine_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medication`
--
ALTER TABLE `medication`
  MODIFY `medication_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medication_link`
--
ALTER TABLE `medication_link`
  MODIFY `medication_link_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medication_snapshot`
--
ALTER TABLE `medication_snapshot`
  MODIFY `medication_snapshot_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `medication_timing`
--
ALTER TABLE `medication_timing`
  MODIFY `medication_timing_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reminder_medication`
--
ALTER TABLE `reminder_medication`
  MODIFY `reminder_medical_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timing`
--
ALTER TABLE `timing`
  MODIFY `timing_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `unit_type`
--
ALTER TABLE `unit_type`
  MODIFY `unit_type_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `user_otp`
--
ALTER TABLE `user_otp`
  MODIFY `user_otp_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `volume`
--
ALTER TABLE `volume`
  MODIFY `volume_id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `app`
--
ALTER TABLE `app`
  ADD CONSTRAINT `app_ibfk_1` FOREIGN KEY (`connect_id`) REFERENCES `connect` (`connect_id`) ON DELETE CASCADE;

--
-- Constraints for table `connect`
--
ALTER TABLE `connect`
  ADD CONSTRAINT `connect_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`),
  ADD CONSTRAINT `connect_ibfk_2` FOREIGN KEY (`machine_id`) REFERENCES `machinesn` (`machine_id`);

--
-- Constraints for table `day_app`
--
ALTER TABLE `day_app`
  ADD CONSTRAINT `day_app_ibfk_1` FOREIGN KEY (`app_id`) REFERENCES `app` (`app_id`) ON DELETE CASCADE;

--
-- Constraints for table `medication`
--
ALTER TABLE `medication`
  ADD CONSTRAINT `fk_medication_unit_type` FOREIGN KEY (`unit_type_id`) REFERENCES `unit_type` (`unit_type_id`),
  ADD CONSTRAINT `medication_ibfk_1` FOREIGN KEY (`connect_id`) REFERENCES `connect` (`connect_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `medication_ibfk_2` FOREIGN KEY (`dosage_form_id`) REFERENCES `dosage_form` (`dosage_form_id`);

--
-- Constraints for table `medication_link`
--
ALTER TABLE `medication_link`
  ADD CONSTRAINT `medication_link_ibfk_1` FOREIGN KEY (`medication_id`) REFERENCES `medication` (`medication_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `medication_link_ibfk_2` FOREIGN KEY (`app_id`) REFERENCES `app` (`app_id`) ON DELETE CASCADE;

--
-- Constraints for table `medication_snapshot`
--
ALTER TABLE `medication_snapshot`
  ADD CONSTRAINT `medication_snapshot_ibfk_1` FOREIGN KEY (`reminder_medical_id`) REFERENCES `reminder_medication` (`reminder_medical_id`) ON DELETE CASCADE;

--
-- Constraints for table `medication_timing`
--
ALTER TABLE `medication_timing`
  ADD CONSTRAINT `medication_timing_ibfk_1` FOREIGN KEY (`medication_id`) REFERENCES `medication` (`medication_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `medication_timing_ibfk_2` FOREIGN KEY (`timing_id`) REFERENCES `timing` (`timing_id`);

--
-- Constraints for table `reminder_medication`
--
ALTER TABLE `reminder_medication`
  ADD CONSTRAINT `reminder_medication_ibfk_1` FOREIGN KEY (`connect_id`) REFERENCES `connect` (`connect_id`) ON DELETE CASCADE,
  ADD CONSTRAINT `reminder_medication_ibfk_2` FOREIGN KEY (`timing_id`) REFERENCES `timing` (`timing_id`);

--
-- Constraints for table `unit_type`
--
ALTER TABLE `unit_type`
  ADD CONSTRAINT `unit_type_ibfk_1` FOREIGN KEY (`dosage_form_id`) REFERENCES `dosage_form` (`dosage_form_id`);

--
-- Constraints for table `user_otp`
--
ALTER TABLE `user_otp`
  ADD CONSTRAINT `fk_user_otp_user_id` FOREIGN KEY (`user_id`) REFERENCES `user` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `volume`
--
ALTER TABLE `volume`
  ADD CONSTRAINT `volume_ibfk_1` FOREIGN KEY (`connect_id`) REFERENCES `connect` (`connect_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
