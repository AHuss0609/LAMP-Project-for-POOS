<?php
declare(strict_types=1);

/**
 * Load simple KEY=VALUE entries from the API's .env file.
 */
function loadEnv(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }

    $path ??= __DIR__ . '/../.env';
    if (is_file($path) && is_readable($path)) {
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if ($name === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $name)) {
                continue;
            }
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
    }

    $loaded = true;
}

function setApiHeaders(): void
{
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [
        'http://johntmurphy.com',
        'https://johntmurphy.com',
    ];

    if (in_array($origin, $allowedOrigins, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type, X-Requested-With');
    header('Access-Control-Max-Age: 86400');

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

/**
 * @param mixed $data
 */
function respond(int $status, $data): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Decode a JSON request body. Form data remains accepted for compatibility.
 *
 * @return array<string, mixed>
 */
function getRequestBody(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            respond(400, ['error' => 'Request body must be valid JSON']);
        }
        return $decoded;
    }

    return is_array($_POST) ? $_POST : [];
}

function routePath(): string
{
    $path = $_SERVER['PATH_INFO'] ?? '';
    if ($path === '') {
        $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/api/index.php';
        if (str_starts_with($requestPath, $scriptName)) {
            $path = substr($requestPath, strlen($scriptName));
        }
    }

    $path = '/' . trim($path, '/');
    return $path === '//' ? '/' : $path;
}

/**
 * @return array{0: string, 1: string}|null
 */
function basicCredentials(): ?array
{
    if (isset($_SERVER['PHP_AUTH_USER'], $_SERVER['PHP_AUTH_PW'])) {
        return [(string) $_SERVER['PHP_AUTH_USER'], (string) $_SERVER['PHP_AUTH_PW']];
    }

    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? null;

    if (!$header && function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        $header = $headers['Authorization'] ?? $headers['authorization'] ?? null;
    }

    if (!is_string($header) || !preg_match('/^Basic\s+(.+)$/i', trim($header), $matches)) {
        return null;
    }

    $decoded = base64_decode($matches[1], true);
    if ($decoded === false || !str_contains($decoded, ':')) {
        return null;
    }

    return explode(':', $decoded, 2);
}

/**
 * Authenticate an active user on each request.
 *
 * @param array{0: string, 1: string}|null $credentials
 * @return array{id: int, firstName: string, lastName: string, username: string, isAdmin: bool}
 */
function requireAuth(PDO $db, bool $adminOnly = false, ?array $credentials = null): array
{
    $credentials ??= basicCredentials();
    if ($credentials === null || $credentials[0] === '' || $credentials[1] === '') {
        header('WWW-Authenticate: Basic realm="Contacts API", charset="UTF-8"');
        respond(401, ['error' => 'Authentication required']);
    }

    [$username, $password] = $credentials;
    $stmt = $db->prepare(
        'SELECT ID, FirstName, LastName, Username, Password, IsAdmin, IsActive
         FROM Users WHERE Username = :username LIMIT 1'
    );
    $stmt->execute([':username' => $username]);
    $row = $stmt->fetch();

    if (!$row || !(bool) $row['IsActive']) {
        header('WWW-Authenticate: Basic realm="Contacts API", charset="UTF-8"');
        respond(401, ['error' => 'Invalid credentials']);
    }

    $storedPassword = (string) $row['Password'];
    $passwordInfo = password_get_info($storedPassword);
    $valid = $passwordInfo['algo'] !== null
        ? password_verify($password, $storedPassword)
        : hash_equals($storedPassword, $password);

    if (!$valid) {
        header('WWW-Authenticate: Basic realm="Contacts API", charset="UTF-8"');
        respond(401, ['error' => 'Invalid credentials']);
    }

    // Upgrade legacy plaintext passwords immediately after a successful login.
    if ($passwordInfo['algo'] === null || password_needs_rehash($storedPassword, PASSWORD_DEFAULT)) {
        $update = $db->prepare('UPDATE Users SET Password = :password WHERE ID = :id');
        $update->execute([
            ':password' => password_hash($password, PASSWORD_DEFAULT),
            ':id' => (int) $row['ID'],
        ]);
    }

    if ($adminOnly && !(bool) $row['IsAdmin']) {
        respond(403, ['error' => 'Administrator access required']);
    }

    return [
        'id' => (int) $row['ID'],
        'firstName' => (string) $row['FirstName'],
        'lastName' => (string) $row['LastName'],
        'username' => (string) $row['Username'],
        'isAdmin' => (bool) $row['IsAdmin'],
    ];
}

function stringField(array $body, string $field, int $maxLength, bool $required = true): ?string
{
    if (!array_key_exists($field, $body) || $body[$field] === null) {
        if ($required) {
            respond(400, ['error' => $field . ' is required']);
        }
        return null;
    }

    if (!is_string($body[$field])) {
        respond(400, ['error' => $field . ' must be a string']);
    }

    $value = trim($body[$field]);
    if ($required && $value === '') {
        respond(400, ['error' => $field . ' is required']);
    }
    $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
    if ($length > $maxLength) {
        respond(400, ['error' => $field . ' exceeds the maximum length of ' . $maxLength]);
    }

    return $value === '' ? null : $value;
}

function positiveIntQuery(string $name, int $default, int $maximum): int
{
    if (!isset($_GET[$name])) {
        return $default;
    }

    $value = filter_var($_GET[$name], FILTER_VALIDATE_INT);
    if ($value === false || $value < 0 || $value > $maximum) {
        respond(400, ['error' => $name . ' must be between 0 and ' . $maximum]);
    }

    return (int) $value;
}
