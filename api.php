<?php
// ============================================================
// api.php - the ONLY server file.
// JavaScript cannot talk to MySQL directly, so the web pages
// call this file, and this file reads/writes the database.
// Written for PHP 5.6 (XAMPP 5.6.40).
// Save this file as ANSI in Notepad (see tutorial).
// ============================================================

date_default_timezone_set('Asia/Colombo');   // so dates match local time
header('Content-Type: application/json');    // we always answer with JSON

// ---- Database settings (XAMPP defaults) ----
$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = '';            // XAMPP root has no password by default
$DB_NAME = 'bookshop';

$db = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($db->connect_error) {
    echo json_encode(array('error' => 'Database connection failed: ' . $db->connect_error));
    exit;
}
$db->set_charset('utf8');

// ---- Helper: send an error and stop ----
function fail($msg) {
    echo json_encode(array('error' => $msg));
    exit;
}

// ---- Helper: read the JSON that JavaScript posted ----
function getPostData() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (!is_array($data)) { fail('No data received'); }
    return $data;
}

// ---- Helper: make a text value safe to put inside an SQL string ----
function esc($db, $text) {
    return $db->real_escape_string(trim($text));
}

// ---- Helper: check a date looks like YYYY-MM-DD ----
function cleanDate($d) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) { fail('Bad date'); }
    return $d;
}

// Which job did the page ask for?  e.g. api.php?action=list_books
$action = isset($_GET['action']) ? $_GET['action'] : '';


// ============================================================
// ACTION 1: list_books  -> all books in the inventory
// ============================================================
if ($action == 'list_books') {
    $result = $db->query("SELECT book_id, title, author, price, stock_qty FROM books ORDER BY title");
    $rows = array();
    while ($r = $result->fetch_assoc()) { $rows[] = $r; }
    echo json_encode(array('books' => $rows));
    exit;
}


// ============================================================
// ACTION 2: add_book -> add a new book, OR add stock if the
//           same title + author already exists
// ============================================================
if ($action == 'add_book') {
    $d      = getPostData();
    $title  = esc($db, isset($d['title'])  ? $d['title']  : '');
    $author = esc($db, isset($d['author']) ? $d['author'] : '');
    $price  = (float)(isset($d['price']) ? $d['price'] : 0);
    $qty    = (int)(isset($d['qty'])     ? $d['qty']   : 0);

    if ($title == '' || $price <= 0 || $qty <= 0) { fail('Enter title, price and quantity'); }

    // Does this book already exist?
    $check = $db->query("SELECT book_id FROM books WHERE title='$title' AND author='$author'");
    if ($check->num_rows > 0) {
        $row = $check->fetch_assoc();
        $id  = (int)$row['book_id'];
        // Existing book: add the new copies and update the price
        $db->query("UPDATE books SET stock_qty = stock_qty + $qty, price = $price WHERE book_id = $id");
        echo json_encode(array('ok' => true, 'message' => 'Existing book - stock increased'));
    } else {
        $db->query("INSERT INTO books (title, author, price, stock_qty) VALUES ('$title','$author',$price,$qty)");
        echo json_encode(array('ok' => true, 'message' => 'New book added'));
    }
    exit;
}


