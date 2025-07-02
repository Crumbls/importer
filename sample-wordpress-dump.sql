-- WordPress SQL Dump Sample
-- Generated for testing purposes

SET FOREIGN_KEY_CHECKS=0;

--
-- Table structure for table `wp_posts`
--

DROP TABLE IF EXISTS `wp_posts`;
CREATE TABLE `wp_posts` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_author` bigint(20) unsigned NOT NULL DEFAULT '0',
  `post_date` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_date_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_content` longtext NOT NULL,
  `post_title` text NOT NULL,
  `post_excerpt` text NOT NULL,
  `post_status` varchar(20) NOT NULL DEFAULT 'publish',
  `post_name` varchar(200) NOT NULL DEFAULT '',
  `post_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_modified_gmt` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `post_parent` bigint(20) unsigned NOT NULL DEFAULT '0',
  `guid` varchar(255) NOT NULL DEFAULT '',
  `menu_order` int(11) NOT NULL DEFAULT '0',
  `post_type` varchar(20) NOT NULL DEFAULT 'post',
  `post_mime_type` varchar(100) NOT NULL DEFAULT '',
  `comment_count` bigint(20) NOT NULL DEFAULT '0',
  PRIMARY KEY (`ID`),
  KEY `post_name` (`post_name`(191)),
  KEY `type_status_date` (`post_type`,`post_status`,`post_date`,`ID`),
  KEY `post_parent` (`post_parent`),
  KEY `post_author` (`post_author`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_posts`
--

INSERT INTO `wp_posts` VALUES 
(1,1,'2024-01-15 10:00:00','2024-01-15 10:00:00','Welcome to WordPress! This is your first post. Edit or delete it, then start writing!','Hello World!','','publish','hello-world','2024-01-15 10:00:00','2024-01-15 10:00:00',0,'https://example.com/?p=1',0,'post','',0),
(2,1,'2024-01-16 11:30:00','2024-01-16 11:30:00','This is an awesome t-shirt made from premium cotton. Available in multiple colors and sizes.','Premium Cotton T-Shirt','Comfortable and stylish','publish','premium-cotton-tshirt','2024-01-16 11:30:00','2024-01-16 11:30:00',0,'https://example.com/?post_type=product&p=2',0,'product','',0),
(3,1,'2024-01-17 14:15:00','2024-01-17 14:15:00','Join us for an amazing tech conference with industry leaders and innovative presentations.','Tech Conference 2024','Annual technology conference','publish','tech-conference-2024','2024-01-17 14:15:00','2024-01-17 14:15:00',0,'https://example.com/?post_type=event&p=3',0,'event','',0),
(4,1,'2024-01-18 09:45:00','2024-01-18 09:45:00','John is our CEO with over 15 years of experience in technology and business development.','John Doe - CEO','Experienced technology leader','publish','john-doe-ceo','2024-01-18 09:45:00','2024-01-18 09:45:00',0,'https://example.com/?post_type=team_member&p=4',0,'team_member','',0);

--
-- Table structure for table `wp_postmeta`
--

DROP TABLE IF EXISTS `wp_postmeta`;
CREATE TABLE `wp_postmeta` (
  `meta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `post_id` bigint(20) unsigned NOT NULL DEFAULT '0',
  `meta_key` varchar(255) DEFAULT NULL,
  `meta_value` longtext,
  PRIMARY KEY (`meta_id`),
  KEY `post_id` (`post_id`),
  KEY `meta_key` (`meta_key`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_postmeta`
--

INSERT INTO `wp_postmeta` VALUES 
(1,1,'_edit_last','1'),
(2,1,'_thumbnail_id','10'),
(3,1,'seo_title','Hello World - SEO Optimized Title'),
(4,1,'seo_description','This is the meta description for SEO purposes'),
(5,2,'_edit_last','1'),
(6,2,'_price','29.99'),
(7,2,'_regular_price','29.99'),
(8,2,'_sale_price',''),
(9,2,'_stock_status','instock'),
(10,2,'_stock','100'),
(11,2,'_weight','0.5'),
(12,2,'product_color','Blue'),
(13,2,'product_size','Medium'),
(14,2,'product_material','Cotton'),
(15,2,'product_brand','EcoWear'),
(16,3,'_edit_last','1'),
(17,3,'event_date','2024-08-15'),
(18,3,'event_time','18:00'),
(19,3,'event_location','Convention Center'),
(20,3,'event_capacity','500'),
(21,3,'event_price','199.00'),
(22,3,'event_organizer','TechCorp Events'),
(23,3,'event_speakers','["John Smith", "Jane Doe", "Mike Johnson"]'),
(24,4,'_edit_last','1'),
(25,4,'position','Chief Executive Officer'),
(26,4,'department','Executive'),
(27,4,'bio','John has over 15 years of experience in technology and business development. He founded the company in 2010 and has led its growth to become a market leader.'),
(28,4,'email','john@company.com'),
(29,4,'phone','+1-555-0123'),
(30,4,'linkedin_url','https://linkedin.com/in/johndoe'),
(31,4,'years_experience','15'),
(32,4,'skills','["Leadership", "Strategy", "Technology", "Business Development"]'),
(33,4,'photo_url','https://example.com/wp-content/uploads/john-doe.jpg');

--
-- Table structure for table `wp_users`
--

DROP TABLE IF EXISTS `wp_users`;
CREATE TABLE `wp_users` (
  `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_login` varchar(60) NOT NULL DEFAULT '',
  `user_pass` varchar(255) NOT NULL DEFAULT '',
  `user_nicename` varchar(50) NOT NULL DEFAULT '',
  `user_email` varchar(100) NOT NULL DEFAULT '',
  `user_url` varchar(100) NOT NULL DEFAULT '',
  `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `user_activation_key` varchar(255) NOT NULL DEFAULT '',
  `user_status` int(11) NOT NULL DEFAULT '0',
  `display_name` varchar(250) NOT NULL DEFAULT '',
  PRIMARY KEY (`ID`),
  KEY `user_login_key` (`user_login`),
  KEY `user_nicename` (`user_nicename`),
  KEY `user_email` (`user_email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_users`
--

INSERT INTO `wp_users` VALUES 
(1,'admin','$2y$10','admin','admin@example.com','','2024-01-01 00:00:00','',0,'Administrator');

--
-- Table structure for table `wp_terms`
--

DROP TABLE IF EXISTS `wp_terms`;
CREATE TABLE `wp_terms` (
  `term_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) NOT NULL DEFAULT '',
  `slug` varchar(200) NOT NULL DEFAULT '',
  `term_group` bigint(10) NOT NULL DEFAULT '0',
  PRIMARY KEY (`term_id`),
  KEY `slug` (`slug`(191)),
  KEY `name` (`name`(191))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

--
-- Dumping data for table `wp_terms`
--

INSERT INTO `wp_terms` VALUES 
(1,'Technology','technology',0),
(2,'Business','business',0),
(3,'Products','products',0);

SET FOREIGN_KEY_CHECKS=1;
