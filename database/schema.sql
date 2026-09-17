CREATE DATABASE IF NOT EXISTS variations CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE variations;

CREATE TABLE systems (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE element_types (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  spacing ENUM('with_space', 'without_space') NOT NULL DEFAULT 'with_space',
  letter_case ENUM('mixed_case', 'initial_always_uppercase') NOT NULL DEFAULT 'mixed_case',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY unique_type_per_system (system_id, name),
  INDEX idx_types_system_id (system_id, id),
  CONSTRAINT fk_type_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE elements (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_id BIGINT UNSIGNED NOT NULL,
  element_type_id BIGINT UNSIGNED NOT NULL,
  text_value TEXT NOT NULL,
  text_value_hash BINARY(32) GENERATED ALWAYS AS (UNHEX(SHA2(text_value, 256))) STORED,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_element_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
  CONSTRAINT fk_element_type FOREIGN KEY (element_type_id) REFERENCES element_types(id) ON DELETE RESTRICT,
  INDEX idx_elements_system_type (system_id, element_type_id),
  INDEX idx_elements_system_id (system_id, id),
  UNIQUE KEY unique_element_per_system_type_text (system_id, element_type_id, text_value_hash)
) ENGINE=InnoDB;
CREATE TABLE combination_structures (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_id BIGINT UNSIGNED NOT NULL,
  name VARCHAR(120) NOT NULL,
  slots JSON NOT NULL COMMENT 'Ordered array of element type IDs',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_structure_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
  INDEX idx_structures_system_id (system_id, id)
) ENGINE=InnoDB;
CREATE TABLE generated_combinations (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  system_id BIGINT UNSIGNED NOT NULL,
  structure_id BIGINT UNSIGNED NOT NULL,
  value_text TEXT NOT NULL,
  element_ids JSON NOT NULL COMMENT 'Ordered array of selected element IDs',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_combination_system FOREIGN KEY (system_id) REFERENCES systems(id) ON DELETE CASCADE,
  CONSTRAINT fk_combination_structure FOREIGN KEY (structure_id) REFERENCES combination_structures(id) ON DELETE CASCADE,
  INDEX idx_combinations_system_id (system_id, id),
  UNIQUE KEY unique_generated_value (structure_id, value_text(255))
) ENGINE=InnoDB;
