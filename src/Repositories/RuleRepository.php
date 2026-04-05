<?php

declare(strict_types=1);

namespace Access402\Repositories;

use Access402\Domain\RuleStatusOptions;
use Access402\Support\Helpers;

final class RuleRepository extends AbstractRepository
{
    protected function table_suffix(): string
    {
        return 'rules';
    }

    public function query(array $args = []): array
    {
        $args = wp_parse_args(
            $args,
            [
                'search'   => '',
                'status'   => '',
                'per_page' => 20,
                'page'     => 1,
            ]
        );

        [$where, $params] = $this->build_where($args);
        $limit            = max(1, (int) $args['per_page']);
        $offset           = max(0, ((int) $args['page'] - 1) * $limit);
        $sql              = "
            SELECT *
            FROM {$this->table()}
            WHERE {$where}
            ORDER BY sort_order ASC, id ASC
            LIMIT %d OFFSET %d
        ";
        $prepared         = $this->wpdb->prepare($sql, array_merge($params, [$limit, $offset]));
        $rows             = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function count(array $args = []): int
    {
        [$where, $params] = $this->build_where($args);
        $sql              = "SELECT COUNT(*) FROM {$this->table()} WHERE {$where}";

        if ($params === []) {
            return (int) $this->wpdb->get_var($sql);
        }

        return (int) $this->wpdb->get_var($this->wpdb->prepare($sql, $params));
    }

    public function all_active_ordered(): array
    {
        $sql  = $this->wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE status = %s ORDER BY sort_order ASC, id ASC",
            RuleStatusOptions::ACTIVE
        );
        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function save(array $data, ?int $id = null): int
    {
        $data = wp_parse_args(
            $data,
            [
                'sort_order'                => $this->next_sort_order(),
                'name'                      => '',
                'path_pattern'              => '',
                'price_override'            => null,
                'unlock_behavior_override'  => null,
                'status'                    => RuleStatusOptions::ACTIVE,
                'updated_at'                => Helpers::now(),
            ]
        );

        if ($id === null) {
            $data['created_at'] = $data['updated_at'];

            return $this->insert_row($data);
        }

        $this->update_row($id, $data);

        return $id;
    }

    public function next_sort_order(): int
    {
        $max = (int) $this->wpdb->get_var("SELECT COALESCE(MAX(sort_order), 0) FROM {$this->table()}");

        return $max + 1;
    }

    public function update_order(array $ordered_ids): void
    {
        $ordered_ids = array_values(array_filter(array_map('absint', $ordered_ids)));

        foreach ($ordered_ids as $index => $id) {
            $this->update_row(
                $id,
                [
                    'sort_order' => $index + 1,
                    'updated_at' => Helpers::now(),
                ]
            );
        }
    }

    public function set_status(array $ids, string $status): int
    {
        $ids = array_values(array_filter(array_map('absint', $ids)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));
        $sql          = "
            UPDATE {$this->table()}
            SET status = %s, updated_at = %s
            WHERE id IN ({$placeholders})
        ";

        return (int) $this->wpdb->query(
            $this->wpdb->prepare($sql, array_merge([$status, Helpers::now()], $ids))
        );
    }

    public function delete_many(array $ids): int
    {
        $ids = array_values(array_filter(array_map('absint', $ids)));

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '%d'));

        return (int) $this->wpdb->query(
            $this->wpdb->prepare("DELETE FROM {$this->table()} WHERE id IN ({$placeholders})", $ids)
        );
    }

    public function touch_match(int $id): void
    {
        $sql = $this->wpdb->prepare(
            "
            UPDATE {$this->table()}
            SET hits_count = hits_count + 1,
                last_matched_at = %s
            WHERE id = %d
            ",
            Helpers::now(),
            $id
        );

        $this->wpdb->query($sql);
    }

    private function build_where(array $args): array
    {
        $where  = ['1=1'];
        $params = [];

        if (! empty($args['search'])) {
            $like    = '%' . $this->wpdb->esc_like((string) $args['search']) . '%';
            $where[] = '(name LIKE %s OR path_pattern LIKE %s)';
            $params  = array_merge($params, [$like, $like]);
        }

        if (! empty($args['status']) && RuleStatusOptions::is_valid((string) $args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }

        return [implode(' AND ', $where), $params];
    }
}
