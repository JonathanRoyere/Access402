<?php

declare(strict_types=1);

namespace Access402\Repositories;

use Access402\Domain\RuleStatusOptions;
use Access402\Support\Helpers;

final class TrustedWalletRepository extends AbstractRepository
{
    protected function table_suffix(): string
    {
        return 'trusted_wallets';
    }

    public function query(array $args = []): array
    {
        $args = wp_parse_args(
            $args,
            [
                'search' => '',
                'status' => '',
            ]
        );

        [$where, $params] = $this->build_where($args);
        $sql              = "SELECT * FROM {$this->table()} WHERE {$where} ORDER BY created_at DESC, id DESC";
        $prepared         = $params === [] ? $sql : $this->wpdb->prepare($sql, $params);
        $rows             = $this->wpdb->get_results($prepared, ARRAY_A);

        return is_array($rows) ? $rows : [];
    }

    public function active(): array
    {
        return $this->query(['status' => RuleStatusOptions::ACTIVE]);
    }

    public function save(array $data, ?int $id = null): int
    {
        $data = wp_parse_args(
            $data,
            [
                'label'          => '',
                'wallet_address' => '',
                'wallet_type'    => 'other',
                'status'         => RuleStatusOptions::ACTIVE,
                'updated_at'     => Helpers::now(),
            ]
        );

        if ($id === null) {
            $data['created_at'] = $data['updated_at'];

            return $this->insert_row($data);
        }

        $this->update_row($id, $data);

        return $id;
    }

    public function set_status(int $id, string $status): bool
    {
        return $this->update_row(
            $id,
            [
                'status'     => $status,
                'updated_at' => Helpers::now(),
            ]
        );
    }

    private function build_where(array $args): array
    {
        $where  = ['1=1'];
        $params = [];

        if (! empty($args['search'])) {
            $like    = '%' . $this->wpdb->esc_like((string) $args['search']) . '%';
            $where[] = '(label LIKE %s OR wallet_address LIKE %s)';
            $params  = array_merge($params, [$like, $like]);
        }

        if (! empty($args['status'])) {
            $where[]  = 'status = %s';
            $params[] = (string) $args['status'];
        }

        return [implode(' AND ', $where), $params];
    }
}
