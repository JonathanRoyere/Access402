<?php

declare(strict_types=1);

namespace Access402\Repositories;

use Access402\Support\Helpers;

final class LogRepository extends AbstractRepository
{
    private const DEDUPE_SIGNATURE_FIELDS = [
        'matched_rule_id',
        'wallet_address',
        'message',
    ];

    protected function table_suffix(): string
    {
        return 'request_logs';
    }

    public function insert(array $data): int
    {
        $data = wp_parse_args(
            $data,
            [
                'logged_at'        => Helpers::now(),
                'path'             => '',
                'matched_rule_id'  => null,
                'decision'         => '',
                'wallet_address'   => null,
                'mode'             => 'test',
                'message'          => '',
                'created_at'       => Helpers::now(),
            ]
        );

        return $this->insert_row($data);
    }

    public function query(array $args = []): array
    {
        $args = wp_parse_args(
            $args,
            [
                'search_path' => '',
                'decision'    => '',
                'mode'        => '',
                'per_page'    => 30,
                'page'        => 1,
            ]
        );

        [$where, $params] = $this->build_where($args, 'logs');
        $limit            = max(1, (int) $args['per_page']);
        $offset           = max(0, ((int) $args['page'] - 1) * $limit);
        $rules_table      = Helpers::table('rules');
        $sql              = "
            SELECT logs.*, rules.name AS matched_rule_name
            FROM {$this->table()} logs
            LEFT JOIN {$rules_table} rules ON rules.id = logs.matched_rule_id
            WHERE {$where}
            ORDER BY logs.logged_at DESC, logs.id DESC
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

    public function has_recent_duplicate(array $data, int $window_seconds = 10): bool
    {
        $path     = Helpers::normalize_path((string) ($data['path'] ?? '/'));
        $decision = sanitize_key((string) ($data['decision'] ?? ''));
        $mode     = sanitize_key((string) ($data['mode'] ?? 'test'));

        if ($path === '' || $decision === '' || $mode === '') {
            return false;
        }

        $sql = $this->wpdb->prepare(
            "
                SELECT *
                FROM {$this->table()}
                WHERE path = %s
                    AND decision = %s
                    AND mode = %s
                ORDER BY logged_at DESC, id DESC
                LIMIT 1
            ",
            $path,
            $decision,
            $mode
        );
        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (! is_array($row)) {
            return false;
        }

        foreach (self::DEDUPE_SIGNATURE_FIELDS as $field) {
            if ((string) ($row[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                return false;
            }
        }

        $logged_at = strtotime((string) ($row['logged_at'] ?? ''));

        if (! is_int($logged_at)) {
            return false;
        }

        return $logged_at >= (time() - max(1, $window_seconds));
    }

    private function build_where(array $args, string $alias = ''): array
    {
        $column = static fn (string $name): string => $alias !== '' ? "{$alias}.{$name}" : $name;
        $where  = ['1=1'];
        $params = [];

        $where[] = $column('path') . " <> ''";
        $where[] = $column('path') . " <> '/'";
        $where[] = $column('path') . " <> '/robots.txt'";
        $where[] = $column('path') . " NOT LIKE '/favicon%'";
        $where[] = $column('path') . " NOT LIKE '/apple-touch-icon%'";

        if (! empty($args['search_path'])) {
            $like    = '%' . $this->wpdb->esc_like((string) $args['search_path']) . '%';
            $where[] = $column('path') . ' LIKE %s';
            $params[] = $like;
        }

        foreach (['decision', 'mode'] as $key) {
            if (! empty($args[$key])) {
                $where[]  = $column($key) . ' = %s';
                $params[] = (string) $args[$key];
            }
        }

        return [implode(' AND ', $where), $params];
    }
}
