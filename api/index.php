<?php
declare(strict_types=1);

/**
 * Contact Manager JSON API
 *
 * Base URL: http://johntmurphy.com/api/index.php
 *
 * Public:
 *   GET  /ping
 *   POST /register
 * Authentication with HTTP Basic credentials or a JSON body:
 *   POST /login
 * Protected with HTTP Basic credentials:
 *   GET|POST /contacts
 *   GET|PUT|DELETE /contacts/{id}
 * Administrators only:
 *   GET /admin/users
 *   POST /admin/users
 *   GET /admin/contacts
 *   PUT /admin/users/{id}/status
 *   PUT /admin/users/{id}/password
 *
 * Examples:
 *   curl -X POST http://johntmurphy.com/api/index.php/register \
 *     -H 'Content-Type: application/json' \
 *     -d '{"firstName":"Jane","lastName":"Doe","username":"janedoe","password":"ChangeMe123!"}'
 *
 *   curl -u 'janedoe:ChangeMe123!' \
 *     'http://johntmurphy.com/api/index.php/contacts?q=smith&limit=25&offset=0'
 *
 * HTTP Basic credentials are readable in transit over HTTP. Use HTTPS as soon
 * as TLS is available; the same calls work without any API changes.
 */

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/db.php';

setApiHeaders();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$path = routePath();
$segments = $path === '/' ? [] : explode('/', trim($path, '/'));

if ($method === 'GET' && $segments === ['ping']) {
    respond(200, [
        'status' => 'OK',
        'timestamp' => time(),
    ]);
}

$db = getDB();

try {
    if ($method === 'POST' && $segments === ['register']) {
        registerUser($db);
    }

    if ($method === 'POST' && $segments === ['login']) {
        $credentials = basicCredentials();
        if ($credentials === null) {
            $body = getRequestBody();
            $credentials = [
                (string) stringField($body, 'username', 50),
                (string) stringField($body, 'password', 72),
            ];
        }

        $user = requireAuth($db, false, $credentials);
        respond(200, [
            'id' => $user['id'],
            'firstName' => $user['firstName'],
            'lastName' => $user['lastName'],
            'username' => $user['username'],
            'isAdmin' => $user['isAdmin'],
            'error' => '',
        ]);
    }

    if (($segments[0] ?? null) === 'contacts') {
        $user = requireAuth($db);
        handleContacts($db, $method, $segments, $user);
    }

    if (($segments[0] ?? null) === 'admin') {
        $admin = requireAuth($db, true);
        handleAdmin($db, $method, $segments, $admin);
    }

    respond(404, ['error' => 'Route not found']);
} catch (PDOException $exception) {
    error_log('Contacts API query failed: ' . $exception->getMessage());
    respond(500, ['error' => 'Database operation failed']);
} catch (Throwable $exception) {
    error_log('Contacts API request failed: ' . $exception->getMessage());
    respond(500, ['error' => 'Internal server error']);
}

function registerUser(PDO $db): void
{
    $body = getRequestBody();
    $firstName = stringField($body, 'firstName', 50);
    $lastName = stringField($body, 'lastName', 50);
    $username = stringField($body, 'username', 50);
    $password = stringField($body, 'password', 72);

    if (strlen((string) $username) < 3 || !preg_match('/^[A-Za-z0-9_.-]+$/', (string) $username)) {
        respond(400, ['error' => 'username must be at least 3 characters and contain only letters, numbers, ., _, or -']);
    }
    validatePassword((string) $password);

    $exists = $db->prepare('SELECT ID FROM Users WHERE Username = :username LIMIT 1');
    $exists->execute([':username' => $username]);
    if ($exists->fetch()) {
        respond(409, ['error' => 'Username is already in use']);
    }

    $stmt = $db->prepare(
        'INSERT INTO Users (FirstName, LastName, Username, Password, IsAdmin, IsActive)
         VALUES (:firstName, :lastName, :username, :password, 0, 1)'
    );
    $stmt->execute([
        ':firstName' => $firstName,
        ':lastName' => $lastName,
        ':username' => $username,
        ':password' => password_hash((string) $password, PASSWORD_DEFAULT),
    ]);

    respond(201, [
        'id' => (int) $db->lastInsertId(),
        'message' => 'Account created',
        'error' => '',
    ]);
}

