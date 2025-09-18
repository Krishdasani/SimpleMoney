-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 18, 2025 at 07:44 AM
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
-- Database: `simplemoney`
--

-- --------------------------------------------------------

--
-- Table structure for table `budgets`
--

CREATE TABLE `budgets` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `category` varchar(80) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  `period_type` enum('monthly','weekly','custom') NOT NULL DEFAULT 'monthly',
  `start_date` date DEFAULT NULL,
  `rollover` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `budgets`
--

INSERT INTO `budgets` (`id`, `user_id`, `category`, `amount`, `period_type`, `start_date`, `rollover`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'Uber', 100.00, 'monthly', NULL, 0, 0, '2025-09-10 19:46:05', '2025-09-10 19:54:24'),
(2, 1, 'Food & Drink', 500.00, 'monthly', NULL, 0, 0, '2025-09-10 19:53:23', '2025-09-18 02:33:17'),
(3, 1, 'DIRECT_DEBIT', 10000.00, 'monthly', NULL, 0, 0, '2025-09-10 19:54:06', '2025-09-18 02:34:31'),
(4, 1, 'Shopping', 200.00, 'monthly', NULL, 0, 1, '2025-09-18 02:33:37', '2025-09-18 02:33:37'),
(5, 1, 'DIRECT_DEBIT', 500.00, 'monthly', NULL, 0, 1, '2025-09-18 02:34:45', '2025-09-18 02:34:45');

-- --------------------------------------------------------

--
-- Table structure for table `categories`
--

