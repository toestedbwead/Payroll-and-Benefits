<?php

require_once __DIR__ . '/../Repositories/ClaimsRepository.php';

class ClaimsService
{
    public const STATUSES = [
        'Pending Approval',
        'Approved',
        'Rejected',
        'Returned for Revision',
    ];

    public function __construct(
        private PDO $connection,
        private ClaimsRepository $claims
    ) {
    }

    public function review(int $claimId, string $action, string $note, string $reviewer): string
    {
        $transitions = [
            'approve' => 'Approved',
            'reject' => 'Rejected',
            'return' => 'Returned for Revision',
        ];

        if (!isset($transitions[$action])) {
            throw new InvalidArgumentException('Choose a valid review action.');
        }

        if (in_array($action, ['reject', 'return'], true) && $note === '') {
            throw new InvalidArgumentException('Add a note when rejecting or returning a claim.');
        }

        if (mb_strlen($note) > 4000) {
            throw new InvalidArgumentException('Review notes must be 4,000 characters or fewer.');
        }

        try {
            $this->connection->beginTransaction();
            $claim = $this->claims->findClaimForUpdate($claimId);

            if ($claim === null) {
                throw new DomainException('That claim could not be found.');
            }

            if (!in_array($claim['status'], ['Pending Approval', 'Returned for Revision'], true)) {
                throw new DomainException('This claim has already been finalized and cannot be reviewed again.');
            }

            $newStatus = $transitions[$action];
            $actionLabel = [
                'approve' => 'Approved',
                'reject' => 'Rejected',
                'return' => 'Returned for revision',
            ][$action];
            $auditEntry = sprintf(
                '[%s] %s by %s%s',
                date('Y-m-d H:i:s'),
                $actionLabel,
                $reviewer,
                $note === '' ? '' : ': ' . $note
            );
            $existingNotes = trim((string) ($claim['review_notes'] ?? ''));
            $reviewNotes = $existingNotes === ''
                ? $auditEntry
                : $existingNotes . PHP_EOL . $auditEntry;

            $this->claims->saveReview($claimId, $newStatus, $reviewNotes);
            $this->connection->commit();

            return $newStatus;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
