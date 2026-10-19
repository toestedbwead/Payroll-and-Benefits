<?php

require_once __DIR__ . '/../Repositories/UserRepository.php';

class AuthService
{
    public function __construct(private UserRepository $users)
    {
    }

    public function authenticate(string $email, string $password): ?array
    {
        $user = $this->users->findActiveByEmail($email);

        if ($user === null) {
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            $storedPassword = $user['password_hash'];

            if (
                password_get_info($storedPassword)['algo'] !== null
                || !hash_equals($storedPassword, $password)
            ) {
                return null;
            }

            $this->users->updatePasswordHash(
                (int) $user['id'],
                password_hash($password, PASSWORD_DEFAULT)
            );
        }

        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'email' => $user['email'],
            'role' => $user['role'],
        ];
    }
}
