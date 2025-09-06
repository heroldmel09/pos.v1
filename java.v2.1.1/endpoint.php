<?php

// Log file path
$log_file = __DIR__ . "/request_log.txt";

// Request details
$log_entry = str_replace("/pos_backend/endpoint.php?command=", "", $_SERVER['REQUEST_URI']);


// Append to file
file_put_contents($log_file, $log_entry . "\n", FILE_APPEND);


include "conn.php";

ini_set('display_errors', 0);
error_reporting(0);

if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: *");
    header("Access-Control-Max-Age: 60");

    if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
        header("Access-Control-Allow-Methods: POST, OPTIONS");
        header("Access-Control-Allow-Headers: Authorization, Content-Type, Accept, Origin, cache-control");
        http_response_code(200);
        die;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "method_not_allowed"]);
    die;
}

function print_Jsonresponse($dictionary = [], $error = "none")
{
    echo json_encode([
        "error" => $error,
        "command" => $_REQUEST['command'] ?? '',
        "response" => $dictionary
    ]);
    exit;
}

if (!isset($_REQUEST['command']) || $_REQUEST['command'] === null) {
    print_Jsonresponse([], "missing_command");
}

if (!isset($_REQUEST['data']) || $_REQUEST['data'] === null) {
    print_Jsonresponse([], "missing_data");
}

$response_json = json_decode($_REQUEST['data'], true);
if ($response_json === null) {
    print_Jsonresponse([], "invalid_json");
}


function anti_sql($value)
{
    global $conn;
    return mysqli_real_escape_string($conn, $value);
}

