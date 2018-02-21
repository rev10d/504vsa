<?php

// initialize the database
try {
    $db = new PDO('sqlite:./data.db');
    $db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION  );
    $query = 'CREATE TABLE IF NOT EXISTS commands (id integer primary key, time_snd date, time_rcv date, agent_id text, command text, result text)';
    $db->query($query);
    $query = 'CREATE TABLE IF NOT EXISTS agents (id text primary key, interval int DEFAULT 10, last_poll date)';
    $db->query($query);
} catch (Exception $error) {
    die('database error');
}

?>
