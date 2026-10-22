<?php

require_once __DIR__ . '/../Services/ClaimsService.php';
require_once __DIR__ . '/../../config/database.php';

class ClaimsController
{
    private const ALLOWED_REVIEW_ROLES = ['admin', 'superadmin', 'hr_manager'];
    private const CLAIMS_PER_PAGE = 10;

    public static function handleRequest(): array
    {
        $user = $_SESSION['user'] ?? [];
        $role = isset($user['role']) && is_string($user['role']) ? $user['role'] : '';

        if (!in_array($role, self::ALLOWED_REVIEW_ROLES, true)) {
            http_response_code(403);
            return [
                'claims' => [],
                'counts' => [],
                'status' => null,
                'search' => '',
                'page' => 1,
                'totalPages' => 1,
                'totalClaims' => 0,
                'flash' => ['type' => 'error', 'message' => 'Your account is not allowed to review claims.'],
                'canReview' => false,
            ];
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            self::processReview($user);
        }

        $flash = $_SESSION['claims_flash'] ?? null;
        unset($_SESSION['claims_flash']);

        $statusInput = $_GET['status'] ?? '';
        $status = is_string($statusInput) && in_array($statusInput, ClaimsService::STATUSES, true)
            ? $statusInput
            : null;
        $searchInput = $_GET['search'] ?? '';
        $search = is_string($searchInput) ? trim(mb_substr($searchInput, 0, 100)) : '';
        $pageInput = $_GET['page_number'] ?? '1';
        $requestedPage = filter_var($pageInput, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $page = $requestedPage === false ? 1 : $requestedPage;

        try {
            $connection = getDatabaseConnection();
            $repository = new ClaimsRepository($connection);
            $totalClaims = $repository->countClaims($status, $search);
            $totalPages = max(1, (int) ceil($totalClaims / self::CLAIMS_PER_PAGE));
            $page = min($page, $totalPages);

            return [
                'claims' => $repository->listClaims(
                    $status,
                    $search,
                    self::CLAIMS_PER_PAGE,
                    ($page - 1) * self::CLAIMS_PER_PAGE
                ),
                'counts' => $repository->getStatusCounts(),
                'status' => $status,
                'search' => $search,
                'page' => $page,
                'totalPages' => $totalPages,
                'totalClaims' => $totalClaims,
                'flash' => is_array($flash) ? $flash : null,
                'canReview' => true,
            ];
        } catch (PDOException $exception) {
            error_log('Claims page database error: ' . $exception->getMessage());

            return [
                'claims' => [],
                'counts' => [],
                'status' => $status,
                'search' => $search,
                'page' => $page,
                'totalPages' => 1,
                'totalClaims' => 0,
                'flash' => ['type' => 'error', 'message' => 'Claims could not be loaded. Check the database connection and claims table.'],
                'canReview' => true,
            ];
        }
    }

    public static function downloadReceipt(): void
    {
        $user = $_SESSION['user'] ?? [];
        $role = isset($user['role']) && is_string($user['role']) ? $user['role'] : '';

        if (!in_array($role, self::ALLOWED_REVIEW_ROLES, true)) {
            http_response_code(403);
            exit('Your account is not allowed to access claim receipts.');
        }

        $claimId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if ($claimId === false || $claimId === null || $claimId < 1) {
            http_response_code(400);
            exit('Invalid claim reference.');
        }

        try {
            $repository = new ClaimsRepository(getDatabaseConnection());
            $receipt = $repository->findReceipt($claimId);
        } catch (PDOException $exception) {
            error_log('Claim receipt database error: ' . $exception->getMessage());
            http_response_code(503);
            exit('The receipt is temporarily unavailable.');
        }

        if ($receipt === null || !str_starts_with($receipt, 'uploads/receipts/')) {
            http_response_code(404);
            exit('Receipt not found.');
        }

        $uploadRoot = realpath(__DIR__ . '/../../uploads/receipts');
        $filePath = realpath(__DIR__ . '/../../' . $receipt);
        if (
            $uploadRoot === false
            || $filePath === false
            || !str_starts_with($filePath, $uploadRoot . DIRECTORY_SEPARATOR)
            || !is_file($filePath)
        ) {
            http_response_code(404);
            exit('Receipt file is not available.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($filePath);
        $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!is_string($mimeType) || !in_array($mimeType, $allowedMimeTypes, true)) {
            http_response_code(415);
            exit('This receipt file type is not supported.');
        }

        header('Content-Type: ' . $mimeType);
        header('Content-Length: ' . (string) filesize($filePath));
        header('Content-Disposition: inline; filename="' . rawurlencode(basename($filePath)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($filePath);
        exit;
    }

    private static function processReview(array $user): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired review request. Refresh the page and try again.');
        }

        $claimId = filter_var($_POST['claim_id'] ?? null, FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? null;
        $note = $_POST['review_note'] ?? '';
        if (
            $claimId === false
            || $claimId === null
            || $claimId < 1
            || !is_string($action)
            || !is_string($note)
        ) {
            self::redirectWithFlash('error', 'The review request was invalid.');
        }

        $reviewer = isset($user['name']) && is_string($user['name']) ? $user['name'] : 'HR reviewer';

        try {
            $connection = getDatabaseConnection();
            $service = new ClaimsService($connection, new ClaimsRepository($connection));
            $newStatus = $service->review($claimId, $action, trim($note), $reviewer);
            self::redirectWithFlash('success', 'Claim #' . $claimId . ' updated to ' . $newStatus . '.');
        } catch (InvalidArgumentException | DomainException $exception) {
            self::redirectWithFlash('error', $exception->getMessage());
        } catch (PDOException $exception) {
            error_log('Claim review database error: ' . $exception->getMessage());
            self::redirectWithFlash('error', 'The claim could not be updated because of a database error.');
        }
    }

    private static function redirectWithFlash(string $type, string $message): never
    {
        $_SESSION['claims_flash'] = ['type' => $type, 'message' => $message];
        header('Location: index.php?page=claims');
        exit;
    }
}