/**
 * @param list<string> $segments
 * @param array{id: int, firstName: string, lastName: string, username: string, isAdmin: bool} $user
 */
function handleContacts(PDO $db, string $method, array $segments, array $user): void
{
    if (count($segments) === 1) {
        if ($method === 'GET') {
            listContacts($db, $user['id']);
        }
        if ($method === 'POST') {
            createContact($db, $user['id']);
        }
        respond(405, ['error' => 'Method not allowed']);
    }

    if (count($segments) !== 2 || !ctype_digit($segments[1]) || (int) $segments[1] < 1) {
        respond(404, ['error' => 'Route not found']);
    }

    $contactId = (int) $segments[1];
    if ($method === 'GET') {
        getContact($db, $contactId, $user['id']);
    }
    if ($method === 'PUT') {
        updateContact($db, $contactId, $user['id']);
    }
    if ($method === 'DELETE') {
        deleteContact($db, $contactId, $user['id']);
    }

    respond(405, ['error' => 'Method not allowed']);
}

function listContacts(PDO $db, int $userId): void
{
    [$query, $limit, $offset] = searchParameters();
    $like = '%' . $query . '%';

    $stmt = $db->prepare(
        'SELECT ID AS id, FirstName AS firstName, LastName AS lastName,
                Email AS email, PhoneNumber AS phoneNumber,
                DateCreated AS dateCreated, DateUpdated AS dateUpdated
         FROM Contacts
         WHERE UserID = :userId
           AND (:query = \'\' OR FirstName LIKE :like1 OR LastName LIKE :like2
                OR Email LIKE :like3 OR PhoneNumber LIKE :like4)
         ORDER BY LastName, FirstName, ID
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':userId', $userId, PDO::PARAM_INT);
    $stmt->bindValue(':query', $query);
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':like4', $like);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    respond(200, [
        'contacts' => $stmt->fetchAll(),
        'limit' => $limit,
        'offset' => $offset,
        'error' => '',
    ]);
}

function getContact(PDO $db, int $contactId, int $userId): void
{
    $stmt = $db->prepare(
        'SELECT ID AS id, FirstName AS firstName, LastName AS lastName,
                Email AS email, PhoneNumber AS phoneNumber,
                DateCreated AS dateCreated, DateUpdated AS dateUpdated
         FROM Contacts
         WHERE ID = :id AND UserID = :userId
         LIMIT 1'
    );
    $stmt->execute([':id' => $contactId, ':userId' => $userId]);
    $contact = $stmt->fetch();
    if (!$contact) {
        respond(404, ['error' => 'Contact not found']);
    }

    respond(200, ['contact' => $contact, 'error' => '']);
}

function createContact(PDO $db, int $userId): void
{
    $contact = contactBody(getRequestBody());
    $stmt = $db->prepare(
        'INSERT INTO Contacts (FirstName, LastName, Email, PhoneNumber, UserID)
         VALUES (:firstName, :lastName, :email, :phoneNumber, :userId)'
    );
    $stmt->execute([
        ':firstName' => $contact['firstName'],
        ':lastName' => $contact['lastName'],
        ':email' => $contact['email'],
        ':phoneNumber' => $contact['phoneNumber'],
        ':userId' => $userId,
    ]);

    respond(201, [
        'id' => (int) $db->lastInsertId(),
        'message' => 'Contact created',
        'error' => '',
    ]);
}

function updateContact(PDO $db, int $contactId, int $userId): void
{
    $contact = contactBody(getRequestBody());
    $stmt = $db->prepare(
        'UPDATE Contacts
         SET FirstName = :firstName, LastName = :lastName,
             Email = :email, PhoneNumber = :phoneNumber
         WHERE ID = :id AND UserID = :userId'
    );
    $stmt->execute([
        ':firstName' => $contact['firstName'],
        ':lastName' => $contact['lastName'],
        ':email' => $contact['email'],
        ':phoneNumber' => $contact['phoneNumber'],
        ':id' => $contactId,
        ':userId' => $userId,
    ]);

    if ($stmt->rowCount() === 0) {
        $exists = $db->prepare('SELECT ID FROM Contacts WHERE ID = :id AND UserID = :userId LIMIT 1');
        $exists->execute([':id' => $contactId, ':userId' => $userId]);
        if (!$exists->fetch()) {
            respond(404, ['error' => 'Contact not found']);
        }
    }

    respond(200, ['message' => 'Contact updated', 'error' => '']);
}