switch ($_REQUEST['command']) {
    case "register":
        if (
            empty($response_json["name"]) ||
            empty($response_json["username"]) ||
            empty($response_json["email"]) ||
            empty($response_json["password"])
        ) {
            print_Jsonresponse([], "missing_parameters");
        }

        $name = anti_sql($response_json["name"]);
        $username = anti_sql($response_json["username"]);
        $email = anti_sql($response_json["email"]);
        $password = anti_sql($response_json["password"]);

        $check_email_sql = "SELECT id FROM users WHERE email='$email'";
        $check_email_result = mysqli_query($conn, $check_email_sql);

        if (mysqli_num_rows($check_email_result) > 0) {
            print_Jsonresponse([], "email_already_exists");
        }

        $check_username_sql = "SELECT id FROM users WHERE username='$username'";
        $check_username_result = mysqli_query($conn, $check_username_sql);

        if (mysqli_num_rows($check_username_result) > 0) {
            print_Jsonresponse([], "username_already_exists");
        }

        $sql = "INSERT INTO users (name, username, email, password) 
                VALUES ('$name', '$username', '$email', '$password')";
        $result = mysqli_query($conn, $sql);

        if ($result) {
            print_Jsonresponse(["ok" => true, "message" => "User registered successfully"]);
        } else {
            print_Jsonresponse([], "insert_failed");
        }
        break;

    case "login":
        if (empty($response_json["username"]) || empty($response_json["password"])) {
            print_Jsonresponse([], "missing_parameters");
        }

        $username = anti_sql($response_json["username"]);
        $password = anti_sql($response_json["password"]);

        $sql = "SELECT * FROM users WHERE username='$username' AND password='$password'";
        $result = mysqli_query($conn, $sql);

        if (!$result || mysqli_num_rows($result) !== 1) {
            print_Jsonresponse([], "invalid_credentials");
        }

        $user = mysqli_fetch_assoc($result);

        print_Jsonresponse([
            "ok" => true,
            "message" => "Login successful",
            "user" => [
                "id" => $user["id"],
                "name" => $user["FirstName"] . $user["LastName"],
                "username" => $user["username"],
                "email" => $user["email"],
                "user_type"=> $user["user_type"],
                "password" => $user["password"],
            ]
        ]);
        break;

    case "create_transaction":
        //  Required fields
        $required_fields = [
            "user_id",
            "customer_name",
            "container",
            "total_amount",
            "payment",
            "pos_name",
            "area_id",
            "unit_price",
            "image_base64",
            "days_counter",
            "item_id"
        ];

        foreach ($required_fields as $field) {
            if (!isset($response_json[$field]) || $response_json[$field] === "") {
                echo json_encode(["status" => "error", "message" => "missing_parameters: $field"]);
                exit;
            }
        }

        // SVG to Base64 conversion
        $svg_path_data = $response_json["image_base64"];
        $svg_content = '<?xml version="1.0" encoding="UTF-8" standalone="no"?>'
            . '<svg xmlns="http://www.w3.org/2000/svg" width="200" height="200">'
            . '<path d="' . $svg_path_data . '" fill="none" stroke="#000" stroke-width="2"/>'
            . '</svg>';

        $temp_svg = tempnam(sys_get_temp_dir(), "svg_") . ".svg";
        file_put_contents($temp_svg, $svg_content);
        $image_data = file_get_contents($temp_svg);
        unlink($temp_svg);

        //  Sanitize & assign
        $user_id        = (int)$response_json["user_id"];
        $customer_name  = $response_json["customer_name"];
        $container      = (int)$response_json["container"];
        $total_amount   = (float)$response_json["total_amount"];
        $payment        = (float)$response_json["payment"];
        $pos_name       = $response_json["pos_name"];
        $area_id        = (int)$response_json["area_id"];
        $sold_price     = (float)$response_json["unit_price"];
        $image_base64   = base64_encode($image_data);
        $days_counter   = (int)$response_json["days_counter"];
        $item_id        = (int)$response_json["item_id"];
        $notes          = $response_json["notes"] ?? "";
        $wc_swap        = $response_json["wc_swap"] ?? 0;
        $isDeleted      = 0;

        // 🔹 Fetch original unit_price from containers by item_id
        $original_price = 0;
        $stmtOrig = $conn->prepare("SELECT unit_price FROM containers WHERE id = ? LIMIT 1");
        $stmtOrig->bind_param("i", $item_id);
        $stmtOrig->execute();
        $stmtOrig->bind_result($original_price);
        $stmtOrig->fetch();
        $stmtOrig->close();

        //  Balance calculation
        $balance = $total_amount - $payment;
        if ($balance < 0) $balance = 0;

        //  Get previous balance
        $previous_balance = 0;
        $stmt = $conn->prepare("
            SELECT (balance + previous_balance) AS total_balance
            FROM transactions
            WHERE customer_name = ?
            ORDER BY id DESC
            LIMIT 1
        ");
        $stmt->bind_param("s", $customer_name);
        $stmt->execute();
        $stmt->bind_result($total_balance);
        if ($stmt->fetch()) {
            $previous_balance = $total_balance;
        }
        $stmt->close();

        //  Transaction with rollback safety
        $conn->begin_transaction();
        
        try {
            //  Check total available containers for this item_id
            $remainingContainers = 0;
            $stmtCheck = $conn->prepare("SELECT SUM(containers_remaining) AS total_remaining 
                                        FROM containers 
                                        WHERE item_id = ?");
            $stmtCheck->bind_param("i", $item_id);
            $stmtCheck->execute();
            $stmtCheck->bind_result($remainingContainers);
            $stmtCheck->fetch();
            $stmtCheck->close();

            if ($remainingContainers < $container) {
                throw new Exception("Insufficient remaining quantity for this item. Current remaining: $remainingContainers");
            }

            // Insert transaction
            $swap_container = $container;
            $stmt2 = $conn->prepare("
                INSERT INTO transactions (
                    user_id, customer_name, container, total_amount, payment, balance, previous_balance,
                    wc_swap, pos_name, area_id, unit_price, image_base64, swap_container,
                    days_counter, notes, isDeleted, item_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt2->bind_param(
                "isiddddssidsiisii",
                $user_id,
                $customer_name,
                $container,
                $total_amount,
                $payment,
                $balance,
                $previous_balance,
                $wc_swap,
                $pos_name,
                $area_id,
                $sold_price,
                $image_base64,
                $swap_container,
                $days_counter,
                $notes,
                $isDeleted,
                $item_id
            );
            $stmt2->execute();
            $transaction_id = $conn->insert_id;
            $stmt2->close();

            //Insert into transactionsnapshot
            $stmtSnap = $conn->prepare("
                INSERT INTO transactionsnapshots (
                    user_id, transaction_id, payment, balance, previous_balance, date_created, isDeleted
                ) VALUES (?, ?, ?, ?, ?, NOW(), 0)
            ");
            $stmtSnap->bind_param(
                "iiddd",
                $user_id,
                $transaction_id,
                $payment,
                $balance,
                $previous_balance
            );
            $stmtSnap->execute();
            $stmtSnap->close();

            //  Deduct containers (start with lowest containers_remaining first)

            $containers_needed = $container;
            $result2 = $conn->query("SELECT id, containers_remaining, unit_price 
                                    FROM containers 
                                    WHERE item_id = $item_id AND containers_remaining > 0 
                                    ORDER BY containers_remaining ASC");

            while ($containers_needed > 0 && ($row2 = $result2->fetch_assoc())) {
                $available   = (int)$row2["containers_remaining"];
                $cost_price  = (float)$row2["unit_price"];
                $containerId = (int)$row2["id"];

                if ($available >= $containers_needed) {
                    // Deduct only what is needed

                    $profit = ($sold_price - $cost_price) * $containers_needed;

                    $stmtProfit = $conn->prepare("INSERT INTO transactioncontainerprofits 
                        (transactions_id, containers_id, profit, no_container, date_created) 
                        VALUES (?, ?, ?, ?, NOW())");
                    $stmtProfit->bind_param("iidi", $transaction_id, $containerId, $profit, $containers_needed);
                    $stmtProfit->execute();
                    $stmtProfit->close();

                    $conn->query("UPDATE containers 
                                SET containers_remaining = containers_remaining - $containers_needed 
                                WHERE id = $containerId");

                    $containers_needed = 0;
                } else {
                    // Deduct everything from this container and continue
                    
                    $profit = ($sold_price - $cost_price) * $available;

                    $stmtProfit = $conn->prepare("INSERT INTO transactioncontainerprofits 
                        (transactions_id, containers_id, profit, no_container, date_created) 
                        VALUES (?, ?, ?, ?, NOW())");
                    $stmtProfit->bind_param("iidi", $transaction_id, $containerId, $profit, $available);
                    $stmtProfit->execute();
                    $stmtProfit->close();

                    $conn->query("UPDATE containers SET containers_remaining = 0 WHERE id = $containerId");

                    $containers_needed -= $available;
                }
            }

            //  Commit everything
            $conn->commit();
            echo json_encode(["status" => "success", "message" => "Transaction created successfully", "transaction_id" => $transaction_id]);

        } catch (Exception $e) {
            $conn->rollback();
            echo json_encode(["status" => "error", "message" => $e->getMessage()]);
        }
        break;



    case "search_customers":
        if (empty($response_json["query"])) {
            print_Jsonresponse(["customers" => []], "missing_parameters");
        }

        $query = anti_sql($response_json["query"]);
        $sql = "SELECT DISTINCT customer_name FROM transactions WHERE customer_name LIKE '%$query%' LIMIT 10";
        $result = mysqli_query($conn, $sql);

        $customers = [];
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $customers[] = $row["customer_name"];
            }
        }

        print_Jsonresponse(["customers" => $customers]);
        break;

    case "fetch_transactions":
        $search = isset($response_json["search"]) ? anti_sql($response_json["search"]) : "";
        $area = isset($response_json["area"]) ? anti_sql($response_json["area"]) : "";

        $sql = "
            SELECT 
                t.id,
                t.customer_name,
                t.date_created AS created_at,
                t.balance,
                t.total_amount,
                a.name,
                i.item_name
            FROM transactions t
            LEFT JOIN areas a ON t.area_id = a.id
            LEFT JOIN item i ON t.item_id = i.id
            WHERE t.balance > 0 AND t.isDeleted = 0

        ";

        if (!empty($search)) {
            $sql .= " AND t.customer_name LIKE '%$search%'";
        }

        if (!empty($area)) {
            $sql .= " AND t.area_id = '$area'";
        }

        $sql .= " ORDER BY t.id DESC";

        $result = mysqli_query($conn, $sql);
        $transactions = [];

        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $transactions[] = $row;
            }
        }

        print_Jsonresponse(["transactions" => $transactions]);
        break;


    case "fetch_items":
        $stmt = $conn->prepare("SELECT DISTINCT i.id, i.item_name, c.unit_price FROM item i JOIN containers c ON c.item_id = i.id ORDER BY i.item_name ASC");
        $stmt->execute();
        $result = $stmt->get_result();

        $items = [];
        while ($row = $result->fetch_assoc()) {
            $items[] = $row;
        }

        print_Jsonresponse([
            "ok" => true,
            "items" => $items
        ]);
        break;

    case "fetch_areas":
        $stmt = $conn->prepare("SELECT id, `name` FROM areas WHERE active = 1 ORDER BY `name` ASC");
        $stmt->execute();
        $result = $stmt->get_result();

        $areas = [];
        while ($row = $result->fetch_assoc()) {
            $areas[] = $row;
        }

        print_Jsonresponse([
            "ok" => true,
            "areas" => $areas
        ]);
        break;

    case "fetch_transaction_by_id":
        $id = isset($response_json["id"]) ? intval($response_json["id"]) : 0;

        $sql = "
            SELECT
                t.*,
                t.date_created AS created_at,
                a.name AS area_name,
                i.item_name
            FROM
                transactions t
            LEFT JOIN areas a ON
                t.area_id = a.id
            LEFT JOIN item i ON
                t.item_id = i.id
            WHERE
                t.id = $id
            LIMIT 1
        ";

        $result = mysqli_query($conn, $sql);
        $transaction = null;

        if ($result && mysqli_num_rows($result) > 0) {
            $transaction = mysqli_fetch_assoc($result);
        }

        if ($transaction) {
            print_Jsonresponse(["transaction" => $transaction]);
        } else {
            print_Jsonresponse(["message" => "Error while fetching data"]);
        }
        break;

    case "update_balance":
        if (!isset($response_json["transactionID"]) || !isset($response_json["payment"])) {
            print_Jsonresponse([], "missing_parameters");
        }

        $transactionID = (int)$response_json["transactionID"];
        $payment = (float)$response_json["payment"];
        $userID = (int)$response_json["user_id"];
        $prevBalance = (float)$response_json["previous_balance"];

        // Fetch existing transaction
        $sql = "SELECT balance, previous_balance, user_id, payment FROM transactions WHERE id = $transactionID LIMIT 1";
        $result = mysqli_query($conn, $sql);

        if (!$result || mysqli_num_rows($result) === 0) {
            print_Jsonresponse([], "transaction_not_found");
        }

        $transaction = mysqli_fetch_assoc($result);
        $current_balance = (float)$transaction["balance"];
        $previous_balance = (float)$transaction["previous_balance"];
        $user_id = (int)$transaction["user_id"];
        $current_payment = (float)$transaction["payment"]; // existing payment

        // Calculate new balance and total payment
        $new_balance = $current_balance - $payment;
        if ($new_balance < 0) $new_balance = 0;
        $newPrevBalance = $current_balance + $prevBalance;

        $new_payment = $current_payment + $payment; // updated payment

        // Update transaction
        $update_sql = "UPDATE transactions 
                    SET balance = $new_balance, 
                        previous_balance = $current_balance, 
                        payment = $new_payment
                    WHERE id = $transactionID";
        $update_result = mysqli_query($conn, $update_sql);

        if ($update_result) {
            // Update transaction snapshot
            $updateSnap = $conn->prepare("
                INSERT INTO transactionsnapshots (
                    user_id, transaction_id, payment, balance, previous_balance, date_created, isDeleted
                ) VALUES (?, ?, ?, ?, ?, NOW(), 0)"
            );
            $updateSnap->bind_param("iiddd", $userID, $transactionID, $payment, $new_balance, $newPrevBalance);
            $updateSnap->execute();
            $updateSnap->close();

            print_Jsonresponse(["ok" => true, "new_balance" => $new_balance, "new_payment" => $new_payment]);
        } else {
            print_Jsonresponse([], "update_failed");
        }
        break;



    case "dashboard_stats":
        $sales = 0;
        $payments = 0;
        $profits = 0;
        $prev_balance = 0;

        // sum total_amount from transactions
        $sql = "
                SELECT
                    SUM(total_amount) AS total_sales,
                    SUM(payment) AS total_payments,
                    SUM(previous_balance) AS total_prev_balance,
                    SUM(balance) AS current_balance
                FROM
                    transactions
                ";
        $result = $conn->query($sql);
        if ($result && $row = $result->fetch_assoc()) {
            $sales = $row["total_sales"] ?? 0;
            $payments = $row["total_payments"] ?? 0;
            $prev_balance = $row["total_prev_balance"] ?? 0;
            $current_balance = $row["current_balance"] ?? 0;
        }

        // sum profit from transactioncontainerprofits
        $sql2 = "SELECT SUM(profit) as total_profits FROM transactioncontainerprofits";
        $result2 = $conn->query($sql2);
        if ($result2 && $row2 = $result2->fetch_assoc()) {
            $profits = $row2["total_profits"] ?? 0;
        }

        $response = [
            "sales" => (float)$sales,
            "payments" => (float)$payments,
            "profits" => (float)$profits,
            "prev_balance" => (float)$prev_balance,
            "current_balance" => (float)$current_balance
        ];

        print_Jsonresponse($response, "ok");
        break;

    case "fetch_containers":
        $sql = "SELECT * FROM containers";
        $result = $conn->query($sql);

        if ($result && $result->num_rows > 0) {
            $rows = [];
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            print_Jsonresponse([
                "ok"=> true,
                "data" => $rows
            ]);
        } else {
            print_Jsonresponse([]);
        }
        break;

    case "quota_logs":
        $sqlUsers = "
            SELECT id, CONCAT(FirstName,' ',LastName) AS name, username,email FROM users WHERE LOWER(user_type) = 'employee'
        ";
        $resultUsers = $conn->query($sqlUsers);

        $quotaLogs = [];

        if ($resultUsers && $resultUsers->num_rows > 0) {
            while ($user = $resultUsers->fetch_assoc()) {
                $userId = (int)$user['id'];

                // Get transactions for this user
                $sqlTransactions = "
                    SELECT t.id, t.customer_name, t.total_amount, t.date_created, t.item_id, i.item_name
                    FROM transactions t
                    LEFT JOIN item i ON t.item_id = i.id
                    WHERE t.user_id = $userId
                    ORDER BY t.date_created DESC
                ";
                $resultTrans = $conn->query($sqlTransactions);

                $transactions = [];
                $totalAmount = 0;

                if ($resultTrans && $resultTrans->num_rows > 0) {
                    while ($trans = $resultTrans->fetch_assoc()) {
                        $transactions[] = [
                            "transaction_id" => $trans["id"],
                            "customer_name" => $trans["customer_name"],
                            "total_amount" => (float)$trans["total_amount"],
                            "item_id" => $trans["item_id"],
                            "item_name" => $trans["item_name"],
                            "date_created" => $trans["date_created"]
                        ];
                        $totalAmount += (float)$trans["total_amount"];
                    }
                }

                $quotaLogs[] = [
                    "user_id" => $userId,
                    "name" => $user["name"],
                    "username" => $user["username"],
                    "email" => $user["email"],
                    "total_amount" => $totalAmount,
                    "transactions" => $transactions
                ];
            }
        }

        print_Jsonresponse(["ok" => true, "data" => $quotaLogs]);
        break;


    case "test_API":
        print_Jsonresponse([
            "ok" => true,
            "message" => "Test API working fine!",
            "timestamp" => date("Y-m-d H:i:s")
        ]);
        break;


    default:
        print_Jsonresponse([], "invalid_command");
}
