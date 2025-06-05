<?php
require 'get_config.php';
require 'db.php';
$pdo = get_connection();

function execute_query_to_csv($pdo, $query, $filename) {
    $stmt = $pdo->query($query);
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $fp = fopen($filename, 'w');
    fputcsv($fp, array_keys(reset($result))); // header
    foreach ($result as $row) {
        #print_r($row);
        fputcsv($fp, $row);
    }
    fclose($fp);
}

// Paths to save the CSV files
$file1 = '/tmp/report_chats_by_user.csv';
$file2 = '/tmp/report_chats_by_day.csv';
$file3 = '/tmp/report_exchanges_by_user.csv';
$file4 = '/tmp/report_exchanges_by_day.csv';
$file5 = '/tmp/report_by_model.csv';

// Queries
$query1 = "
SELECT
  DATE(c.timestamp) AS chat_date,
  c.user,
  COUNT(DISTINCT c.id) AS count_chats
FROM
  chat c
  LEFT JOIN exchange e ON c.id = e.chat_id
WHERE
  c.timestamp > '2023-09-30'
GROUP BY
  DATE(c.timestamp), c.user
ORDER BY
  chat_date, c.user
";

$query2 = "
SELECT
  DATE(c.timestamp) AS chat_date,
  COUNT(DISTINCT c.id) AS count_chats
FROM
  chat c
  LEFT JOIN exchange e ON c.id = e.chat_id
WHERE
  c.timestamp > '2023-09-30'
GROUP BY
  DATE(c.timestamp)
ORDER BY
  chat_date
";
$query3 = "
SELECT
  DATE(e.timestamp) AS chat_date,
  c.user,
  COUNT(e.chat_id) AS count_exchanges
FROM
  chat c
  LEFT JOIN exchange e ON c.id = e.chat_id
GROUP BY
  DATE(e.timestamp), c.user
ORDER BY
  chat_date, c.user
";
$query4 = "
SELECT
  DATE(e.timestamp) AS chat_date,
  COUNT(e.chat_id) AS count_exchanges
FROM
  exchange e
GROUP BY
  DATE(e.timestamp)
ORDER BY
  chat_date
";
$query5 = "
SELECT
  DATE(e.timestamp) AS chat_date,
  e.deployment,
  COUNT(e.chat_id) AS count_exchanges
FROM
  exchange e
GROUP BY
  DATE(e.timestamp), e.deployment
ORDER BY
  chat_date, deployment
";

// Execute queries and save the results to CSV
execute_query_to_csv($pdo, $query1, $file1);
execute_query_to_csv($pdo, $query2, $file2);
execute_query_to_csv($pdo, $query3, $file3);
execute_query_to_csv($pdo, $query4, $file4);
execute_query_to_csv($pdo, $query5, $file5);

// CSV files generated and saved to /tmp or /scratch

