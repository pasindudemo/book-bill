-- ============================================================
-- setup.sql  -  Run this ONCE in phpMyAdmin (SQL tab)
-- ============================================================

-- 1. Create the database (utf8 so book titles store properly)
CREATE DATABASE IF NOT EXISTS bookshop
  DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;

-- 2. Tell MySQL to use it for the commands below
USE bookshop;

-- ------------------------------------------------------------
-- TABLE 1: books  (the inventory)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS books (
  book_id    INT NOT NULL AUTO_INCREMENT,   -- unique id, MySQL numbers it for us
  title      VARCHAR(150) NOT NULL,         -- book title
  author     VARCHAR(100) NOT NULL DEFAULT '',
  price      DECIMAL(10,2) NOT NULL,        -- selling price, 2 decimal places
  stock_qty  INT NOT NULL DEFAULT 0,        -- copies currently in the shop
  PRIMARY KEY (book_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE 2: bills  (one row per bill)
-- bill_id is AUTO_INCREMENT, so every new bill automatically
-- gets a NEW, UNIQUE bill number (1, 2, 3 ...)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bills (
  bill_id         INT NOT NULL AUTO_INCREMENT,
  bill_date       DATETIME NOT NULL,                    -- date and time of the sale
  total           DECIMAL(10,2) NOT NULL DEFAULT 0,     -- bill value
  payment_method  VARCHAR(10) NOT NULL,                 -- Cash / Card / Online
  cash_given      DECIMAL(10,2) NOT NULL DEFAULT 0,     -- only used for Cash
  balance         DECIMAL(10,2) NOT NULL DEFAULT 0,     -- change given back (Cash)
  PRIMARY KEY (bill_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- TABLE 3: bill_items  (the books on each bill)
-- One bill has MANY items, so items live in their own table
-- and point back to the bill using bill_id.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS bill_items (
  item_id     INT NOT NULL AUTO_INCREMENT,
  bill_id     INT NOT NULL,                  -- which bill this line belongs to
  book_id     INT NOT NULL,                  -- which book was sold
  qty         INT NOT NULL,                  -- how many copies
  unit_price  DECIMAL(10,2) NOT NULL,        -- price at the time of sale
  line_total  DECIMAL(10,2) NOT NULL,        -- qty x unit_price
  PRIMARY KEY (item_id),
  FOREIGN KEY (bill_id) REFERENCES bills(bill_id),
  FOREIGN KEY (book_id) REFERENCES books(book_id)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- OPTIONAL: sample books so you can test straight away
-- ------------------------------------------------------------
INSERT INTO books (title, author, price, stock_qty) VALUES
 ('Sample Novel',     'A. Writer',  850.00, 20),
 ('Sample Textbook',  'B. Teacher', 1500.00, 10),
 ('Sample Children Book', 'C. Author', 450.00, 30);