<?php

require_once __DIR__ . '/../Repositories/HmoRepository.php';

class HmoService
{
    public const JOB_TIERS = ['Entry-Level', 'Supervisory', 'Executive'];
    public const DEPENDENT_MONTHLY_PREMIUMS = [
        'Entry-Level' => '1000.00',
        'Supervisory' => '1500.00',
        'Executive' => '2500.00',
    ];
    public const DEPENDENT_COST_SHARE_PERCENT = 100;
    public const DEDUCTION_TRANSITIONS = [
        'export' => ['Queued', 'Exported'],
        'process' => ['Exported', 'Processed'],
    ];

    public function __construct(
        private PDO $connection,
        private HmoRepository $hmo
    ) {
    }

    public function savePlan(array $input, string $actor): int
    {
        $planId = $input['plan_id'] === '' ? null : filter_var($input['plan_id'], FILTER_VALIDATE_INT);
        $provider = trim($input['provider_name']);
        $tierName = trim($input['plan_tier_name']);
        $jobTier = $input['target_job_tier'];
        $room = trim($input['room_and_board']);
        $coverage = $this->positiveAmount($input['max_coverage_limit'], 'Coverage limit');
        $premium = $this->positiveAmount($input['annual_premium_cost'], 'Annual premium');
        if (!in_array($input['parent_dependents_allowed'], ['0', '1'], true)
            || !in_array($input['is_active'], ['0', '1'], true)
        ) {
            throw new InvalidArgumentException('Choose valid plan availability and parent eligibility settings.');
        }
        $parentAllowed = $input['parent_dependents_allowed'] === '1';
        $active = $input['is_active'] === '1';

        if ($planId === false || ($planId !== null && $planId < 1)) {
            throw new InvalidArgumentException('Select a valid plan to update.');
        }
        if ($provider === '' || mb_strlen($provider) > 100 || $tierName === '' || mb_strlen($tierName) > 100) {
            throw new InvalidArgumentException('Enter a provider and plan tier name of 100 characters or fewer.');
        }
        if (!in_array($jobTier, self::JOB_TIERS, true)) {
            throw new InvalidArgumentException('Choose a valid employee tier.');
        }
        $dependentPremium = self::DEPENDENT_MONTHLY_PREMIUMS[$jobTier];
        if ($room === '' || mb_strlen($room) > 100) {
            throw new InvalidArgumentException('Enter a room and board description of 100 characters or fewer.');
        }

        $plan = [
            'provider_name' => $provider,
            'plan_tier_name' => $tierName,
            'target_job_tier' => $jobTier,
            'max_coverage_limit' => number_format($coverage, 2, '.', ''),
            'room_and_board' => $room,
            'annual_premium_cost' => number_format($premium, 2, '.', ''),
            'dependent_monthly_premium' => number_format($dependentPremium, 2, '.', ''),
            'parent_dependents_allowed' => $parentAllowed ? 1 : 0,
            'is_active' => $active ? 1 : 0,
        ];

        $this->connection->beginTransaction();
        try {
            if ($planId === null) {
                $savedId = $this->hmo->createPlan($plan);
                $action = 'Plan created';
            } else {
                $this->hmo->updatePlan($planId, $plan);
                $savedId = $planId;
                $action = 'Plan updated';
            }
            $this->hmo->recordAudit($actor, $action, 'plan', $savedId, $plan);
            $this->connection->commit();

            return $savedId;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function enroll(array $input, array $certificateFiles, string $actor): int
    {
        $employeeId = filter_var($input['employee_id'], FILTER_VALIDATE_INT);
        $planId = filter_var($input['plan_id'], FILTER_VALIDATE_INT);
        $effectivityDate = $this->validDate($input['effectivity_date'], 'Effective date');

        if ($employeeId === false || $employeeId < 1 || $planId === false || $planId < 1) {
            throw new InvalidArgumentException('Choose a valid employee and HMO plan.');
        }

        $dependents = $this->parseDependents($input['dependents'], $certificateFiles);
        $this->validateDependentOrder($dependents);

        $storedCertificates = [];
        try {
            $this->connection->beginTransaction();
            $employee = $this->hmo->findEmployeeForUpdate($employeeId);
            if ($employee === null) {
                throw new DomainException('That employee could not be found.');
            }
            if ($employee['employment_status'] !== 'Active' || $employee['employment_type'] !== 'Full-time') {
                throw new DomainException('Only active full-time employees are eligible for HMO enrollment.');
            }
            if (is_string($employee['date_hired']) && $effectivityDate < $employee['date_hired']) {
                throw new DomainException('Coverage cannot start before the employee hire date.');
            }
            if ($this->hmo->hasActiveEnrollment($employeeId)) {
                throw new DomainException('This employee already has an active HMO enrollment.');
            }

            $plan = $this->hmo->findPlanForUpdate($planId);
            if ($plan === null || (int) $plan['is_active'] !== 1) {
                throw new DomainException('Choose an active HMO plan.');
            }
            if ($plan['target_job_tier'] !== $employee['job_tier']) {
                throw new DomainException('The selected plan tier does not match the employee job tier.');
            }
            $dependentMonthlyPremium = self::DEPENDENT_MONTHLY_PREMIUMS[$plan['target_job_tier']];

            foreach ($dependents as $dependent) {
                if ($dependent['relationship'] === 'Parent') {
                    if ((int) $plan['parent_dependents_allowed'] !== 1) {
                        throw new DomainException('This plan does not allow parent dependents.');
                    }
                    if ($dependent['age'] > 65) {
                        throw new DomainException('Parent dependents must be age 65 or younger.');
                    }
                }
            }

            $enrollmentId = $this->hmo->createEnrollment(
                $employeeId,
                $planId,
                $effectivityDate,
                self::DEPENDENT_COST_SHARE_PERCENT,
                number_format((float) $plan['annual_premium_cost'], 2, '.', '')
            );
            foreach ($dependents as $dependent) {
                $dependent['monthly_premium'] = (float) $dependentMonthlyPremium;
                $certificatePath = $this->storeBirthCertificate($dependent['certificate_file']);
                $storedCertificates[] = $certificatePath;
                $dependent['birth_certificate_path'] = $certificatePath;
                unset($dependent['certificate_file'], $dependent['slot']);
                $this->hmo->createDependent($enrollmentId, $dependent);
            }
            $this->hmo->recordAudit($actor, 'Enrollment created', 'enrollment', $enrollmentId, [
                'employee_id' => $employeeId,
                'plan_id' => $planId,
                'effectivity_date' => $effectivityDate,
                'dependent_count' => count($dependents),
                'dependent_cost_share_percent' => self::DEPENDENT_COST_SHARE_PERCENT,
                'dependent_monthly_premium' => (float) $dependentMonthlyPremium,
            ]);
            $this->connection->commit();

            return $enrollmentId;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            foreach ($storedCertificates as $certificatePath) {
                $this->deleteBirthCertificate($certificatePath);
            }

            throw $exception;
        }
    }

    public function editEnrollment(int $enrollmentId, array $input, array $certificateFiles, string $actor): void
    {
        if ($enrollmentId < 1) {
            throw new InvalidArgumentException('Choose a valid enrollment.');
        }
        $planId = filter_var($input['plan_id'], FILTER_VALIDATE_INT);
        $effectivityDate = $this->validDate($input['effectivity_date'], 'Effective date');
        if ($planId === false || $planId < 1) {
            throw new InvalidArgumentException('Choose a valid HMO plan.');
        }

        $dependents = $this->parseDependents($input['dependents'], $certificateFiles, false);
        $this->validateDependentOrder($dependents);
        $storedCertificates = [];
        $certificatesToDelete = [];

        try {
            $this->connection->beginTransaction();
            $enrollment = $this->hmo->findEnrollmentForUpdate($enrollmentId);
            if ($enrollment === null || $enrollment['enrollment_status'] !== 'Active') {
                throw new DomainException('Only active HMO enrollments can be edited.');
            }
            $employee = $this->hmo->findEmployeeForUpdate((int) $enrollment['employee_id']);
            if ($employee === null) {
                throw new DomainException('The employee for this enrollment could not be found.');
            }
            if (is_string($employee['date_hired']) && $effectivityDate < $employee['date_hired']) {
                throw new DomainException('Coverage cannot start before the employee hire date.');
            }
            $plan = $this->hmo->findPlanForUpdate($planId);
            if (
                $plan === null
                || ((int) $plan['is_active'] !== 1 && $planId !== (int) $enrollment['hmo_provider_id'])
            ) {
                throw new DomainException('Choose an active HMO plan.');
            }
            if ($plan['target_job_tier'] !== $employee['job_tier']) {
                throw new DomainException('The selected plan tier does not match the employee job tier.');
            }

            foreach ($dependents as $dependent) {
                if ($dependent['relationship'] === 'Parent') {
                    if ((int) $plan['parent_dependents_allowed'] !== 1) {
                        throw new DomainException('This plan does not allow parent dependents.');
                    }
                    if ($dependent['age'] > 65) {
                        throw new DomainException('Parent dependents must be age 65 or younger.');
                    }
                }
            }

            $existingDependents = $this->hmo->findEnrollmentDependentsForUpdate($enrollmentId);
            $existingById = [];
            foreach ($existingDependents as $existingDependent) {
                $existingById[(int) $existingDependent['id']] = $existingDependent;
            }
            $submittedExistingIds = [];
            $dependentRate = (float) self::DEPENDENT_MONTHLY_PREMIUMS[$plan['target_job_tier']];

            $this->hmo->updateEnrollment(
                $enrollmentId,
                $planId,
                $effectivityDate,
                number_format((float) $plan['annual_premium_cost'], 2, '.', '')
            );

            foreach ($dependents as $dependent) {
                $dependentId = $dependent['dependent_id'];
                $dependent['monthly_premium'] = $dependentRate;
                $certificateFile = $dependent['certificate_file'];
                unset($dependent['certificate_file'], $dependent['slot'], $dependent['dependent_id']);

                if ($dependentId === null) {
                    if (!is_array($certificateFile)) {
                        throw new InvalidArgumentException('Upload a birth certificate for each new dependent.');
                    }
                    $certificatePath = $this->storeBirthCertificate($certificateFile);
                    $storedCertificates[] = $certificatePath;
                    $dependent['birth_certificate_path'] = $certificatePath;
                    $this->hmo->createDependent($enrollmentId, $dependent);
                    continue;
                }

                if (!isset($existingById[$dependentId])) {
                    throw new InvalidArgumentException('A dependent selected for editing does not belong to this active enrollment.');
                }
                $submittedExistingIds[] = $dependentId;
                $dependent['birth_certificate_path'] = null;
                if (is_array($certificateFile)) {
                    $certificatePath = $this->storeBirthCertificate($certificateFile);
                    $storedCertificates[] = $certificatePath;
                    $dependent['birth_certificate_path'] = $certificatePath;
                    $oldPath = $existingById[$dependentId]['birth_certificate_path'];
                    if (is_string($oldPath) && $oldPath !== '') {
                        $certificatesToDelete[] = $oldPath;
                    }
                }
                $this->hmo->updateDependent($dependentId, $enrollmentId, $dependent);
            }

            $removedDependentIds = [];
            foreach ($existingById as $existingId => $existingDependent) {
                if (!in_array($existingId, $submittedExistingIds, true)) {
                    $this->hmo->deactivateDependent($existingId, $enrollmentId);
                    $removedDependentIds[] = $existingId;
                }
            }

            $this->hmo->recordAudit($actor, 'Enrollment updated', 'enrollment', $enrollmentId, [
                'employee_id' => (int) $enrollment['employee_id'],
                'previous_plan_id' => (int) $enrollment['hmo_provider_id'],
                'plan_id' => $planId,
                'previous_effectivity_date' => $enrollment['effectivity_date'],
                'effectivity_date' => $effectivityDate,
                'active_dependents' => count($dependents),
                'removed_dependent_ids' => $removedDependentIds,
            ]);
            $this->connection->commit();

            foreach (array_unique($certificatesToDelete) as $certificatePath) {
                $this->deleteBirthCertificate($certificatePath);
            }
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }
            foreach ($storedCertificates as $certificatePath) {
                $this->deleteBirthCertificate($certificatePath);
            }

            throw $exception;
        }
    }

    public function terminate(int $enrollmentId, string $terminationDate, string $actor): void
    {
        if ($enrollmentId < 1) {
            throw new InvalidArgumentException('Choose a valid enrollment.');
        }
        $terminationDate = $this->validDate($terminationDate, 'Termination date');

        $this->connection->beginTransaction();
        try {
            $enrollment = $this->hmo->findEnrollmentForUpdate($enrollmentId);
            if ($enrollment === null) {
                throw new DomainException('That enrollment could not be found.');
            }
            if ($enrollment['enrollment_status'] !== 'Active') {
                throw new DomainException('This enrollment has already been terminated.');
            }
            if ($terminationDate < $enrollment['effectivity_date'] || $terminationDate > date('Y-m-d')) {
                throw new InvalidArgumentException('Termination date must be between the coverage effective date and today.');
            }
            $this->hmo->terminateEnrollment($enrollmentId, $terminationDate);
            $voidedDeductions = $this->hmo->voidFutureDeductions($enrollmentId, $terminationDate);
            $this->hmo->recordAudit($actor, 'Enrollment terminated', 'enrollment', $enrollmentId, [
                'termination_date' => $terminationDate,
                'future_queued_deductions_voided' => $voidedDeductions,
            ]);
            $this->connection->commit();
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function generateDeductions(string $month, string $actor): int
    {
        $month = $this->validMonth($month);
        if ($month > date('Y-m')) {
            throw new InvalidArgumentException('Deductions cannot be queued for a future payroll month.');
        }

        $this->connection->beginTransaction();
        try {
            $added = 0;
            foreach ($this->hmo->findDependentsForMonth($month) as $dependent) {
                if ($this->hmo->queueDeduction($month, $dependent)) {
                    $added++;
                }
            }
            $this->hmo->recordAudit($actor, 'Monthly deduction queue generated', 'payroll_month', null, [
                'payroll_month' => $month,
                'new_deduction_count' => $added,
            ]);
            $this->connection->commit();

            return $added;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public function transitionDeductions(string $action, string $month, string $actor): int
    {
        $month = $this->validMonth($month);
        if (!isset(self::DEDUCTION_TRANSITIONS[$action])) {
            throw new InvalidArgumentException('Choose a valid payroll queue action.');
        }
        [$from, $to] = self::DEDUCTION_TRANSITIONS[$action];

        $this->connection->beginTransaction();
        try {
            $updated = $this->hmo->updateDeductionStatuses($month, $from, $to);
            if ($updated === 0) {
                throw new DomainException('There are no deductions in the expected state for this action.');
            }
            $this->hmo->recordAudit($actor, 'Deduction queue status changed', 'payroll_month', null, [
                'payroll_month' => $month,
                'from' => $from,
                'to' => $to,
                'deduction_count' => $updated,
            ]);
            $this->connection->commit();

            return $updated;
        } catch (Throwable $exception) {
            if ($this->connection->inTransaction()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }

    public static function validMonth(string $month): string
    {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            throw new InvalidArgumentException('Choose a valid payroll month.');
        }

        return $month;
    }

    private function parseDependents(
        mixed $input,
        array $certificateFiles,
        bool $requireCertificate = true
    ): array
    {
        if (!is_array($input) || count($input) > 2) {
            throw new InvalidArgumentException('A maximum of two dependents may be enrolled.');
        }

        ksort($input);
        $dependents = [];
        foreach ($input as $slot => $row) {
            if (!in_array($slot, [0, 1], true) || !is_array($row)) {
                throw new InvalidArgumentException('Dependent details are invalid.');
            }
            $lastName = isset($row['last_name']) && is_string($row['last_name']) ? trim($row['last_name']) : '';
            $firstName = isset($row['first_name']) && is_string($row['first_name']) ? trim($row['first_name']) : '';
            $middleInitial = isset($row['middle_initial']) && is_string($row['middle_initial'])
                ? trim($row['middle_initial'])
                : '';
            $nameExtension = isset($row['name_extension']) && is_string($row['name_extension'])
                ? trim($row['name_extension'])
                : '';
            $relationship = isset($row['relationship']) && is_string($row['relationship'])
                ? $row['relationship']
                : '';
            $sex = isset($row['sex']) && is_string($row['sex']) ? $row['sex'] : '';
            $civilStatus = isset($row['civil_status']) && is_string($row['civil_status'])
                ? $row['civil_status']
                : '';
            $ageInput = isset($row['age']) && is_string($row['age']) ? trim($row['age']) : '';
            $dependentIdInput = isset($row['dependent_id']) && is_string($row['dependent_id'])
                ? trim($row['dependent_id'])
                : '';
            $dependentId = $dependentIdInput === ''
                ? null
                : filter_var($dependentIdInput, FILTER_VALIDATE_INT);
            if ($dependentId === false || ($dependentId !== null && $dependentId < 1)) {
                throw new InvalidArgumentException('Dependent details are invalid.');
            }
            $certificateFile = $certificateFiles[$slot] ?? null;
            $hasUploadedCertificate = is_array($certificateFile)
                && ($certificateFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if (!$hasUploadedCertificate) {
                $certificateFile = null;
            }

            if (
                $lastName === '' && $firstName === '' && $middleInitial === ''
                && $nameExtension === '' && $relationship === '' && $sex === ''
                && $civilStatus === '' && $ageInput === '' && !$hasUploadedCertificate
            ) {
                continue;
            }
            if (
                $lastName === '' || mb_strlen($lastName) > 100
                || $firstName === '' || mb_strlen($firstName) > 100
                || mb_strlen($middleInitial) > 5
                || mb_strlen($nameExtension) > 20
            ) {
                throw new InvalidArgumentException('Enter a dependent’s last and first name; middle initial and name extension are optional.');
            }
            if (!in_array($relationship, ['Spouse', 'Child', 'Parent'], true)) {
                throw new InvalidArgumentException('Choose a valid dependent relationship.');
            }
            if (!in_array($sex, ['Female', 'Male'], true)) {
                throw new InvalidArgumentException('Choose a valid dependent sex.');
            }
            if (!in_array($civilStatus, ['Single', 'Married', 'Widowed', 'Separated'], true)) {
                throw new InvalidArgumentException('Choose a valid dependent civil status.');
            }
            $age = filter_var($ageInput, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 120]]);
            if ($age === false) {
                throw new InvalidArgumentException('Enter a dependent age between 0 and 120.');
            }
            if (!is_array($certificateFile) && ($requireCertificate || $dependentId === null)) {
                throw new InvalidArgumentException('Upload a birth certificate for each new dependent.');
            }
            if (is_array($certificateFile)) {
                $this->validateBirthCertificate($certificateFile);
            }
            $name = implode(' ', array_filter([
                $firstName,
                $middleInitial === '' ? '' : $middleInitial . '.',
                $nameExtension,
            ])) . ' ' . $lastName;
            if (mb_strlen($name) > 150) {
                throw new InvalidArgumentException('A dependent’s full name must be 150 characters or fewer.');
            }

            $dependents[] = [
                'slot' => $slot,
                'dependent_id' => $dependentId,
                'name' => $name,
                'last_name' => $lastName,
                'first_name' => $firstName,
                'middle_initial' => $middleInitial === '' ? null : $middleInitial,
                'name_extension' => $nameExtension === '' ? null : $nameExtension,
                'age' => $age,
                'relationship' => $relationship,
                'sex' => $sex,
                'civil_status' => $civilStatus,
                'certificate_file' => $certificateFile,
            ];
        }

        $includedSlots = array_column($dependents, 'slot');
        if (in_array(1, $includedSlots, true) && !in_array(0, $includedSlots, true)) {
            throw new InvalidArgumentException('Complete dependent 1 before adding dependent 2.');
        }

        return $dependents;
    }

    private function validateDependentOrder(array $dependents): void
    {
        if (count($dependents) > 2) {
            throw new InvalidArgumentException('A maximum of two dependents may be enrolled.');
        }
        $rank = ['Spouse' => 0, 'Child' => 1, 'Parent' => 2];
        $previousRank = -1;
        $spouseCount = 0;
        $previousChildAge = null;
        foreach ($dependents as $dependent) {
            $currentRank = $rank[$dependent['relationship']];
            if ($currentRank < $previousRank) {
                throw new InvalidArgumentException('List dependents in policy order: spouse, children youngest-to-oldest, then eligible parent.');
            }
            if ($dependent['relationship'] === 'Spouse' && ++$spouseCount > 1) {
                throw new InvalidArgumentException('Only one spouse may be enrolled.');
            }
            if ($dependent['relationship'] === 'Child') {
                if ($previousChildAge !== null && $dependent['age'] < $previousChildAge) {
                    throw new InvalidArgumentException('List children from youngest to oldest.');
                }
                $previousChildAge = $dependent['age'];
            }
            $previousRank = $currentRank;
        }
    }

    private function validateBirthCertificate(array $file): void
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Upload a birth certificate for each dependent (PDF, JPG, or PNG; maximum 5 MB).');
        }
        if (
            !isset($file['tmp_name'], $file['size'])
            || !is_string($file['tmp_name'])
            || !is_uploaded_file($file['tmp_name'])
            || !is_int($file['size'])
            || $file['size'] < 1
            || $file['size'] > 5 * 1024 * 1024
        ) {
            throw new InvalidArgumentException('Each birth certificate must be a valid upload under 5 MB.');
        }

        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        if (!is_string($mimeType) || !in_array($mimeType, ['application/pdf', 'image/jpeg', 'image/png'], true)) {
            throw new InvalidArgumentException('Birth certificates must be PDF, JPG, or PNG files.');
        }
    }

    private function storeBirthCertificate(array $file): string
    {
        $mimeType = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
        $extensions = [
            'application/pdf' => 'pdf',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
        ];
        if (!is_string($mimeType) || !isset($extensions[$mimeType])) {
            throw new InvalidArgumentException('Birth certificate file type is not supported.');
        }

        $relativePath = 'uploads/hmo/birth-certificates/' . bin2hex(random_bytes(24)) . '.' . $extensions[$mimeType];
        $absolutePath = __DIR__ . '/../../' . $relativePath;
        $directory = dirname($absolutePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('The private birth certificate folder could not be created.');
        }
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            throw new RuntimeException('A birth certificate could not be stored.');
        }
        if (!chmod($absolutePath, 0600)) {
            unlink($absolutePath);
            throw new RuntimeException('Birth certificate file permissions could not be restricted.');
        }

        return $relativePath;
    }

    private function deleteBirthCertificate(string $relativePath): void
    {
        if (!str_starts_with($relativePath, 'uploads/hmo/birth-certificates/')) {
            return;
        }
        $absolutePath = __DIR__ . '/../../' . $relativePath;
        if (is_file($absolutePath) && !unlink($absolutePath)) {
            error_log('HMO enrollment rollback could not remove birth certificate ' . basename($absolutePath));
        }
    }

    private function positiveAmount(string $input, string $label): float
    {
        if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $input)) {
            throw new InvalidArgumentException($label . ' must be a positive amount with up to two decimal places.');
        }
        $amount = (float) $input;
        $maximum = str_contains($label, 'Dependent monthly premium') ? 99999999.99 : 9999999999.99;
        if ($amount <= 0 || $amount > $maximum) {
            throw new InvalidArgumentException($label . ' must be greater than zero.');
        }

        return $amount;
    }

    private function validDate(string $date, string $label): string
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        $errors = DateTimeImmutable::getLastErrors();
        if (
            $parsed === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format('Y-m-d') !== $date
        ) {
            throw new InvalidArgumentException($label . ' must be a valid date.');
        }

        return $date;
    }
}
