<?php

require_once __DIR__ . '/../Repositories/SettingsRepository.php';
require_once __DIR__ . '/../../config/database.php';

class SettingsController
{
    private const ALLOWED_ROLES = ['admin', 'superadmin', 'hr_manager'];
    private const CLAIM_CATEGORIES = [
        'Medical' => ['label' => 'Medical / Health', 'amount' => 5000, 'days' => 60, 'icon' => 'bi-heart-pulse'],
        'Transportation' => ['label' => 'Transportation', 'amount' => 1000, 'days' => 30, 'icon' => 'bi-train-front'],
        'Official Business' => ['label' => 'Official Business', 'amount' => 10000, 'days' => 60, 'icon' => 'bi-briefcase'],
        'Miscellaneous' => ['label' => 'Miscellaneous', 'amount' => 500, 'days' => 30, 'icon' => 'bi-receipt'],
    ];
    private const DEFAULTS = [
        'organization' => [
            'name' => 'Priority Handling Inc',
            'business_location' => 'Philippines',
            'industry' => '',
            'tax_id' => '',
            'email' => '',
            'phone' => '',
            'date_format' => 'Y-m-d',
        ],
        'payroll' => [
            'currency' => 'PHP',
            'frequency' => 'semi_monthly',
            'pay_day' => '15,30',
        ],
        'compensation' => [
            'standard_weekly_hours' => 40,
            'salary_review_months' => 12,
            'overtime_multiplier' => 1.25,
        ],
    ];

    public static function canManage(array $user): bool
    {
        return isset($user['role'])
            && is_string($user['role'])
            && in_array($user['role'], self::ALLOWED_ROLES, true);
    }

    public static function handleRequest(string $section): array
    {
        $user = $_SESSION['user'] ?? [];
        if (!self::canManage($user)) {
            http_response_code(403);
            return [
                'canManage' => false,
                'loadError' => false,
                'flash' => ['type' => 'error', 'message' => 'Your account cannot access settings.'],
                'organization' => self::DEFAULTS['organization'],
                'payroll' => self::DEFAULTS['payroll'],
                'compensation' => self::DEFAULTS['compensation'],
                'claimsThresholds' => self::CLAIM_CATEGORIES,
                'workLocations' => [],
            ];
        }

        $flash = $_SESSION['settings_flash'] ?? null;
        unset($_SESSION['settings_flash']);

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section !== 'benefits') {
            self::processAction($section);
        }

