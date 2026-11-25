<?php

require_once __DIR__ . '/SmtpMailer.php';

class PasswordResetService
{
    public function __construct(
        private PDO $connection,
        private SmtpMailer $mailer
    ) {
    }

    public function requestCode(string $email): bool
    {
        $userStatement = $this->connection->prepare(
            'SELECT id, email FROM users WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $userStatement->execute(['email' => $email]);
        $user = $userStatement->fetch();
        if ($user === false) {
            return false;
        }

        $secret = $this->hashSecret();
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $codeHash = hash_hmac('sha256', $code, $secret);
        $isPostgreSql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $utcNow = $isPostgreSql ? "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')" : 'UTC_TIMESTAMP()';
        $this->connection->beginTransaction();
        try {
            $lockUser = $this->connection->prepare(
                'SELECT id FROM users WHERE id = :id AND is_active = 1 FOR UPDATE'
            );
            $lockUser->execute(['id' => $user['id']]);
            if ($lockUser->fetchColumn() === false) {
                $this->connection->rollBack();
                return false;
            }

            $elapsedSeconds = $isPostgreSql
                ? 'EXTRACT(EPOCH FROM (' . $utcNow . ' - requested_at))'
                : 'TIMESTAMPDIFF(SECOND, requested_at, UTC_TIMESTAMP())';
            $lastRequest = $this->connection->prepare(
                'SELECT ' . $elapsedSeconds . '
                 FROM password_reset_codes WHERE user_id = :user_id FOR UPDATE'
            );
            $lastRequest->execute(['user_id' => $user['id']]);
            $secondsSinceRequest = $lastRequest->fetchColumn();
            if ($secondsSinceRequest !== false && (int) $secondsSinceRequest < 60) {
                $this->connection->commit();
                return false;
            }

            $expiresAt = $isPostgreSql
                ? '(' . $utcNow . ' + INTERVAL \'10 minutes\')'
                : 'DATE_ADD(UTC_TIMESTAMP(), INTERVAL 10 MINUTE)';
            $upsert = $isPostgreSql
                ? 'ON CONFLICT (user_id) DO UPDATE SET
                    code_hash = EXCLUDED.code_hash,
                    expires_at = EXCLUDED.expires_at,
                    attempts = 0,
                    requested_at = EXCLUDED.requested_at'
                : 'ON DUPLICATE KEY UPDATE
                    code_hash = VALUES(code_hash),
                    expires_at = VALUES(expires_at),
                    attempts = 0,
                    requested_at = VALUES(requested_at)';
            $saveCode = $this->connection->prepare(
                'INSERT INTO password_reset_codes
                    (user_id, code_hash, expires_at, attempts, requested_at)
                 VALUES
                    (:user_id, :code_hash, ' . $expiresAt . ', 0, ' . $utcNow . ')
                 ' . $upsert
            );
            $saveCode->execute([
                'user_id' => $user['id'],
                'code_hash' => $codeHash,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }

        try {
            $this->mailer->sendPasswordResetCode((string) $user['email'], $code);
        } catch (Throwable $exception) {
            try {
                $invalidateCode = $this->connection->prepare(
                    'UPDATE password_reset_codes
                     SET attempts = 5, expires_at = ' . $utcNow . '
                     WHERE user_id = :user_id AND code_hash = :code_hash'
                );
                $invalidateCode->execute([
                    'user_id' => $user['id'],
                    'code_hash' => $codeHash,
                ]);
            } catch (PDOException $cleanupException) {
                error_log('Could not invalidate an undelivered password reset code: ' . $cleanupException->getMessage());
            }
            throw $exception;
        }

        return true;
    }

    public function resetPassword(string $email, string $code, string $newPassword): bool
    {
        if (preg_match('/^\d{6}$/', $code) !== 1 || strlen($newPassword) < 12) {
            return false;
        }

        $secret = $this->hashSecret();
        $isPostgreSql = $this->connection->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $utcNow = $isPostgreSql ? "(CURRENT_TIMESTAMP AT TIME ZONE 'UTC')" : 'UTC_TIMESTAMP()';
        $unexpired = $isPostgreSql
            ? 'CASE WHEN prc.expires_at > ' . $utcNow . ' THEN 1 ELSE 0 END'
            : '(prc.expires_at > UTC_TIMESTAMP())';
        $this->connection->beginTransaction();
        try {
            $statement = $this->connection->prepare(
                'SELECT prc.user_id, prc.code_hash, prc.attempts,
                        ' . $unexpired . ' AS unexpired
                 FROM password_reset_codes prc
                 INNER JOIN users u ON u.id = prc.user_id
                 WHERE u.email = :email AND u.is_active = 1
                 FOR UPDATE'
            );
            $statement->execute(['email' => $email]);
            $reset = $statement->fetch();
            if ($reset === false) {
                $this->connection->commit();
                return false;
            }

            $userId = (int) $reset['user_id'];
            if ((int) $reset['attempts'] >= 5 || (int) $reset['unexpired'] !== 1) {
                $this->deleteCode($userId);
                $this->connection->commit();
                return false;
            }

            $providedHash = hash_hmac('sha256', $code, $secret);
            if (!hash_equals((string) $reset['code_hash'], $providedHash)) {
                $incrementAttempts = $this->connection->prepare(
                    'UPDATE password_reset_codes SET attempts = attempts + 1 WHERE user_id = :user_id'
                );
                $incrementAttempts->execute(['user_id' => $userId]);
                $this->connection->commit();
                return false;
            }

            $updatePassword = $this->connection->prepare(
                'UPDATE users SET password_hash = :password_hash WHERE id = :id'
            );
            $updatePassword->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $userId,
            ]);
            $this->deleteCode($userId);
            $this->connection->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            throw $exception;
        }
    }

    private function hashSecret(): string
    {
        $secret = getenv('EMAIL_OTP_HASH_SECRET');
        if (!is_string($secret) || strlen($secret) < 32) {
            throw new RuntimeException('Password reset hashing secret is not configured.');
        }

        return $secret;
    }

    private function deleteCode(int $userId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM password_reset_codes WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
    }
}
