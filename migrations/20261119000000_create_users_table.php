<?php

return [
    'up' => function (PDO $pdo) {
        $pdo->exec(
            'CREATE TABLE users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            )'
        );
    },
    'down' => function (PDO $pdo) {
        $pdo->exec('DROP TABLE users');
    },
];