        try {
            $repository = new SettingsRepository(getDatabaseConnection());
            $thresholds = $repository->get('claims_thresholds', self::CLAIM_CATEGORIES);
            foreach (self::CLAIM_CATEGORIES as $category => $defaults) {
                $thresholds[$category] = array_replace($defaults, $thresholds[$category] ?? []);
            }

            return [
                'canManage' => true,
                'loadError' => false,
                'flash' => is_array($flash) ? $flash : null,
                'organization' => $repository->get('organization', self::DEFAULTS['organization']),
                'payroll' => $repository->get('payroll', self::DEFAULTS['payroll']),
                'compensation' => $repository->get('compensation', self::DEFAULTS['compensation']),
                'claimsThresholds' => $thresholds,
                'workLocations' => $repository->listWorkLocations(),
            ];
        } catch (PDOException $exception) {
            error_log('Settings load database error: ' . $exception->getMessage());
            return [
                'canManage' => true,
                'loadError' => true,
                'flash' => ['type' => 'error', 'message' => 'Settings could not be loaded. Apply the settings migration and check the database connection.'],
                'organization' => self::DEFAULTS['organization'],
                'payroll' => self::DEFAULTS['payroll'],
                'compensation' => self::DEFAULTS['compensation'],
                'claimsThresholds' => self::CLAIM_CATEGORIES,
                'workLocations' => [],
            ];
        }
    }

    public static function getClaimsThresholds(): array
    {
        try {
            $repository = new SettingsRepository(getDatabaseConnection());
            $thresholds = $repository->get('claims_thresholds', self::CLAIM_CATEGORIES);
            foreach (self::CLAIM_CATEGORIES as $category => $defaults) {
                $thresholds[$category] = array_replace($defaults, $thresholds[$category] ?? []);
            }
            return $thresholds;
        } catch (PDOException $exception) {
            error_log('Claims settings load database error: ' . $exception->getMessage());
            return self::CLAIM_CATEGORIES;
        }
    }

    private static function processAction(string $section): never
    {
        self::validateCsrfToken();
        $action = $_POST['action'] ?? '';

        try {
            $repository = new SettingsRepository(getDatabaseConnection());
            if ($action === 'save_organization') {
                $repository->save('organization', [
                    'name' => self::requiredString('name', 160),
                    'business_location' => self::requiredString('business_location', 120),
                    'industry' => self::optionalString('industry', 120),
                    'tax_id' => self::optionalString('tax_id', 80),
                    'email' => self::emailString('email'),
                    'phone' => self::optionalString('phone', 40),
                    'date_format' => self::choice('date_format', ['Y-m-d', 'm/d/Y', 'd/m/Y']),
                ]);
                self::redirect('organization', 'Organization profile saved.');
            }

            if ($action === 'save_payroll') {
                $repository->save('payroll', [
                    'currency' => self::choice('currency', ['PHP', 'USD', 'EUR']),
                    'frequency' => self::choice('frequency', ['weekly', 'biweekly', 'semi_monthly', 'monthly']),
                    'pay_day' => self::requiredString('pay_day', 20),
                ]);
                self::redirect('payroll', 'Payroll settings saved.');
            }

            if ($action === 'save_compensation') {
                $weeklyHours = self::number('standard_weekly_hours', 1, 168);
                $reviewMonths = self::number('salary_review_months', 1, 60);
                $overtimeMultiplier = self::decimal('overtime_multiplier', 1, 5);
                $repository->save('compensation', [
                    'standard_weekly_hours' => $weeklyHours,
                    'salary_review_months' => $reviewMonths,
                    'overtime_multiplier' => $overtimeMultiplier,
                ]);
                self::redirect('compensation', 'Compensation defaults saved.');
            }

            if ($action === 'save_claims') {
                $thresholds = self::CLAIM_CATEGORIES;
                foreach (self::CLAIM_CATEGORIES as $category => $_defaults) {
                    $fieldKey = strtolower(str_replace(' ', '_', $category));
                    $thresholds[$category]['amount'] = self::wholeAmount('amount_' . $fieldKey, 0, 100000000);
                    $thresholds[$category]['days'] = self::number('days_' . $fieldKey, 1, 365);
                }
                $repository->save('claims_thresholds', $thresholds);
                self::redirect('claims', 'Claims thresholds saved.');
            }

            if ($action === 'save_location') {
                $idInput = $_POST['location_id'] ?? '';
                $id = $idInput === '' ? null : filter_var($idInput, FILTER_VALIDATE_INT);
                if ($idInput !== '' && ($id === false || $id < 1)) {
                    throw new InvalidArgumentException('Choose a valid work location.');
                }
                $repository->saveWorkLocation($id === false ? null : $id, [
                    'name' => self::requiredString('location_name', 120),
                    'address' => self::optionalString('address', 255),
                    'city' => self::optionalString('city', 100),
                    'province' => self::optionalString('province', 100),
                    'postal_code' => self::optionalString('postal_code', 20),
                    'is_primary' => ($_POST['is_primary'] ?? '') === '1',
                ]);
                self::redirect('work_locations', 'Work location saved.');
            }

            if ($action === 'delete_location') {
                $id = filter_var($_POST['location_id'] ?? null, FILTER_VALIDATE_INT);
                if ($id === false || $id === null || $id < 1) {
                    throw new InvalidArgumentException('Choose a valid work location.');
                }
                $repository->deleteWorkLocation($id);
                self::redirect('work_locations', 'Work location removed.');
            }

            throw new InvalidArgumentException('Choose a valid settings action.');
        } catch (InvalidArgumentException $exception) {
            self::redirect($section, $exception->getMessage(), 'error');
        } catch (PDOException | JsonException $exception) {
            error_log('Settings save error: ' . $exception->getMessage());
            self::redirect($section, 'Settings could not be saved because of a database error.', 'error');
        }
    }

    private static function validateCsrfToken(): void
    {
        $postedToken = $_POST['csrf_token'] ?? null;
        $sessionToken = $_SESSION['csrf_token'] ?? null;
        if (
            !is_string($postedToken)
            || !is_string($sessionToken)
            || !hash_equals($sessionToken, $postedToken)
        ) {
            http_response_code(403);
            exit('Invalid or expired settings request. Refresh the page and try again.');
        }
    }

    private static function requiredString(string $field, int $maxLength): string
    {
        $value = $_POST[$field] ?? null;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $maxLength) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
        return trim($value);
    }

    private static function optionalString(string $field, int $maxLength): string
    {
        $value = $_POST[$field] ?? '';
        if (!is_string($value) || mb_strlen(trim($value)) > $maxLength) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
        return trim($value);
    }

    private static function emailString(string $field): string
    {
        $value = self::optionalString($field, 254);
        if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Enter a valid organization email address.');
        }
        return $value;
    }

    private static function choice(string $field, array $allowed): string
    {
        $value = $_POST[$field] ?? null;
        if (!is_string($value) || !in_array($value, $allowed, true)) {
            throw new InvalidArgumentException('Choose a valid ' . str_replace('_', ' ', $field) . '.');
        }
        return $value;
    }

    private static function number(string $field, int $minimum, int $maximum): int
    {
        $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_INT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
        return $value;
    }

    private static function decimal(string $field, float $minimum, float $maximum): float
    {
        $value = filter_var($_POST[$field] ?? null, FILTER_VALIDATE_FLOAT);
        if ($value === false || $value < $minimum || $value > $maximum) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }
        return round($value, 2);
    }

    private static function wholeAmount(string $field, int $minimum, int $maximum): int
    {
        $value = $_POST[$field] ?? null;
        if (!is_string($value) || !preg_match('/^\d+(?:\.\d+)?$/', trim($value))) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }

        $wholeAmount = (int) explode('.', trim($value), 2)[0];
        if ($wholeAmount < $minimum || $wholeAmount > $maximum) {
            throw new InvalidArgumentException('Enter a valid ' . str_replace('_', ' ', $field) . '.');
        }

        return $wholeAmount;
    }

    private static function redirect(string $section, string $message, string $type = 'success'): never
    {
        $_SESSION['settings_flash'] = ['type' => $type, 'message' => $message];
        $returnPage = $_POST['return_to'] ?? 'dashboard';
        $allowedReturnPages = ['dashboard', 'payroll', 'compensation', 'claims', 'benefits', 'analytics'];
        $returnPage = is_string($returnPage) && in_array($returnPage, $allowedReturnPages, true)
            ? $returnPage
            : 'dashboard';
        header('Location: index.php?page=settings&section=' . rawurlencode($section) . '&return_to=' . rawurlencode($returnPage));
        exit;
    }
}