// ============================================================
// ACTION 3: save_bill -> store the bill, reduce stock, and
//           return everything needed to print it
// ============================================================
if ($action == 'save_bill') {
    $d      = getPostData();
    $method = isset($d['payment_method']) ? $d['payment_method'] : '';
    $cash   = (float)(isset($d['cash_given']) ? $d['cash_given'] : 0);
    $items  = isset($d['items']) ? $d['items'] : array();

    if ($method != 'Cash' && $method != 'Card' && $method != 'Online') { fail('Choose a payment method'); }
    if (count($items) == 0) { fail('The bill is empty'); }

    // Start a transaction: either EVERYTHING is saved, or NOTHING is.
    $db->autocommit(false);

    // Insert the bill first (total = 0 for now) to get the new unique bill number
    $now = date('Y-m-d H:i:s');
    $db->query("INSERT INTO bills (bill_date, total, payment_method) VALUES ('$now', 0, '$method')");
    $billId = (int)$db->insert_id;       // <- the unique bill number

    $total = 0;
    $printItems = array();

    foreach ($items as $it) {
        $bookId = (int)$it['book_id'];
        $qty    = (int)$it['qty'];
        if ($qty <= 0) { $db->rollback(); fail('Bad quantity'); }

        // Read price + stock from the DATABASE (do not trust the browser).
        // FOR UPDATE locks the row so two cashiers cannot sell the last copy twice.
        $res = $db->query("SELECT title, price, stock_qty FROM books WHERE book_id=$bookId FOR UPDATE");
        if ($res->num_rows == 0) { $db->rollback(); fail('Book not found'); }
        $book = $res->fetch_assoc();

        if ($qty > (int)$book['stock_qty']) {
            $db->rollback();
            fail('Not enough stock for: ' . $book['title']);
        }

        $price     = (float)$book['price'];
        $lineTotal = round($price * $qty, 2);
        $total    += $lineTotal;

        // Save the bill line and reduce the stock
        $db->query("INSERT INTO bill_items (bill_id, book_id, qty, unit_price, line_total)
                    VALUES ($billId, $bookId, $qty, $price, $lineTotal)");
        $db->query("UPDATE books SET stock_qty = stock_qty - $qty WHERE book_id=$bookId");

        $printItems[] = array('title' => $book['title'], 'qty' => $qty,
                              'price' => $price, 'line_total' => $lineTotal);
    }

    $total = round($total, 2);

    // Cash handling: must pay enough, and we calculate the balance
    $balance = 0;
    if ($method == 'Cash') {
        if ($cash < $total) { $db->rollback(); fail('Cash given is less than the bill total'); }
        $balance = round($cash - $total, 2);
    } else {
        $cash = 0;
    }

    // Now write the final total, cash and balance onto the bill
    $db->query("UPDATE bills SET total=$total, cash_given=$cash, balance=$balance WHERE bill_id=$billId");

    $db->commit();   // everything worked - save permanently

    echo json_encode(array(
        'ok'         => true,
        'bill_no'    => $billId,
        'bill_date'  => $now,
        'items'      => $printItems,
        'total'      => $total,
        'method'     => $method,
        'cash_given' => $cash,
        'balance'    => $balance
    ));
    exit;
}


// ============================================================
// ACTION 4: report_daily -> collection per payment method
//           for one date (api.php?action=report_daily&date=2026-10-27)
// ============================================================
if ($action == 'report_daily') {
    $date = cleanDate(isset($_GET['date']) ? $_GET['date'] : '');
    $res  = $db->query("SELECT payment_method, COUNT(*) AS bill_count, SUM(total) AS amount
                        FROM bills
                        WHERE DATE(bill_date) = '$date'
                        GROUP BY payment_method");
    $rows = array();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    echo json_encode(array('rows' => $rows));
    exit;
}


// ============================================================
// ACTION 5: report_inventory -> for one date: copies sold that
//           day and copies remaining in stock (right now)
// ============================================================
if ($action == 'report_inventory') {
    $date = cleanDate(isset($_GET['date']) ? $_GET['date'] : '');
    $res  = $db->query("SELECT b.title, b.author,
                               IFNULL(s.sold, 0) AS sold,
                               b.stock_qty AS remaining
                        FROM books b
                        LEFT JOIN (
                            SELECT bi.book_id, SUM(bi.qty) AS sold
                            FROM bill_items bi
                            JOIN bills bl ON bl.bill_id = bi.bill_id
                            WHERE DATE(bl.bill_date) = '$date'
                            GROUP BY bi.book_id
                        ) s ON s.book_id = b.book_id
                        ORDER BY b.title");
    $rows = array();
    while ($r = $res->fetch_assoc()) { $rows[] = $r; }
    echo json_encode(array('rows' => $rows));
    exit;
}

// If we get here, the action name was not recognised
fail('Unknown action');
?>