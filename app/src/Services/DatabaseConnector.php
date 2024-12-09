<?php

namespace Services;

class DatabaseConnector
{
    static function getConnection(?string $dbName = null): \Doctrine\DBAL\Connection
    {
        $connectionParams = [
            'dbname' => $dbName ?? $_ENV['DB_NAME'],
            'user' => $_ENV['DB_USER'],
            'password' => $_ENV['DB_PASS'],
            'host' => $_ENV['DB_HOST'],
            'driver' => 'pdo_mysql',
            'charset' => 'utf8mb4'
        ];

        try {
            $connection = \Doctrine\DBAL\DriverManager::getConnection($connectionParams);
            $version = $connection->getServerVersion();
        } catch (\Doctrine\DBAL\Exception $e) {
            if ($_ENV['DEBUG']) {
                echo($e->getMessage() . PHP_EOL);
            } else {
                $filename = __DIR__ . '/../../storage/db.log';
                $file = new \SplFileObject($filename, 'a');
                $file->fwrite(
                    (new \DateTime())->format(\DateTimeInterface::RSS) .
                    ' - ' .
                    $e->getMessage() .
                    PHP_EOL);
                header('Location: /unavailable.html');
            }
            exit();
        }
        return $connection;
    }
}