-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 06, 2026 at 08:15 AM
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
-- Database: `votingsystem`
--

-- --------------------------------------------------------

--
-- Table structure for table `employees`
--

CREATE TABLE `employees` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(80) NOT NULL,
  `department` varchar(80) NOT NULL,
  `gender` enum('male','female') NOT NULL,
  `code` char(6) NOT NULL,
  `created_at` datetime NOT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `device_token` char(32) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `employees`
--

INSERT INTO `employees` (`id`, `name`, `department`, `gender`, `code`, `created_at`, `confirmed_at`, `device_token`) VALUES
(32, 'Fareast Kholin', 'Digital Operations Department', 'male', 'TH3ASD', '2026-10-05 16:51:47', NULL, '1504b71fa6176907d8661364b1746e5e'),
(33, 'Kols Slow', 'digital', 'male', 'XY3Q3D', '2026-10-05 16:51:53', NULL, '018cf5e0bac9b3b51826b6fb324fb67f'),
(34, 'dasdsasdas', 'dsadsadsad', 'female', 'GPYYCY', '2026-10-05 16:52:07', NULL, 'fa88303f60a39a84cee2e8e5690f99bc'),
(35, 'dsadsa', 'ddadsadsa', 'female', 'NRSJUP', '2026-10-05 16:52:24', NULL, 'cc942d0a75add2253b2eb8db5739f6e6');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `event_date` date NOT NULL,
  `reg_start` time NOT NULL,
  `vote_start` time NOT NULL,
  `vote_end` time NOT NULL,
  `admin_password_hash` varchar(255) NOT NULL,
  `public_url` varchar(255) NOT NULL DEFAULT ''
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`id`, `event_date`, `reg_start`, `vote_start`, `vote_end`, `admin_password_hash`, `public_url`) VALUES
(1, '2026-10-05', '16:51:00', '16:53:00', '16:55:00', '$2y$10$StulGYzheNoMpL0J69QAbO81yfdGLjWD8AOKYEFVei4uDu9Itcv9q', '');

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `id` int(10) UNSIGNED NOT NULL,
  `voter_id` int(10) UNSIGNED NOT NULL,
  `male_id` int(10) UNSIGNED NOT NULL,
  `female_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `employees`
--
ALTER TABLE `employees`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_employees_code` (`code`),
  ADD UNIQUE KEY `uq_employees_device` (`device_token`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_votes_voter` (`voter_id`),
  ADD KEY `fk_votes_male` (`male_id`),
  ADD KEY `fk_votes_female` (`female_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `employees`
--
ALTER TABLE `employees`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=36;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `fk_votes_female` FOREIGN KEY (`female_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_votes_male` FOREIGN KEY (`male_id`) REFERENCES `employees` (`id`),
  ADD CONSTRAINT `fk_votes_voter` FOREIGN KEY (`voter_id`) REFERENCES `employees` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
