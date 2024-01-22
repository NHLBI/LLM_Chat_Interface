# NHLBI-chat
Site files for the NHLBI-chat environment

This is the query for daily prompts by user:
MariaDB [osi_chat]> 
SELECT
  DATE(c.timestamp) AS chat_date,
  c.user,
  COUNT(DISTINCT c.id) AS count_chats,
  COUNT(e.chat_id) AS count_exchanges
FROM
  chat c
  LEFT JOIN exchange e ON c.id = e.chat_id
WHERE
  c.timestamp > '2023-09-30'
GROUP BY
  DATE(c.timestamp), c.user
ORDER BY
  chat_date, c.user;


This is the daily prompts by day:
SELECT
  DATE(c.timestamp) AS chat_date,
  COUNT(DISTINCT c.id) AS count_chats,
  COUNT(e.chat_id) AS count_exchanges
FROM
  chat c
  LEFT JOIN exchange e ON c.id = e.chat_id
WHERE
  c.timestamp > '2023-09-30'
GROUP BY
  DATE(c.timestamp)
ORDER BY
  chat_date;

# webchat1
