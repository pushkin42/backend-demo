-- --------------------------------------------------------
-- Хост:                     127.0.0.1
-- Версия сервера:  11.8.6-MariaDB-0+deb13u1 from Debian - -- Please help get to 10k stars at https://github.com/MariaDB/Server
-- Операционная система:debian-linux-gnu
-- HeidiSQL Версия:        12.17.1.1
-- --------------------------------------------------------

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


-- Дамп структуры базы данных club_db
CREATE DATABASE IF NOT EXISTS `club_db` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci */;
USE `club_db`;

-- Дамп структуры для таблица club_db.clubs
CREATE TABLE IF NOT EXISTS `clubs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name_ru` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `name_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `city_ru` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `city_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Дамп данных таблицы club_db.clubs: ~3 rows (приблизительно)
REPLACE INTO `clubs` (`id`, `name_ru`, `name_en`, `city_ru`, `city_en`, `created_at`, `updated_at`) VALUES
	(1, 'Спартак', 'Spartak', 'Москва', 'Moscow', NULL, NULL),
	(2, 'Зенит', 'Zenit', 'Санкт-Петербург', 'Saint Petersburg', NULL, NULL),
	(3, 'Рубин', 'Rubin', 'Казань', 'Kazan', NULL, NULL);

-- Дамп структуры для таблица club_db.players
CREATE TABLE IF NOT EXISTS `players` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fio_ru` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `fio_en` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `weight` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Дамп данных таблицы club_db.players: ~5 rows (приблизительно)
REPLACE INTO `players` (`id`, `fio_ru`, `fio_en`, `weight`, `height`, `created_at`, `updated_at`) VALUES
	(1, 'Иванов Иван Иванович', 'Ivan Ivanov', 82, 184, NULL, NULL),
	(2, 'Петров Пётр Сергеевич', 'Petr Petrov', 76, 178, NULL, NULL),
	(3, 'Сидоров Алексей Николаевич', 'Alexey Sidorov', 90, 190, NULL, NULL),
	(4, 'Смирнов Дмитрий Олегович', 'Dmitry Smirnov', 71, 175, NULL, NULL),
	(5, 'Кузнецов Андрей Павлович', 'Andrey Kuznetsov', 85, 186, NULL, NULL);

-- Дамп структуры для таблица club_db.player_season_club
CREATE TABLE IF NOT EXISTS `player_season_club` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `player_id` bigint(20) unsigned NOT NULL,
  `club_id` bigint(20) unsigned NOT NULL,
  `season_id` bigint(20) unsigned NOT NULL,
  `game_number` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_player_season` (`player_id`,`season_id`),
  UNIQUE KEY `uq_club_season_number` (`club_id`,`season_id`,`game_number`),
  KEY `fk_psc_season` (`season_id`),
  KEY `idx_psc_club_season` (`club_id`,`season_id`),
  KEY `idx_psc_player` (`player_id`),
  CONSTRAINT `fk_psc_club` FOREIGN KEY (`club_id`) REFERENCES `clubs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_psc_player` FOREIGN KEY (`player_id`) REFERENCES `players` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_psc_season` FOREIGN KEY (`season_id`) REFERENCES `seasons` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Дамп данных таблицы club_db.player_season_club: ~5 rows (приблизительно)
REPLACE INTO `player_season_club` (`id`, `player_id`, `club_id`, `season_id`, `game_number`, `created_at`, `updated_at`) VALUES
	(1, 1, 1, 1, 10, NULL, NULL),
	(2, 2, 1, 1, 7, NULL, NULL),
	(3, 3, 2, 1, 9, NULL, NULL),
	(4, 4, 2, 2, 11, NULL, NULL),
	(5, 5, 3, 2, 5, NULL, NULL);

-- Дамп структуры для таблица club_db.seasons
CREATE TABLE IF NOT EXISTS `seasons` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_uca1400_ai_ci NOT NULL,
  `year_start` smallint(5) unsigned NOT NULL,
  `year_end` smallint(5) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Дамп данных таблицы club_db.seasons: ~2 rows (приблизительно)
REPLACE INTO `seasons` (`id`, `name`, `year_start`, `year_end`, `created_at`, `updated_at`) VALUES
	(1, '2024/2025', 2024, 2025, NULL, NULL),
	(2, '2025/2026', 2025, 2026, NULL, NULL);

/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
