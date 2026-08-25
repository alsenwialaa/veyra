<?php

declare(strict_types=1);

namespace Veyra\Infrastructure\Database\Migration;

interface Migration
{
    public function version(): string;

    public function up(\wpdb $database): void;
}

