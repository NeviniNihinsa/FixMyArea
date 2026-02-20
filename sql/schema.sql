-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 20, 2026 at 07:47 PM
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
-- Database: `fixmyarea`
--

-- --------------------------------------------------------

--
-- Table structure for table `areas`
--

CREATE TABLE `areas` (
  `area_id` int(11) NOT NULL,
  `area_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `areas`
--

INSERT INTO `areas` (`area_id`, `area_name`, `created_at`) VALUES
(1, 'Colombo Fort', '2026-02-11 12:38:28'),
(2, 'Nugegoda', '2026-02-11 12:38:28'),
(3, 'Bambalapitiya', '2026-02-11 12:38:28'),
(4, 'Slave Island', '2026-02-11 12:38:28');

-- --------------------------------------------------------

--
-- Table structure for table `assignments`
--

CREATE TABLE `assignments` (
  `assignment_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `field_worker_id` int(11) NOT NULL,
  `assigned_by_authority_id` int(11) NOT NULL,
  `assignment_status` enum('ASSIGNED','ACCEPTED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'ASSIGNED',
  `assigned_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `accepted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `assignments`
--

INSERT INTO `assignments` (`assignment_id`, `issue_id`, `field_worker_id`, `assigned_by_authority_id`, `assignment_status`, `assigned_at`, `accepted_at`, `completed_at`) VALUES
(1, 1, 3, 2, 'COMPLETED', '2026-02-14 06:40:51', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `comments`
--

CREATE TABLE `comments` (
  `comment_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `comment_text` text NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `comments`
--

INSERT INTO `comments` (`comment_id`, `issue_id`, `user_id`, `comment_text`, `created_at`) VALUES
(1, 3, 5, 'Nice Job', '2026-02-14 06:00:46'),
(2, 3, 4, 'This is affecting daily commuters badly.', '2026-02-14 06:01:36'),
(3, 3, 5, 'Work team has inspected the location.', '2026-02-14 06:01:36');

-- --------------------------------------------------------

--
-- Table structure for table `feedback_ratings`
--

CREATE TABLE `feedback_ratings` (
  `feedback_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `citizen_user_id` int(11) NOT NULL,
  `authority_user_id` int(11) DEFAULT NULL,
  `field_worker_id` int(11) DEFAULT NULL,
  `authority_rating` tinyint(4) DEFAULT NULL,
  `worker_rating` tinyint(4) DEFAULT NULL,
  `overall_rating` tinyint(4) DEFAULT NULL,
  `feedback_text` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `feedback_ratings`
--

INSERT INTO `feedback_ratings` (`feedback_id`, `issue_id`, `citizen_user_id`, `authority_user_id`, `field_worker_id`, `authority_rating`, `worker_rating`, `overall_rating`, `feedback_text`, `created_at`) VALUES
(1, 3, 4, 1, 2, 4, 5, 5, 'Issue was fixed quickly and professionally.', '2026-02-14 05:56:44');

-- --------------------------------------------------------

--
-- Table structure for table `issues`
--

CREATE TABLE `issues` (
  `issue_id` int(11) NOT NULL,
  `reporter_user_id` int(11) NOT NULL,
  `area_id` int(11) NOT NULL,
  `category_id` int(11) DEFAULT NULL,
  `title` varchar(200) NOT NULL,
  `description` text NOT NULL,
  `lat` decimal(10,7) NOT NULL,
  `lng` decimal(10,7) NOT NULL,
  `status` enum('PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED') NOT NULL DEFAULT 'PENDING',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issues`
--

INSERT INTO `issues` (`issue_id`, `reporter_user_id`, `area_id`, `category_id`, `title`, `description`, `lat`, `lng`, `status`, `created_at`) VALUES
(1, 4, 3, 1, 'Pothole near main road', 'Large pothole causing traffic issues.', 6.8941000, 79.8559000, 'PENDING', '2026-02-11 12:38:28'),
(2, 5, 1, 1, 'Large pothole near Bambalapitiya junction', 'There is a deep pothole in the middle of the road causing heavy traffic during peak hours. Several vehicles have already been damaged.', 6.8941000, 6.8941000, 'PENDING', '2026-02-13 09:18:37'),
(3, 5, 1, 1, 'Large pothole near main bus stop', 'There is a deep pothole near the main bus stop causing traffic congestion and creating a serious safety risk for motorcycles and school vans.', 6.9270790, 79.8612440, 'PENDING', '2026-02-13 14:17:24'),
(4, 5, 1, 4, 'Road Garbage', 'Road Garbage', 6.8299170, 79.9121320, 'PENDING', '2026-02-18 15:16:09');

-- --------------------------------------------------------

--
-- Table structure for table `issue_categories`
--

CREATE TABLE `issue_categories` (
  `category_id` int(11) NOT NULL,
  `category_name` varchar(100) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_categories`
--

INSERT INTO `issue_categories` (`category_id`, `category_name`, `created_at`) VALUES
(1, 'Road', '2026-02-11 12:38:28'),
(2, 'Water', '2026-02-11 12:38:28'),
(3, 'Street Light', '2026-02-11 12:38:28'),
(4, 'Garbage', '2026-02-11 12:38:28'),
(5, 'Drainage', '2026-02-11 12:38:28');

-- --------------------------------------------------------

--
-- Table structure for table `issue_photos`
--

CREATE TABLE `issue_photos` (
  `photo_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `photo_type` enum('REPORT','PROOF_BEFORE','PROOF_AFTER') NOT NULL,
  `file_path` varchar(255) NOT NULL,
  `uploaded_by_user_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_photos`
--

INSERT INTO `issue_photos` (`photo_id`, `issue_id`, `photo_type`, `file_path`, `uploaded_by_user_id`, `created_at`) VALUES
(1, 2, 'REPORT', '/public/uploads/issues/issue_1770974317_386aec707991.jpg', 5, '2026-02-13 09:18:37'),
(2, 3, 'REPORT', '/public/uploads/issues/issue_1770992244_45b61b04d216.jpg', 5, '2026-02-13 14:17:24'),
(3, 3, 'PROOF_BEFORE', '/public/uploads/issues/issue_test_before.jpg', 2, '2026-02-14 05:54:55'),
(4, 3, 'PROOF_AFTER', '/public/uploads/issues/issue_test_after.jpg', 2, '2026-02-14 05:54:55'),
(5, 4, 'REPORT', '/public/uploads/issues/issue_1771427769_0d2f447b5cd7.jpg', 5, '2026-02-18 15:16:09');

-- --------------------------------------------------------

--
-- Table structure for table `issue_status_history`
--

CREATE TABLE `issue_status_history` (
  `history_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `status` enum('PENDING','ASSIGNED','IN_PROGRESS','COMPLETED','CLOSED','REOPENED','REJECTED') NOT NULL,
  `changed_by_user_id` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `issue_status_history`
--

INSERT INTO `issue_status_history` (`history_id`, `issue_id`, `status`, `changed_by_user_id`, `note`, `created_at`) VALUES
(1, 1, 'PENDING', 4, 'Issue reported by citizen', '2026-02-11 12:38:28'),
(2, 2, 'PENDING', 5, 'Issue reported by citizen', '2026-02-13 09:18:37'),
(3, 3, 'PENDING', 5, 'Issue reported by citizen', '2026-02-13 14:17:24'),
(4, 3, 'PENDING', 5, 'Issue reported by citizen', '2026-02-14 05:50:15'),
(5, 3, 'ASSIGNED', 1, 'Assigned to field worker', '2026-02-14 05:50:15'),
(6, 3, 'IN_PROGRESS', 2, 'Work started on site', '2026-02-14 05:50:15'),
(7, 1, 'IN_PROGRESS', 2, 'Authority verified issue', '2026-02-14 06:47:28'),
(8, 4, 'PENDING', 5, 'Issue reported by citizen', '2026-02-18 15:16:09');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `notification_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `issue_id` int(11) DEFAULT NULL,
  `notification_type` varchar(50) NOT NULL,
  `title` varchar(150) NOT NULL,
  `message` text NOT NULL,
  `action_url` varchar(255) DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `notifications`
--

INSERT INTO `notifications` (`notification_id`, `user_id`, `issue_id`, `notification_type`, `title`, `message`, `action_url`, `is_read`, `read_at`, `created_at`) VALUES
(10, 5, 2, 'STATUS', 'Issue status updated', 'Your issue #2 has been updated to IN_PROGRESS.', '/citizen/issue_view.php?issue_id=2', 1, '2026-02-17 17:21:03', '2026-02-17 17:15:21'),
(11, 5, 3, 'COMMENT', 'New comment on your issue', 'Someone commented on issue #3. Tap to view.', '/citizen/issue_view.php?issue_id=3', 1, '2026-02-17 17:20:59', '2026-02-17 17:15:21'),
(12, 5, NULL, 'SYSTEM', 'Welcome to FixMyArea', 'Thanks for joining! You can report issues anytime.', '/citizen/home.php', 1, NULL, '2026-02-17 17:15:21');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int(11) NOT NULL,
  `name` varchar(150) NOT NULL,
  `email` varchar(190) NOT NULL,
  `nic` varchar(20) NOT NULL,
  `dob` date DEFAULT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `gender` enum('male','female','other') DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `area_id` int(11) DEFAULT NULL,
  `role` enum('admin','authority','worker','citizen') NOT NULL DEFAULT 'citizen',
  `password_hash` varchar(255) NOT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`user_id`, `name`, `email`, `nic`, `dob`, `phone`, `gender`, `address`, `area_id`, `role`, `password_hash`, `status`, `created_at`) VALUES
(1, 'System Admin', 'admin@fixmyarea.lk', 'ADMIN001', '2000-01-01', '0770000000', 'other', 'HQ', NULL, 'admin', '$2b$10$461MkJqM8n5vJL2vEvWo3uFn/Cbo36YoDq8eXBH5Ajc4znvOQY7NC', 'active', '2026-02-11 12:38:28'),
(2, 'Colombo Council', 'authority@fixmyarea.lk', 'AUTH001', '1995-05-05', '0771111111', 'other', 'Colombo Fort Office', 1, 'authority', '$2b$10$BJmKn8z/CWgadn0ogYlu9eKJzSBaUH7TgAJHLgNj2SR7kKuhO6sIy', 'active', '2026-02-11 12:38:28'),
(3, 'Worker A', 'worker@fixmyarea.lk', 'WORK001', '1998-08-08', '0772222222', 'male', 'Colombo Fort Yard', 1, 'worker', '$2b$10$mCje41Ggkue7Mp3hMLoQ8Oo02KayaV.by.5g8ISA2Qg5UXbmFN8Qy', 'active', '2026-02-11 12:38:28'),
(4, 'Citizen One', 'citizen@fixmyarea.lk', 'CIT001', '2002-02-02', '0773333333', 'female', 'Bambalapitiya', 3, 'citizen', '$2b$10$o86Ec1.G4fdc/b.MzSWylOTWMN56xdpoBpPI.HwORKZ9vLMcJl1X6', 'active', '2026-02-11 12:38:28'),
(5, 'Test user1', 'testuser1@gmail.com', '981234567V', '2007-06-20', '0987654321', 'female', '001, test road one, colombo', 1, 'citizen', '$2y$10$4Bx4ozCXnb6txTZ8U8hNQeH0Ikpx4UodGUCuOttE8LTC.8yi1P8yq', 'active', '2026-02-11 13:47:38');

-- --------------------------------------------------------

--
-- Table structure for table `votes`
--

CREATE TABLE `votes` (
  `vote_id` int(11) NOT NULL,
  `issue_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `value` tinyint(4) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `votes`
--

INSERT INTO `votes` (`vote_id`, `issue_id`, `user_id`, `value`, `created_at`) VALUES
(4, 3, 4, 1, '2026-02-14 05:55:24'),
(9, 1, 5, 1, '2026-02-14 13:48:17');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `areas`
--
ALTER TABLE `areas`
  ADD PRIMARY KEY (`area_id`),
  ADD UNIQUE KEY `area_name` (`area_name`);

--
-- Indexes for table `assignments`
--
ALTER TABLE `assignments`
  ADD PRIMARY KEY (`assignment_id`),
  ADD KEY `fk_assign_authority` (`assigned_by_authority_id`),
  ADD KEY `idx_assign_worker_status` (`field_worker_id`,`assignment_status`),
  ADD KEY `idx_assign_issue` (`issue_id`);

--
-- Indexes for table `comments`
--
ALTER TABLE `comments`
  ADD PRIMARY KEY (`comment_id`),
  ADD KEY `fk_comments_user` (`user_id`),
  ADD KEY `idx_comments_issue_created` (`issue_id`,`created_at`);

--
-- Indexes for table `feedback_ratings`
--
ALTER TABLE `feedback_ratings`
  ADD PRIMARY KEY (`feedback_id`),
  ADD UNIQUE KEY `uq_feedback_one_per_issue` (`issue_id`),
  ADD KEY `fk_feedback_citizen` (`citizen_user_id`),
  ADD KEY `fk_feedback_authority` (`authority_user_id`),
  ADD KEY `fk_feedback_worker` (`field_worker_id`);

--
-- Indexes for table `issues`
--
ALTER TABLE `issues`
  ADD PRIMARY KEY (`issue_id`),
  ADD KEY `idx_issues_area_status_created` (`area_id`,`status`,`created_at`),
  ADD KEY `idx_issues_reporter_created` (`reporter_user_id`,`created_at`),
  ADD KEY `idx_issues_category` (`category_id`);

--
-- Indexes for table `issue_categories`
--
ALTER TABLE `issue_categories`
  ADD PRIMARY KEY (`category_id`),
  ADD UNIQUE KEY `category_name` (`category_name`);

--
-- Indexes for table `issue_photos`
--
ALTER TABLE `issue_photos`
  ADD PRIMARY KEY (`photo_id`),
  ADD KEY `fk_photos_uploader` (`uploaded_by_user_id`),
  ADD KEY `idx_issue_photos_issue` (`issue_id`);

--
-- Indexes for table `issue_status_history`
--
ALTER TABLE `issue_status_history`
  ADD PRIMARY KEY (`history_id`),
  ADD KEY `fk_hist_user` (`changed_by_user_id`),
  ADD KEY `idx_hist_issue_created` (`issue_id`,`created_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`notification_id`),
  ADD KEY `fk_notif_issue` (`issue_id`),
  ADD KEY `idx_notif_user_read` (`user_id`,`is_read`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `nic` (`nic`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_area` (`area_id`);

--
-- Indexes for table `votes`
--
ALTER TABLE `votes`
  ADD PRIMARY KEY (`vote_id`),
  ADD UNIQUE KEY `uq_votes_issue_user` (`issue_id`,`user_id`),
  ADD KEY `fk_votes_user` (`user_id`),
  ADD KEY `idx_votes_issue` (`issue_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `areas`
--
ALTER TABLE `areas`
  MODIFY `area_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `assignments`
--
ALTER TABLE `assignments`
  MODIFY `assignment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `comments`
--
ALTER TABLE `comments`
  MODIFY `comment_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `feedback_ratings`
--
ALTER TABLE `feedback_ratings`
  MODIFY `feedback_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `issues`
--
ALTER TABLE `issues`
  MODIFY `issue_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `issue_categories`
--
ALTER TABLE `issue_categories`
  MODIFY `category_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `issue_photos`
--
ALTER TABLE `issue_photos`
  MODIFY `photo_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `issue_status_history`
--
ALTER TABLE `issue_status_history`
  MODIFY `history_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `notification_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `votes`
--
ALTER TABLE `votes`
  MODIFY `vote_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `assignments`
--
ALTER TABLE `assignments`
  ADD CONSTRAINT `fk_assign_authority` FOREIGN KEY (`assigned_by_authority_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assign_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_assign_worker` FOREIGN KEY (`field_worker_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `comments`
--
ALTER TABLE `comments`
  ADD CONSTRAINT `fk_comments_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `feedback_ratings`
--
ALTER TABLE `feedback_ratings`
  ADD CONSTRAINT `fk_feedback_authority` FOREIGN KEY (`authority_user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_citizen` FOREIGN KEY (`citizen_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_feedback_worker` FOREIGN KEY (`field_worker_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `issues`
--
ALTER TABLE `issues`
  ADD CONSTRAINT `fk_issues_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`area_id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_issues_category` FOREIGN KEY (`category_id`) REFERENCES `issue_categories` (`category_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_issues_reporter` FOREIGN KEY (`reporter_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `issue_photos`
--
ALTER TABLE `issue_photos`
  ADD CONSTRAINT `fk_photos_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_photos_uploader` FOREIGN KEY (`uploaded_by_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `issue_status_history`
--
ALTER TABLE `issue_status_history`
  ADD CONSTRAINT `fk_hist_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_hist_user` FOREIGN KEY (`changed_by_user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notif_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_area` FOREIGN KEY (`area_id`) REFERENCES `areas` (`area_id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `votes`
--
ALTER TABLE `votes`
  ADD CONSTRAINT `fk_votes_issue` FOREIGN KEY (`issue_id`) REFERENCES `issues` (`issue_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
