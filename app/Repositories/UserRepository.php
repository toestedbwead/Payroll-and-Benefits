<?php

require_once __DIR__ . '/../../config/database.php';

class UserRepository
{
    public function findActiveByEmail(string $email): ?array
    {
        $statement = getDatabaseConnection()->prepare(
            'SELECT id, name, email, password_hash, role
             FROM users
             WHERE email = :email AND is_active = 1
             LIMIT 1'
        );
        $statement->execute(['email' => $email]);

        $user = $statement->fetch();

        return $user === false ? null : $user;
    }

    public function updatePasswordHash(int $userId, string $passwordHash): void
    {
        $statement = getDatabaseConnection()->prepare(
            'UPDATE users SET password_hash = :password_hash WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'id' => $userId,
        ]);
    }
}