CREATE TABLE `categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(64) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `categories`
--

INSERT INTO `categories` (`id`, `name`) VALUES
(4, 'Bills & Utilities'),
(2, 'Eating Out'),
(6, 'Entertainment'),
(1, 'Groceries'),
(8, 'Health & Fitness'),
(10, 'Income'),
(12, 'Other'),
(5, 'Rent & Mortgage'),
(7, 'Shopping'),
(11, 'Transfers'),
(3, 'Transport'),
(9, 'Travel');

-- --------------------------------------------------------

--
-- Table structure for table `tl_connections`
--

CREATE TABLE `tl_connections` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `provider` varchar(64) NOT NULL DEFAULT 'truelayer',
  `access_token` text NOT NULL,
  `refresh_token` text NOT NULL,
  `expires_at` datetime NOT NULL,
  `scope` text NOT NULL,
  `consent_id` varchar(128) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `tl_connections`
--

INSERT INTO `tl_connections` (`id`, `user_id`, `provider`, `access_token`, `refresh_token`, `expires_at`, `scope`, `consent_id`, `created_at`) VALUES
(7, 1, 'truelayer', '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', '2025-09-18 05:47:56', 'info accounts balance transactions cards offline_access', NULL, '2025-09-16 14:06:08'),
(11, 1, 'truelayer', 'd73SZn4lB+sNeV129pyB+3bX0qLI33eDCs39nfRjXhUsiD7jQ+sUTJFYEhm2q9Xko1QEMbAKH1LZGQUL+0Z4r/23NAtFAorGuhZkARkau3lFQ18J3fZYqIG08So3N8v42OFK/sJ+1G8HbBFCC6TRsLGeFpykVXl84RbtBR349COnVECzuGPqk1sw7C25K7v6peP4PrQzOR2LBg+aXPseIqehsBSVMVYkvMSg34bSOOEJubf8SJ7LTZ35eMQfZYCz7heDNW0atwS0rDj2BMeZiEGdb3+5gYYTx2hAunA3XKmBFQNZwFOnLh/GmgMYgHPIocX2n4XIvpZp7od0tbnps4Ob1wXY4ASqUZwfamFNFBlx9My4hEyOZwrI1viTEFYzP+m/MvSehKp+VhM1FC8vEIag2nyU9VV0WmV+rEb8yCjgPwPaxVV2XEN5FHMJ7ON6cIK+6iOpVsQMLmqe/RrRWV6/zpR1YQABHPVfXLwbOQOuvSr2v3yEpKUrLHyW+T6pf+n7xYBMTrBUaK2fzm/RKQnV/JeqozNwZNT8TKe1qhUAElSTkllE3EZGQICSoQd96lonFJvHkf3dCCTEGF8egmNa2m0+IdGhHz0IcsVe5qScFY4eomLtb/7OFIn4sJ0BqulfOIuiabJ3s6mefG/7vbBhM9ThRbU1Sd50VEJbD2F1Tp3gdejj+xp8NdHW7G6d5rT/KsrOvqvCCXPxnW6wYYx+QKSw6ZwphYX1MlYTO6X3FVY3Nk5M80lH79wEmLdymP068inbEgWGLDWyPlYe+o+guwgMnsv71ghTF+DdIpw1kDXzghcaQd0rpoIuCa6RojE7RMwu3Jo+JJfCOoQJc6E2hUnqKQFc+02g8ggVz8M318WDLLDWWc2l5MeBKTEFd6SQfa2b+95PSXsS36y+ZTh5JJMvhdUfij/1StlnWq9F/cFwFfimFciewdp/OF4wAhg9lDrOgtWa0y19FFh+hZJGS1jL67lBiDMPgSWkFAsgI5H2GADDr9PqO2wzeURnAppFvZLjybbS3FQDDBEmaTL5L8bbUHiIbAiwZ3qq6L+7CQHMhlVf5aIWwns13macSHqYiSsTmC4tPy8SnWaODSvWyYKsgTjeEro+UBay7sCXVdrhpJh7zVMm/vH6fSn7vVj9VRiiYXUluprZ8xwIm+prrsfgAraplvaKYrGiLhwQdNGuryAF2HCBKjoFJbdwmur2Jq25DCWduJLNKqLKtdrX7ookUf9EFHX9sbFyE6iJYtcnzn1MCgg0xuZYqIK5HtOFQaOHBhH9iaO1SoBzRAqql/f3AP78hf/jT/MjI690r8WddUaaNPjNTz7BlY+9ionFz0otmrdwrQ5XMKhEBJw2gMJxtwdhITfub4f1LcyOQcn5CLZ1Mob500jW0+sHrglO14mS4K/YYuLbGMd3xWnKVrkSIIlmMdRiRBrVdsbqpjoMV04ORTcuP2ij0GdBlJ7GnlJ/tH5/cJX4yHqpshmY3KbeJd20o410lSC0EGx65iZf4xraHsH2ffvx9bI9o5Z4+s50psLDP3YNSnRYnYtMvvq+ntyPlbGz+MTrNX8GfOc1Kzha35dblwKDdBS0jltt6fVge+5RWtL0uDC5abptgbBGtVqlI6ySvtO9Ioy8gWRZ/alnb5EUPDoBsxNorwZdaAGder57aIhCBRNk15DA3q3NMWOCvkEaLrYVi602vByRFaJ0EOqI0gv5Loxq2wLswomu18ySOAfLZvMPTUMJwpHbt3vNuwCxzVyah8dW05Wq18XJvo9tgjLWkvlduMRupHtHHRJ4l9Y3gSFDTvSkVm5XqFTFdkrMk7dtT3+8oAJb0r6WbRIU5wFJqZlH7uRpWOeWNoS+lulRVLQ/79RZwJBGcSJCrxDSdkzMHfW668M2JF0Ym2pMhk22QA6+IdCFOp1DGI5wsznuq74dgkUlsk8r+w7GJDtKpBle21TCxIeeTu2YZ7f1v/Ie6Yg9z2pn8maf8D8WvAgMWtqbmn2veklqKKkhpI0q/2pLVm77ODJSWsxsWLDyUzluPuJZ+L2p2Q4/3FnSJ5fHOTGqVsAT+a63lce7oI30zuzkMs9UH/DCWhe732qj16w7bjtenBO60fp96128qGBgcfqlTHr9k16Mv3EvfdITuVbbgs6P/0n2MPCiCwjBRo99MRa2zilbNEsTxsVvmd4qtqekGhNC67DDJzaFW7Su0RQ0DE3nWKbBWcz6fVnUMBgxgz9RMw1Zo1Td8HmNtywICoPgHZwsokBnyRwAUpKygPvfytSbqthOc+zpVG7iQHyI6OM0ulOmObw2DDWjNwnmwkqSpPsfPego0URDVMMG26z90Zb7Qs7qxnG4dEm/IpLJntnxoGuocT+sMquFDg==', 'LEPyCF1EnUYlc+gafFVJnwOGqe2tirhpCv9/1fSu+/W0Jxh9DGwFKtrE09WM3QB4Sk7nBovWgeENMAD3v3sNbMhYEpaLfUa9vprQZML71tBBX1MbUUGwn/Z/otE=', '2025-09-18 04:58:03', 'info accounts balance transactions cards offline_access', NULL, '2025-09-16 15:21:02'),
(12, 1, 'truelayer', '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', '2025-09-18 05:52:01', 'info accounts balance transactions cards offline_access', NULL, '2025-09-18 04:52:01');

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `google_sub` varchar(64) DEFAULT NULL,
  `name` varchar(120) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `google_sub`, `name`, `password_hash`, `created_at`) VALUES
(1, 'krishdasani123@gmail.com', '114500514393622090309', 'Krish', NULL, '2025-09-08 15:08:48'),
(2, 'krishdasani02@gmail.com', NULL, NULL, '$2y$10$XaSBHeNZUQDAnXJ1a8QgpueofJlfZuHcmr9L1hlYjRNaPDYs225s6', '2025-09-08 15:13:30');

-- --------------------------------------------------------

--
-- Table structure for table `user_refresh_tokens`
--

CREATE TABLE `user_refresh_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `token_hash` char(64) NOT NULL,
  `issued_at` datetime NOT NULL,
  `expires_at` datetime NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `revoked` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_refresh_tokens`
--

INSERT INTO `user_refresh_tokens` (`id`, `user_id`, `token_hash`, `issued_at`, `expires_at`, `user_agent`, `ip`, `revoked`) VALUES
(4, 2, '7ea073ec780e1c3f88d406b52e7a695e20384252c9f7a7400f9d030f6e585b2d', '2025-09-08 15:13:30', '2025-11-07 15:13:30', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '::1', 0),
(5, 2, '1ad2ba56c3e8bb82463ea9e689be504e49051e69357a160af413b8ca2b8cb4f0', '2025-09-08 15:14:28', '2025-11-07 15:14:28', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/139.0.0.0 Safari/537.36', '::1', 0),
(47, 1, 'b23e1c208fc7e4d558e831d962a41a6cf0a65a475b608cabfc574959d8eee258', '2025-09-16 12:56:32', '2025-11-15 12:56:32', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 0),
(48, 1, 'face53c435ac6d44b47fff2c83a1fc1b6958e91702e9102cb2804f2aa2cbe620', '2025-09-16 12:56:38', '2025-11-15 12:56:38', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(49, 1, 'af31962f583542b4e6eb9c91148e1abf422fc7ebb69376fda619a8d9f3579929', '2025-09-16 15:57:10', '2025-11-15 14:57:10', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(50, 1, '327f4b4a160ee96de2047ffcd9f95b16bde2ff2cb4b620ded03ea51b567e1e54', '2025-09-16 16:27:36', '2025-11-15 15:27:36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(51, 1, 'c66361e2fa719985a54110084cfa136ef8227be38cfca8a19b802e9e9e85bc36', '2025-09-16 16:58:05', '2025-11-15 15:58:05', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(52, 1, '7f3aa3830ca883ab15ab3d2a3ebc0a0d9fe35b47cabb1a90db9884a009fed522', '2025-09-16 17:29:24', '2025-11-15 16:29:24', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(53, 1, '589c3d890e5d360582d1145e8baf6d7f302b50919036dec42e5b1954dfb0196f', '2025-09-16 18:00:02', '2025-11-15 17:00:02', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(54, 1, '10915f86efa22dfc5a97b0977eae5d3ae982987dc2a6d98e2873589e6cd3b092', '2025-09-16 18:30:04', '2025-11-15 17:30:04', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(55, 1, 'fc7eca9504409a8b2cc8eef4c14f8b31b9724888b28315fdb6e130a0c7fbe54e', '2025-09-16 19:00:36', '2025-11-15 18:00:36', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(56, 1, '5492f97764c60503f5b148b3c303ed3b6b8143467bff3e93f42662ef1387ad0f', '2025-09-16 19:33:16', '2025-11-15 18:33:16', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(57, 1, '7fb20a79ea7343162ebf13239557036b442b50d541905a3ac8fffc98c0f4a567', '2025-09-16 20:14:09', '2025-11-15 19:14:09', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(58, 1, '426f7817a7244aaf15967baedb81096c6de2391c9ab3ade208fee5ef64b58fd0', '2025-09-18 04:12:21', '2025-11-17 03:12:21', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(59, 2, 'e889dcb4a3ff963c78e4655ab34fba03bed0cd2dfbe5a970d00f763793e6814a', '2025-09-18 03:41:57', '2025-11-17 03:41:57', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 0),
(60, 1, 'b019d36ac4080f49bad6dcb0c315a64a541569cd9474c40ab58becbfa4e8b9a8', '2025-09-18 05:42:28', '2025-11-17 04:42:28', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 0),
(61, 1, '199213ee257e3cd8e8a2090934250564721ff80e4c657e48f1e28f8706d3c2e3', '2025-09-18 04:15:37', '2025-11-17 04:15:37', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 1),
(62, 1, '1137f3f9f43458264f098479d83049c1c504ab69470f897673ff013fced0c8b1', '2025-09-18 06:47:34', '2025-11-17 05:47:34', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', '::1', 0);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `budgets`
--
ALTER TABLE `budgets`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_user_cat_period` (`user_id`,`category`,`period_type`,`start_date`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Indexes for table `tl_connections`
--
ALTER TABLE `tl_connections`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_tlconn_user` (`user_id`),
  ADD KEY `idx_tlconn_expires` (`expires_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `google_sub` (`google_sub`);

--
-- Indexes for table `user_refresh_tokens`
--
ALTER TABLE `user_refresh_tokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_user_exp` (`user_id`,`expires_at`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `budgets`
--
ALTER TABLE `budgets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `tl_connections`
--
ALTER TABLE `tl_connections`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `user_refresh_tokens`
--
ALTER TABLE `user_refresh_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tl_connections`
--
ALTER TABLE `tl_connections`
  ADD CONSTRAINT `fk_tlconn_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `user_refresh_tokens`
--
ALTER TABLE `user_refresh_tokens`
  ADD CONSTRAINT `fk_urt_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