function deleteContact(PDO $db, int $contactId, int $userId): void
{
    $stmt = $db->prepare('DELETE FROM Contacts WHERE ID = :id AND UserID = :userId');
    $stmt->execute([':id' => $contactId, ':userId' => $userId]);
    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'Contact not found']);
    }

    respond(200, ['message' => 'Contact deleted', 'error' => '']);
}

/**
 * @param list<string> $segments
 * @param array{id: int, firstName: string, lastName: string, username: string, isAdmin: bool} $admin
 */
function handleAdmin(PDO $db, string $method, array $segments, array $admin): void
{
    if ($method === 'GET' && $segments === ['admin', 'users']) {
        listUsers($db);
    }
    if ($method === 'POST' && $segments === ['admin', 'users']) {
        createAdminUser($db);
    }
    if ($method === 'GET' && $segments === ['admin', 'contacts']) {
        listAdminContacts($db);
    }

    if (
        $method === 'PUT'
        && count($segments) === 4
        && $segments[1] === 'users'
        && ctype_digit($segments[2])
        && (int) $segments[2] > 0
    ) {
        $userId = (int) $segments[2];
        if ($segments[3] === 'status') {
            updateUserStatus($db, $userId);
        }
        if ($segments[3] === 'password') {
            updateUserPassword($db, $userId);
        }
    }

    respond(404, ['error' => 'Route not found']);
}

function listUsers(PDO $db): void
{
    [$query, $limit, $offset] = searchParameters();
    $like = '%' . $query . '%';
    $stmt = $db->prepare(
        'SELECT ID AS id, FirstName AS firstName, LastName AS lastName,
                Username AS username, IsAdmin AS isAdmin, IsActive AS isActive,
                DateCreated AS dateCreated, DateUpdated AS dateUpdated
         FROM Users
         WHERE :query = \'\' OR FirstName LIKE :like1 OR LastName LIKE :like2
               OR Username LIKE :like3
         ORDER BY LastName, FirstName, ID
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':query', $query);
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    respond(200, [
        'users' => normalizeFlags($stmt->fetchAll()),
        'limit' => $limit,
        'offset' => $offset,
        'error' => '',
    ]);
}

function createAdminUser(PDO $db): void
{
    $body = getRequestBody();
    $firstName = stringField($body, 'firstName', 50);
    $lastName = stringField($body, 'lastName', 50);
    $username = stringField($body, 'username', 50);
    $password = stringField($body, 'password', 72);

    if (strlen((string) $username) < 3 || !preg_match('/^[A-Za-z0-9_.-]+$/', (string) $username)) {
        respond(400, ['error' => 'username must be at least 3 characters and contain only letters, numbers, ., _, or -']);
    }
    validatePassword((string) $password);

    $exists = $db->prepare('SELECT ID FROM Users WHERE Username = :username LIMIT 1');
    $exists->execute([':username' => $username]);
    if ($exists->fetch()) {
        respond(409, ['error' => 'Username is already in use']);
    }

    $stmt = $db->prepare(
        'INSERT INTO Users (FirstName, LastName, Username, Password, IsAdmin, IsActive)
         VALUES (:firstName, :lastName, :username, :password, 1, 1)'
    );
    $stmt->execute([
        ':firstName' => $firstName,
        ':lastName' => $lastName,
        ':username' => $username,
        ':password' => password_hash((string) $password, PASSWORD_DEFAULT),
    ]);

    respond(201, [
        'id' => (int) $db->lastInsertId(),
        'message' => 'Administrator account created',
        'error' => '',
    ]);
}

