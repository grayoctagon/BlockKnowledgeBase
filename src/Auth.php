<?php

declare(strict_types=1);

namespace BKB;

use BKB\Storage\AtomicJsonStore;
use BKB\Storage\DataLayout;

final class AuthService
{
    public function __construct(
        private readonly DataLayout $layout,
        private readonly AtomicJsonStore $store
    ) {
    }

    public function isConfigured(): bool
    {
        $data = $this->store->read($this->layout->usersJson(), false);
        return is_array($data) && is_array($data['users'] ?? null) && count($data['users']) > 0;
    }

    /**
     * @return array{id:string,username:string,displayName:string,role:string,createdAt:string}
     */
    public function setupInitialAdmin(string $username, string $displayName, string $password): array
    {
        return $this->store->withLock(
            $this->layout->usersLock(),
            function () use ($username, $displayName, $password): array {
                if ($this->isConfigured()) {
                    throw new HttpException(409, 'ALREADY_CONFIGURED', 'Die Ersteinrichtung wurde bereits abgeschlossen.');
                }

                $username = $this->validateUsername($username);
                $displayName = Text::requiredString($displayName, 'displayName', 120);
                $this->validatePassword($password);

                $userId = 'user_' . bin2hex(random_bytes(16));
                $createdAt = Text::now();
                $user = [
                    'id' => $userId,
                    'username' => $username,
                    'displayName' => $displayName,
                    'passwordHash' => password_hash($password, PASSWORD_DEFAULT),
                    'role' => 'admin',
                    'active' => true,
                    'createdAt' => $createdAt,
                    'updatedAt' => $createdAt,
                ];

                $data = [
                    'schemaVersion' => 1,
                    'users' => [
                        $userId => $user,
                    ],
                ];
                $this->store->write(
                    $this->layout->usersJson(),
                    $data,
                    $this->layout->usersPreviousJson()
                );

                $this->establishSession($userId);
                return $this->publicUser($user);
            }
        );
    }

    /**
     * @return array{id:string,username:string,displayName:string,role:string,createdAt:string}
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);
        $data = $this->readUsers();
        $matched = null;

        foreach ($data['users'] as $user) {
            if (
                is_array($user)
                && ($user['active'] ?? false) === true
                && is_string($user['username'] ?? null)
                && hash_equals(strtolower($user['username']), strtolower($username))
            ) {
                $matched = $user;
                break;
            }
        }

        $dummyHash = '$2y$10$1tfaJd6Wk7mXevjAumXe6uT2BTB.QhoXiQvNGZJ4PsC4m7UfgrmZq';
        $hash = is_array($matched) && is_string($matched['passwordHash'] ?? null)
            ? $matched['passwordHash']
            : $dummyHash;

        if (!password_verify($password, $hash) || $matched === null) {
            throw new HttpException(401, 'LOGIN_FAILED', 'Benutzername oder Passwort ist falsch.');
        }

        $userId = (string) $matched['id'];
        $this->establishSession($userId);

        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->rehashPassword($userId, $password);
        }

        return $this->publicUser($matched);
    }

    public function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                [
                    'expires' => time() - 42000,
                    'path' => $params['path'],
                    'domain' => $params['domain'],
                    'secure' => (bool) $params['secure'],
                    'httponly' => (bool) $params['httponly'],
                    'samesite' => 'Lax',
                ]
            );
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * @return array{id:string,username:string,displayName:string,role:string,createdAt:string}|null
     */
    public function currentUser(): ?array
    {
        $userId = $_SESSION['userId'] ?? null;
        if (!is_string($userId) || $userId === '') {
            return null;
        }

        $data = $this->store->read($this->layout->usersJson(), false);
        $user = is_array($data) ? ($data['users'][$userId] ?? null) : null;
        if (!is_array($user) || ($user['active'] ?? false) !== true) {
            unset($_SESSION['userId']);
            return null;
        }

        return $this->publicUser($user);
    }

    /**
     * @return array{id:string,username:string,displayName:string,role:string,createdAt:string}
     */
    public function requireUser(): array
    {
        $user = $this->currentUser();
        if ($user === null) {
            throw new HttpException(401, 'AUTHENTICATION_REQUIRED', 'Bitte melde dich an.');
        }

        return $user;
    }

    public function csrfToken(): string
    {
        if (!is_string($_SESSION['csrfToken'] ?? null) || strlen($_SESSION['csrfToken']) < 32) {
            $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrfToken'];
    }

    public function requireValidCsrf(?string $token): void
    {
        $expected = $this->csrfToken();
        if (!is_string($token) || !hash_equals($expected, $token)) {
            throw new HttpException(403, 'INVALID_CSRF_TOKEN', 'Das Sicherheitstoken ist ungültig oder abgelaufen.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function readUsers(): array
    {
        $data = $this->store->read($this->layout->usersJson(), false);
        if (!is_array($data) || !is_array($data['users'] ?? null)) {
            throw new HttpException(503, 'NOT_CONFIGURED', 'Die Anwendung wurde noch nicht eingerichtet.');
        }

        return $data;
    }

    private function validateUsername(string $username): string
    {
        $username = trim($username);
        if (!preg_match('/^[A-Za-z0-9._-]{3,64}$/', $username)) {
            throw new HttpException(
                422,
                'INVALID_USERNAME',
                'Der Benutzername muss 3 bis 64 Zeichen lang sein und darf Buchstaben, Zahlen, Punkt, Unterstrich und Bindestrich enthalten.'
            );
        }

        return $username;
    }

    private function validatePassword(string $password): void
    {
        $length = Text::length($password);
        if ($length < 12 || $length > 1024) {
            throw new HttpException(
                422,
                'INVALID_PASSWORD',
                'Das Passwort muss zwischen 12 und 1024 Zeichen lang sein.'
            );
        }
    }

    private function establishSession(string $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['userId'] = $userId;
        $_SESSION['csrfToken'] = bin2hex(random_bytes(32));
        $_SESSION['authenticatedAt'] = time();
    }

    private function rehashPassword(string $userId, string $password): void
    {
        $this->store->withLock(
            $this->layout->usersLock(),
            function () use ($userId, $password): void {
                $data = $this->readUsers();
                if (!is_array($data['users'][$userId] ?? null)) {
                    return;
                }

                $data['users'][$userId]['passwordHash'] = password_hash($password, PASSWORD_DEFAULT);
                $data['users'][$userId]['updatedAt'] = Text::now();
                $this->store->write(
                    $this->layout->usersJson(),
                    $data,
                    $this->layout->usersPreviousJson()
                );
            }
        );
    }

    /**
     * @param array<string, mixed> $user
     * @return array{id:string,username:string,displayName:string,role:string,createdAt:string}
     */
    private function publicUser(array $user): array
    {
        return [
            'id' => (string) $user['id'],
            'username' => (string) $user['username'],
            'displayName' => (string) $user['displayName'],
            'role' => (string) $user['role'],
            'createdAt' => (string) $user['createdAt'],
        ];
    }
}
