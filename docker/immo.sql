-- MySQL Workbench Forward Engineering

SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0;
SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0;
SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

-- -----------------------------------------------------
-- Schema mydb
-- -----------------------------------------------------
-- -----------------------------------------------------
-- Schema immo
-- -----------------------------------------------------

-- -----------------------------------------------------
-- Schema immo
-- -----------------------------------------------------
CREATE SCHEMA IF NOT EXISTS `immo` DEFAULT CHARACTER SET utf8mb4 ;
USE `immo` ;

-- -----------------------------------------------------
-- Table `immo`.`agencies`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`agencies` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(15) NULL DEFAULT NULL,
  `email` VARCHAR(255) NOT NULL,
  `description` VARCHAR(255) NULL DEFAULT NULL,
  `address` VARCHAR(255) NOT NULL,
  `city` VARCHAR(255) NOT NULL,
  `postal` VARCHAR(50) NOT NULL,
  `website` VARCHAR(255) NULL DEFAULT NULL,
  `logo` VARCHAR(255) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`users`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `phone` VARCHAR(20) NULL DEFAULT NULL,
  `role` ENUM('client', 'employee', 'admin') NOT NULL DEFAULT 'client',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE INDEX `email` (`email` ASC) VISIBLE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`agencies_employees`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`agencies_employees` (
  `user_id` INT UNSIGNED NOT NULL,
  `agency_id` INT UNSIGNED NOT NULL,
  PRIMARY KEY (`user_id`, `agency_id`),
  INDEX `agency_id` (`agency_id` ASC) VISIBLE,
  CONSTRAINT `agencies_employees_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `agencies_employees_ibfk_2`
    FOREIGN KEY (`agency_id`)
    REFERENCES `immo`.`agencies` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`properties`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`properties` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT NOT NULL,
  `agency_id` INT UNSIGNED NOT NULL,
  `address` VARCHAR(255) NOT NULL,
  `postal` VARCHAR(50) NOT NULL,
  `city` VARCHAR(255) NOT NULL,
  `price` DECIMAL(20,2) NOT NULL,
  `build_year` INT NULL DEFAULT NULL,
  `bedrooms` SMALLINT NULL DEFAULT NULL,
  `bathrooms` SMALLINT NULL DEFAULT NULL,
  `living_area` DECIMAL(20,2) NULL DEFAULT NULL,
  `surface_area` DECIMAL(20,2) NULL DEFAULT NULL,
  `type` ENUM('house', 'apartment', 'land', 'garage', 'business') NOT NULL,
  `sub_type` ENUM(
      -- HOUSE
        'house;terraced house',
        'house;semi-detached house',
        'house;detached house',
        'house;country house',
        'house;manor house',
        'house;villa',
        'house;castle',
        'house;townhouse',
        'house;cottage',
        'apartment;studio',
        'apartment;duplex',
        'apartment;triplex',
        'apartment;penthouse',
        'land;residential',
        'land;industrial',
        'land;commercial',
        'land;agricultural',
        'land;recreational',
        'land;mixed',
        'business;hotel',
        'business;restaurant',
        'business;retail space',
        'business;office space',
        'business;industrial space',
        'business;hospitality space',
        'business;storage space',
        'garage;single',
        'garage;double',
        'garage;box',
        'garage;underground',
        'garage;carport',
        'garage;parking space'
    ) DEFAULT NULL,
  `status` ENUM('for sale', 'for rent','for auction', 'sold', 'rented') NOT NULL,
  `visibility` ENUM('public', 'private', 'archived') NOT NULL DEFAULT 'public',
  `auction` TINYINT(1) NULL DEFAULT '0',
  `epc_label` ENUM('A+', 'A', 'B', 'C', 'D', 'E', 'F') NULL DEFAULT NULL,
  `epc_value` INT NULL DEFAULT NULL,
  `floors` INT NULL DEFAULT NULL,
  `availability` TINYINT(1) NULL DEFAULT '1',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `agency_id` (`agency_id` ASC) VISIBLE,
  CONSTRAINT `properties_ibfk_1`
    FOREIGN KEY (`agency_id`)
    REFERENCES `immo`.`agencies` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`auctions`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`auctions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `property_id` INT UNSIGNED NOT NULL,
  `starting_price` DECIMAL(20,2) NOT NULL,
  `starting_time` DATETIME NOT NULL,
  `ending_time` DATETIME NOT NULL,
  `overbidding` INT NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `property_id` (`property_id` ASC) VISIBLE,
  CONSTRAINT `auctions_ibfk_1`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`bids`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`bids` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `auction_id` INT UNSIGNED NOT NULL,
  `bid_amount` DECIMAL(20,2) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `user_id` (`user_id` ASC) VISIBLE,
  INDEX `auction_id` (`auction_id` ASC) VISIBLE,
  CONSTRAINT `bids_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `bids_ibfk_2`
    FOREIGN KEY (`auction_id`)
    REFERENCES `immo`.`auctions` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`features`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`features` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(255) NOT NULL,
  PRIMARY KEY (`id`))
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`favourites`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`favourites` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `property_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `user_id` (`user_id` ASC) VISIBLE,
  INDEX `property_id` (`property_id` ASC) VISIBLE,
  CONSTRAINT `favourites_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `favourites_ibfk_2`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`notifications`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`notifications` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `property_id` INT UNSIGNED NOT NULL,
  `read` TINYINT(1) NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `user_id` (`user_id` ASC) VISIBLE,
  INDEX `property_id` (`property_id` ASC) VISIBLE,
  CONSTRAINT `notifications_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `notifications_ibfk_2`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`properties_features`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`properties_features` (
  `property_id` INT UNSIGNED NOT NULL,
  `feature_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`property_id`, `feature_id`),
  INDEX `facility_id` (`feature_id` ASC) VISIBLE,
  CONSTRAINT `properties_facilities_ibfk_1`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `properties_facilities_ibfk_2`
    FOREIGN KEY (`feature_id`)
    REFERENCES `immo`.`features` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`property_images`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`property_images` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `property_id` INT UNSIGNED NOT NULL,
  `image_path` VARCHAR(255) NOT NULL,
  `is_main` TINYINT(1) NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `property_id` (`property_id` ASC) VISIBLE,
  CONSTRAINT `property_images_ibfk_1`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`reviews`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`reviews` (
  `user_id` INT UNSIGNED NOT NULL,
  `agency_id` INT UNSIGNED NOT NULL,
  `rating` TINYINT NOT NULL,
  `title` VARCHAR(255) NOT NULL,
  `content` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `agency_id`),
  INDEX `agency_id` (`agency_id` ASC) VISIBLE,
  CONSTRAINT `reviews_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `reviews_ibfk_2`
    FOREIGN KEY (`agency_id`)
    REFERENCES `immo`.`agencies` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`search_filters`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`search_filters` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NULL DEFAULT NULL,
  `filters` JSON NOT NULL,
  `name` VARCHAR(100) NULL DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `user_id` (`user_id` ASC) VISIBLE,
  CONSTRAINT `search_filters_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;


-- -----------------------------------------------------
-- Table `immo`.`view_history`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`view_history` (
  `user_id` INT UNSIGNED NOT NULL,
  `property_id` INT UNSIGNED NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`user_id`, `property_id`),
  INDEX `property_id` (`property_id` ASC) VISIBLE,
  CONSTRAINT `view_history_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `view_history_ibfk_2`
    FOREIGN KEY (`property_id`)
    REFERENCES `immo`.`properties` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;

-- -----------------------------------------------------
-- Table `immo`.`refresh_tokens`
-- -----------------------------------------------------
CREATE TABLE IF NOT EXISTS `immo`.`refresh_tokens` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `token` TEXT NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `revoked` TINYINT(1) NOT NULL DEFAULT '0',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `user_id` (`user_id` ASC) VISIBLE,
  INDEX `expires_at` (`expires_at` ASC) VISIBLE,
  CONSTRAINT `refresh_tokens_ibfk_1`
    FOREIGN KEY (`user_id`)
    REFERENCES `immo`.`users` (`id`)
    ON DELETE CASCADE)
ENGINE = InnoDB
DEFAULT CHARACTER SET = utf8mb4;



SET SQL_MODE=@OLD_SQL_MODE;
SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS;
SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS;
