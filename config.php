<?php

// connect database postgresql
$db = new PDO(
    "pgsql:host=localhost;port=5432;dbname=home_assistant;user=postgres;password=root"
);

// exception jika ada error query
$db->setAttribute(
    PDO::ATTR_ERRMODE,
    PDO::ERRMODE_EXCEPTION
);
