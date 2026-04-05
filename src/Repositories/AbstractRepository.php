<?php

declare(strict_types=1);

namespace Access402\Repositories;

use Access402\Support\Helpers;

abstract class AbstractRepository
{
    protected \wpdb $wpdb;

    public function __construct(?\wpdb $database = null)
    {
        global $wpdb;

        $this->wpdb = $database instanceof \wpdb ? $database : $wpdb;
    }

    abstract protected function table_suffix(): string;

    protected function table(): string
    {
        return Helpers::table($this->table_suffix());
    }

    public function find(int $id): ?array
    {
        $sql = $this->wpdb->prepare("SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id);
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        return is_array($row) ? $row : null;
    }

    public function delete(int $id): bool
    {
        return false !== $this->wpdb->delete($this->table(), ['id' => $id], ['%d']);
    }

    protected function insert_row(array $data): int
    {
        $this->wpdb->insert($this->table(), $data);

        return (int) $this->wpdb->insert_id;
    }

    protected function update_row(int $id, array $data): bool
    {
        return false !== $this->wpdb->update($this->table(), $data, ['id' => $id], null, ['%d']);
    }
}
