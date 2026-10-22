<?php

class ClaimsRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function listClaims(?string $status, string $search, int $limit = 10, int $offset = 0): array
    {
        [$where, $parameters] = $this->claimFilters($status, $search);

        $statement = $this->connection->prepare(
            'SELECT c.id, c.employee_id, c.expense_type, c.claim_amount, c.expense_date,
                    c.receipt_attachment, c.status, c.review_notes, c.created_at,
                    e.employee_code, e.first_name, e.last_name, e.department,
                    e.employment_type, e.employment_status
             FROM claims c
             INNER JOIN employees e ON e.id = c.employee_id
             ' . $where . '
             ORDER BY CASE c.status
                 WHEN \'Pending Approval\' THEN 0
                 WHEN \'Returned for Revision\' THEN 1
                 ELSE 2
             END, c.created_at DESC, c.id DESC
             LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $statement->bindValue(':limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    public function countClaims(?string $status, string $search): int
    {
        [$where, $parameters] = $this->claimFilters($status, $search);
        $statement = $this->connection->prepare(
            'SELECT COUNT(*)
             FROM claims c
             INNER JOIN employees e ON e.id = c.employee_id
             ' . $where
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    private function claimFilters(?string $status, string $search): array
    {
        $conditions = [];
        $parameters = [];

        if ($status !== null) {
            $conditions[] = 'c.status = :status';
            $parameters['status'] = $status;
        }

        if ($search !== '') {
            $conditions[] = '(e.employee_code LIKE :search_code
                OR e.first_name LIKE :search_first
                OR e.last_name LIKE :search_last
                OR CONCAT(e.first_name, \' \', e.last_name) LIKE :search_name)';
            $pattern = '%' . $search . '%';
            $parameters['search_code'] = $pattern;
            $parameters['search_first'] = $pattern;
            $parameters['search_last'] = $pattern;
            $parameters['search_name'] = $pattern;
        }

        $where = $conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions);

        return [$where, $parameters];
    }

    public function getStatusCounts(): array
    {
        $counts = [
            'Pending Approval' => 0,
            'Approved' => 0,
            'Rejected' => 0,
            'Returned for Revision' => 0,
        ];
        $statement = $this->connection->query(
            'SELECT status, COUNT(*) AS total FROM claims GROUP BY status'
        );

        foreach ($statement->fetchAll() as $row) {
            if (array_key_exists($row['status'], $counts)) {
                $counts[$row['status']] = (int) $row['total'];
            }
        }

        return $counts;
    }

    public function findClaimForUpdate(int $claimId): ?array
    {
        $statement = $this->connection->prepare(
            'SELECT id, status, review_notes
             FROM claims
             WHERE id = :id
             FOR UPDATE'
        );
        $statement->execute(['id' => $claimId]);
        $claim = $statement->fetch();

        return $claim === false ? null : $claim;
    }

    public function saveReview(int $claimId, string $status, string $reviewNotes): void
    {
        $statement = $this->connection->prepare(
            'UPDATE claims SET status = :status, review_notes = :review_notes WHERE id = :id'
        );
        $statement->execute([
            'status' => $status,
            'review_notes' => $reviewNotes,
            'id' => $claimId,
        ]);
    }

    public function findReceipt(int $claimId): ?string
    {
        $statement = $this->connection->prepare(
            'SELECT receipt_attachment FROM claims WHERE id = :id'
        );
        $statement->execute(['id' => $claimId]);
        $receipt = $statement->fetchColumn();

        return $receipt === false ? null : $receipt;
    }
}