function listAdminContacts(PDO $db): void
{
    [$query, $limit, $offset] = searchParameters();
    $like = '%' . $query . '%';
    $userId = null;
    if (isset($_GET['userId'])) {
        $filtered = filter_var($_GET['userId'], FILTER_VALIDATE_INT);
        if ($filtered === false || $filtered < 1) {
            respond(400, ['error' => 'userId must be a positive integer']);
        }
        $userId = (int) $filtered;
    }

    $stmt = $db->prepare(
        'SELECT c.ID AS id, c.FirstName AS firstName, c.LastName AS lastName,
                c.Email AS email, c.PhoneNumber AS phoneNumber, c.UserID AS userId,
                u.Username AS username, c.DateCreated AS dateCreated,
                c.DateUpdated AS dateUpdated
         FROM Contacts c
         INNER JOIN Users u ON u.ID = c.UserID
         WHERE (:userId IS NULL OR c.UserID = :userIdMatch)
           AND (:query = \'\' OR c.FirstName LIKE :like1 OR c.LastName LIKE :like2
                OR c.Email LIKE :like3 OR c.PhoneNumber LIKE :like4
                OR u.Username LIKE :like5)
         ORDER BY c.LastName, c.FirstName, c.ID
         LIMIT :limit OFFSET :offset'
    );
    $stmt->bindValue(':userId', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':userIdMatch', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(':query', $query);
    for ($number = 1; $number <= 5; $number++) {
        $stmt->bindValue(':like' . $number, $like);
    }
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    respond(200, [
        'contacts' => $stmt->fetchAll(),
        'limit' => $limit,
        'offset' => $offset,
        'error' => '',
    ]);
}

function updateUserStatus(PDO $db, int $userId): void
{
    $body = getRequestBody();
    if (!array_key_exists('isActive', $body) || !is_bool($body['isActive'])) {
        respond(400, ['error' => 'isActive must be a JSON boolean']);
    }

    $stmt = $db->prepare('UPDATE Users SET IsActive = :isActive WHERE ID = :id');
    $stmt->execute([
        ':isActive' => $body['isActive'] ? 1 : 0,
        ':id' => $userId,
    ]);
    if ($stmt->rowCount() === 0) {
        $exists = $db->prepare('SELECT ID FROM Users WHERE ID = :id LIMIT 1');
        $exists->execute([':id' => $userId]);
        if (!$exists->fetch()) {
            respond(404, ['error' => 'User not found']);
        }
    }

    respond(200, ['message' => $body['isActive'] ? 'User enabled' : 'User disabled', 'error' => '']);
}

function updateUserPassword(PDO $db, int $userId): void
{
    $body = getRequestBody();
    $password = stringField($body, 'password', 72);
    validatePassword((string) $password);

    $stmt = $db->prepare('UPDATE Users SET Password = :password WHERE ID = :id');
    $stmt->execute([
        ':password' => password_hash((string) $password, PASSWORD_DEFAULT),
        ':id' => $userId,
    ]);
    if ($stmt->rowCount() === 0) {
        respond(404, ['error' => 'User not found']);
    }

    respond(200, ['message' => 'Password changed', 'error' => '']);
}

/**
 * @param array<string, mixed> $body
 * @return array{firstName: string, lastName: string, email: ?string, phoneNumber: ?string}
 */
function contactBody(array $body): array
{
    $firstName = stringField($body, 'firstName', 50);
    $lastName = stringField($body, 'lastName', 50);
    $email = stringField($body, 'email', 100, false);
    $phoneNumber = stringField($body, 'phoneNumber', 20, false);

    if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        respond(400, ['error' => 'email must be a valid email address']);
    }

    return [
        'firstName' => (string) $firstName,
        'lastName' => (string) $lastName,
        'email' => $email,
        'phoneNumber' => $phoneNumber,
    ];
}

/**
 * @return array{0: string, 1: int, 2: int}
 */
function searchParameters(): array
{
    if (isset($_GET['q']) && !is_string($_GET['q'])) {
        respond(400, ['error' => 'q must be a string']);
    }
    $query = isset($_GET['q']) ? trim($_GET['q']) : '';
    $queryLength = function_exists('mb_strlen') ? mb_strlen($query) : strlen($query);
    if ($queryLength > 100) {
        respond(400, ['error' => 'q exceeds the maximum length of 100']);
    }

    $limit = positiveIntQuery('limit', 25, 100);
    if ($limit < 1) {
        respond(400, ['error' => 'limit must be between 1 and 100']);
    }
    $offset = positiveIntQuery('offset', 0, 1000000);

    return [$query, $limit, $offset];
}

function validatePassword(string $password): void
{
    if (strlen($password) < 8) {
        respond(400, ['error' => 'password must be at least 8 characters']);
    }
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<int, array<string, mixed>>
 */
function normalizeFlags(array $rows): array
{
    foreach ($rows as &$row) {
        $row['isAdmin'] = (bool) $row['isAdmin'];
        $row['isActive'] = (bool) $row['isActive'];
    }
    unset($row);
    return $rows;
}
