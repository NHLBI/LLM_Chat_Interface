/*M!999999\- enable the sandbox mode */ 
-- MariaDB dump 10.19  Distrib 10.5.27-MariaDB, for Linux (x86_64)
--
-- Host: localhost    Database: osi_chat_dev
-- ------------------------------------------------------
-- Server version	10.5.27-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `chat`
--

DROP TABLE IF EXISTS `chat`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat` (
  `id` varchar(32) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `summary` text DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `temperature` decimal(2,1) DEFAULT NULL,
  `new_title` tinyint(1) NOT NULL DEFAULT 1,
  `deleted` tinyint(4) DEFAULT 0,
  `sort_order` int(16) NOT NULL DEFAULT 0,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_viewed` timestamp NULL DEFAULT NULL,
  `soft_delete_date` date DEFAULT NULL,
  `hard_delete_date` date DEFAULT NULL,
  UNIQUE KEY `id` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `document`
--

DROP TABLE IF EXISTS `document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `document` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(32) NOT NULL,
  `name` varchar(255) NOT NULL,
  `type` varchar(124) DEFAULT NULL,
  `content` longtext NOT NULL,
  `document_token_length` int(11) NOT NULL DEFAULT 0,
  `source` varchar(24) DEFAULT NULL,
  `deleted` tinyint(1) DEFAULT 0,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `exchange_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_exchange_chat_docs` (`chat_id`),
  CONSTRAINT `fk_exchange_chat_docs` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=53901 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exchange`
--

DROP TABLE IF EXISTS `exchange`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `chat_id` varchar(32) NOT NULL,
  `user` varchar(255) DEFAULT NULL,
  `prompt` longtext DEFAULT NULL,
  `prompt_token_length` int(11) DEFAULT NULL,
  `reply` text DEFAULT NULL,
  `reply_token_length` int(11) DEFAULT NULL,
  `exchange_type` varchar(32) DEFAULT 'chat',
  `image_gen_name` varchar(255) DEFAULT NULL,
  `deployment` varchar(64) DEFAULT NULL,
  `api_key` varchar(64) DEFAULT NULL,
  `temperature` decimal(2,1) DEFAULT NULL,
  `uri` varchar(256) DEFAULT NULL,
  `api_endpoint` varchar(255) DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `timestamp` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_exchange_chat` (`chat_id`),
  CONSTRAINT `fk_exchange_chat` FOREIGN KEY (`chat_id`) REFERENCES `chat` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=99307 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `exchange_document`
--

DROP TABLE IF EXISTS `exchange_document`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exchange_document` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_id` int(11) NOT NULL,
  `document_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `exchange_id` (`exchange_id`),
  KEY `document_id` (`document_id`)
) ENGINE=InnoDB AUTO_INCREMENT=5253 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow`
--

DROP TABLE IF EXISTS `workflow`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workflow` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `title` varchar(255) DEFAULT NULL,
  `description` mediumtext NOT NULL,
  `content` longtext DEFAULT NULL,
  `prompt` mediumtext DEFAULT NULL,
  `deleted` tinyint(4) DEFAULT 0,
  `sort_order` int(8) NOT NULL DEFAULT 0,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `date_created` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_config`
--

DROP TABLE IF EXISTS `workflow_config`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workflow_config` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `config_label` varchar(48) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_config_join`
--

DROP TABLE IF EXISTS `workflow_config_join`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workflow_config_join` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) NOT NULL,
  `workflow_config_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `workflow_id` (`workflow_id`),
  KEY `workflow_config_id` (`workflow_config_id`)
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `workflow_exchange`
--

DROP TABLE IF EXISTS `workflow_exchange`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workflow_exchange` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `workflow_id` int(11) NOT NULL,
  `exchange_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `exchange_id` (`exchange_id`),
  KEY `workflow_id` (`workflow_id`),
  CONSTRAINT `fk_workflow_exchange` FOREIGN KEY (`exchange_id`) REFERENCES `exchange` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=127 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-06-24 12:49:04
