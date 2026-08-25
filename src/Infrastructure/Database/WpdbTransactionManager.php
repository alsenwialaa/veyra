<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database;

final class WpdbTransactionManager
{
    public function __construct(private readonly \wpdb $database)
    {
    }

    /** @template T @param callable(): T $operation @return T */
    public function transactional(callable $operation): mixed
    {
        if ($this->database->query('START TRANSACTION') === false) {
            throw new \RuntimeException('Database transaction could not be started.');
        }

        try {
            $result = $operation();

            if ($this->database->query('COMMIT') === false) {
                throw new \RuntimeException('Database transaction could not be committed.');
            }

            return $result;
        } catch (\Throwable $error) {
            $this->database->query('ROLLBACK');
            throw $error;
        }
    }
}

