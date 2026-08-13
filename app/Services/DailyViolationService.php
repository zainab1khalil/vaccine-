<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\Violation;
use App\Models\CCTVViolation;
use App\Models\DisciplinaryAction;
use App\Models\Fingerprint;
use App\Models\Leave;
use App\Models\EmployeeSchedule;
use Carbon\Carbon;

class DailyViolationService
{
    /**
     * Calculate violations for a specific date range (7am to 6am next day)
     * This captures all shifts for the target date
     */
    public function calculateDailyViolations(string $targetDate, ?int $departmentId = null, ?string $violationType = null): array
    {
        // Calculate the time window: targetDate 7am to nextDay 6am
        $startDateTime = Carbon::parse($targetDate)->setHour(7)->setMinute(0)->setSecond(0);
        $endDateTime = Carbon::parse($targetDate)->addDay()->setHour(6)->setMinute(59)->setSecond(59);

        $violations = [];
        
        // Get employees based on department filter
        $employeeQuery = Employee::with('department');
        if ($departmentId) {
            $employeeQuery->where('department_id', $departmentId);
        }
        $employees = $employeeQuery->get();

        foreach ($employees as $employee) {
            $employeeViolations = $this->calculateEmployeeViolations(
                $employee, 
                $targetDate, 
                $startDateTime, 
                $endDateTime,
                $violationType
            );
            
            if (!empty($employeeViolations)) {
                $violations[] = [
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->name,
                    'department' => $employee->department?->name,
                    'phone_number' => $employee->phone_number,
                    'violations' => $employeeViolations,
                ];
            }
        }

        return [
            'target_date' => $targetDate,
            'calculation_window' => [
                'start' => $startDateTime->toDateTimeString(),
                'end' => $endDateTime->toDateTimeString(),
            ],
            'total_employees_with_violations' => count($violations),
            'violations' => $violations,
        ];
    }

    /**
     * Calculate violations for a single employee
     */
    private function calculateEmployeeViolations(
        Employee $employee, 
        string $targetDate, 
        Carbon $startDateTime, 
        Carbon $endDateTime,
        ?string $violationTypeFilter
    ): array {
        $violations = [];

        // 1. Check for absence (no punches for scheduled work day)
        $absenceViolation = $this->checkAbsence($employee, $targetDate);
        if ($absenceViolation && $this->matchesFilter($absenceViolation['type'], $violationTypeFilter)) {
            $violations[] = $absenceViolation;
        }

        // 2. Check for lateness
        $lateViolations = $this->checkLateness($employee, $targetDate);
        foreach ($lateViolations as $late) {
            if ($this->matchesFilter($late['type'], $violationTypeFilter)) {
                $violations[] = $late;
            }
        }

        // 3. Check for early leave
        $earlyLeaveViolations = $this->checkEarlyLeave($employee, $targetDate);
        foreach ($earlyLeaveViolations as $early) {
            if ($this->matchesFilter($early['type'], $violationTypeFilter)) {
                $violations[] = $early;
            }
        }

        // 4. Check for CCTV violations
        $cctvViolations = $this->checkCCTVViolations($employee, $targetDate);
        foreach ($cctvViolations as $cctv) {
            if ($this->matchesFilter($cctv['type'], $violationTypeFilter)) {
                $violations[] = $cctv;
            }
        }

        // 5. Check for disciplinary actions
        $disciplinaryViolations = $this->checkDisciplinaryActions($employee, $targetDate);
        foreach ($disciplinaryViolations as $disciplinary) {
            if ($this->matchesFilter($disciplinary['type'], $violationTypeFilter)) {
                $violations[] = $disciplinary;
            }
        }

        return $violations;
    }

    /**
     * Check if employee was absent on a scheduled work day
     */
    private function checkAbsence(Employee $employee, string $targetDate): ?array
    {
        // Check if there's an approved leave
        $leave = Leave::where('employee_id', $employee->employee_id)
            ->where('leave_date', $targetDate)
            ->where('status', 'approved')
            ->first();

        if ($leave) {
            return null; // Not absent if on approved leave
        }

        // Check if employee had any punches on the target date
        $hasPunches = Fingerprint::where('employee_id', $employee->employee_id)
            ->where('punch_date', $targetDate)
            ->exists();

        // Check if it was a scheduled work day
        $schedule = EmployeeSchedule::where('employee_id', $employee->employee_id)
            ->where('day', (int)Carbon::parse($targetDate)->format('d'))
            ->first();

        $isOffDay = !$schedule || in_array(strtoupper($schedule->shift_code), ['O', 'OFF', 'H', 'HOL', 'V', 'VAC', 'R', 'REST']);

        if (!$hasPunches && !$isOffDay) {
            return [
                'type' => 'absence',
                'violation_type' => 'غياب بدون إذن',
                'violation_date' => $targetDate,
                'violation_details' => 'غياب في يوم عمل مجدول بدون إجازة معتمدة',
                'article_number' => 'مخالفة انضباطية',
                'penalty_description' => 'سيتم تطبيق الإجراءات المنصوص عليها في لائحة المخالفات',
            ];
        }

        return null;
    }

    /**
     * Check for lateness violations
     */
    private function checkLateness(Employee $employee, string $targetDate): array
    {
        $violations = [];
        
        // Get expected start time from schedule
        $schedule = EmployeeSchedule::where('employee_id', $employee->employee_id)
            ->where('day', (int)Carbon::parse($targetDate)->format('d'))
            ->first();

        if (!$schedule) return $violations;

        $shiftCode = strtoupper($schedule->shift_code);
        $expectedStart = $this->getExpectedStartTime($shiftCode);
        
        if (!$expectedStart) return $violations;

        // Get first punch of the day
        $firstPunch = Fingerprint::where('employee_id', $employee->employee_id)
            ->where('punch_date', $targetDate)
            ->orderBy('punch_time', 'asc')
            ->first();

        if (!$firstPunch) return $violations;

        $punchTime = Carbon::parse($firstPunch->punch_time);
        $expectedTime = Carbon::parse($expectedStart);

        // Calculate lateness in minutes
        if ($punchTime->gt($expectedTime)) {
            $lateMinutes = $punchTime->diffInMinutes($expectedTime);
            
            if ($lateMinutes > 0) {
                $violation = $this->categorizeLateness($lateMinutes);
                $violations[] = [
                    'type' => 'late',
                    'violation_type' => $violation['description'],
                    'violation_date' => $targetDate,
                    'violation_details' => "تأخير {$lateMinutes} دقيقة عن وقت الدوام الرسمي",
                    'article_number' => $violation['article'],
                    'penalty_description' => $violation['penalty'],
                ];
            }
        }

        return $violations;
    }

    /**
     * Check for early leave violations
     */
    private function checkEarlyLeave(Employee $employee, string $targetDate): array
    {
        $violations = [];
        
        // Get expected end time from schedule
        $schedule = EmployeeSchedule::where('employee_id', $employee->employee_id)
            ->where('day', (int)Carbon::parse($targetDate)->format('d'))
            ->first();

        if (!$schedule) return $violations;

        $shiftCode = strtoupper($schedule->shift_code);
        $expectedEnd = $this->getExpectedEndTime($shiftCode);
        
        if (!$expectedEnd) return $violations;

        // Get last punch of the day
        $lastPunch = Fingerprint::where('employee_id', $employee->employee_id)
            ->where('punch_date', $targetDate)
            ->orderBy('punch_time', 'desc')
            ->first();

        if (!$lastPunch) return $violations;

        $punchTime = Carbon::parse($lastPunch->punch_time);
        $expectedTime = Carbon::parse($expectedEnd);

        // Handle overnight shifts
        if ($this->isOvernightShift($shiftCode) && $punchTime->lt($expectedTime)) {
            $punchTime->addDay();
        }

        // Calculate early leave in minutes
        if ($punchTime->lt($expectedTime)) {
            $earlyMinutes = $expectedTime->diffInMinutes($punchTime);
            
            if ($earlyMinutes > 0) {
                $violation = $this->categorizeEarlyLeave($earlyMinutes);
                $violations[] = [
                    'type' => 'early_leave',
                    'violation_type' => $violation['description'],
                    'violation_date' => $targetDate,
                    'violation_details' => "مغادرة مبكرة {$earlyMinutes} دقيقة قبل انتهاء الدوام الرسمي",
                    'article_number' => $violation['article'],
                    'penalty_description' => $violation['penalty'],
                ];
            }
        }

        return $violations;
    }

    /**
     * Check for CCTV violations
     */
    private function checkCCTVViolations(Employee $employee, string $targetDate): array
    {
        $violations = [];
        
        $cctvViolations = CCTVViolation::where('employee_id', $employee->employee_id)
            ->where('violation_date', $targetDate)
            ->get();

        foreach ($cctvViolations as $cctv) {
            $violations[] = [
                'type' => 'cctv',
                'violation_type' => $cctv->violation_type,
                'violation_date' => $cctv->violation_date,
                'violation_details' => $cctv->description,
                'article_number' => 'مخالفة مراقبة',
                'penalty_description' => $cctv->penalty_days > 0 
                    ? "خصم {$cctv->penalty_days} أيام من الراتب" 
                    : 'سيتم تطبيق الإجراءات المنصوص عليها',
            ];
        }

        return $violations;
    }

    /**
     * Check for disciplinary actions
     */
    private function checkDisciplinaryActions(Employee $employee, string $targetDate): array
    {
        $violations = [];
        
        $actions = DisciplinaryAction::where('employee_id', $employee->employee_id)
            ->whereDate('created_at', $targetDate)
            ->get();

        foreach ($actions as $action) {
            $severityText = match($action->severity) {
                'low' => 'منخفضة',
                'medium' => 'متوسطة',
                'high' => 'عالية',
                default => '—',
            };

            $violations[] = [
                'type' => 'disciplinary',
                'violation_type' => $action->action_type,
                'violation_date' => $targetDate,
                'violation_details' => $action->note,
                'article_number' => 'إجراء تأديبي',
                'penalty_description' => "درجة الخطورة: {$severityText}",
            ];
        }

        return $violations;
    }

    /**
     * Categorize lateness based on minutes
     */
    private function categorizeLateness(int $minutes): array
    {
        if ($minutes <= 15) {
            return [
                'description' => 'التأخير لغاية 15 دقيقة',
                'article' => 'المادة الأولى',
                'penalty' => 'تنبيه شفوي',
            ];
        } elseif ($minutes <= 30) {
            return [
                'description' => 'التأخير 15–30 دقيقة',
                'article' => 'المادة الثانية',
                'penalty' => 'تنبيه مع خصم مدة التأخير',
            ];
        } elseif ($minutes <= 60) {
            return [
                'description' => 'التأخير 30–60 دقيقة',
                'article' => 'المادة الثالثة',
                'penalty' => 'تنبيه مع خصم مدة التأخير',
            ];
        } else {
            return [
                'description' => 'التأخير أكثر من 60 دقيقة',
                'article' => 'المادة الرابعة',
                'penalty' => 'إنذار كتابي مع خصم مدة التأخير',
            ];
        }
    }

    /**
     * Categorize early leave based on minutes
     */
    private function categorizeEarlyLeave(int $minutes): array
    {
        if ($minutes <= 15) {
            return [
                'description' => 'مغادرة مبكرة لغاية 15 دقيقة',
                'article' => 'المادة الأولى',
                'penalty' => 'تنبيه شفوي',
            ];
        } elseif ($minutes <= 30) {
            return [
                'description' => 'مغادرة مبكرة 15–30 دقيقة',
                'article' => 'المادة الثانية',
                'penalty' => 'تنبيه مع خصم مدة المغادرة',
            ];
        } elseif ($minutes <= 60) {
            return [
                'description' => 'مغادرة مبكرة 30–60 دقيقة',
                'article' => 'المادة الثالثة',
                'penalty' => 'تنبيه مع خصم مدة المغادرة',
            ];
        } else {
            return [
                'description' => 'مغادرة مبكرة أكثر من 60 دقيقة',
                'article' => 'المادة الرابعة',
                'penalty' => 'إنذار كتابي مع خصم مدة المغادرة',
            ];
        }
    }

    /**
     * Get expected start time from shift code
     */
    private function getExpectedStartTime(string $shiftCode): ?string
    {
        $shiftTimes = [
            'A1' => '08:00',
            'A2' => '07:00',
            'K' => '13:00',
            'k' => '13:00',
            'N' => '19:00',
            'D' => '07:00',
            'M1' => '08:00',
            'M2' => '20:00',
            'L' => '06:00',
            'L1' => '06:00',
        ];

        return $shiftTimes[$shiftCode] ?? null;
    }

    /**
     * Get expected end time from shift code
     */
    private function getExpectedEndTime(string $shiftCode): ?string
    {
        $shiftTimes = [
            'A1' => '16:00',
            'A2' => '15:00',
            'K' => '21:00',
            'k' => '21:00',
            'N' => '07:00', // Next day
            'D' => '19:00',
            'M1' => '20:00',
            'M2' => '08:00', // Next day
            'L' => '18:00',
            'L1' => '14:00',
        ];

        return $shiftTimes[$shiftCode] ?? null;
    }

    /**
     * Check if shift is overnight
     */
    private function isOvernightShift(string $shiftCode): bool
    {
        return in_array($shiftCode, ['N', 'M2', 'N1', 'N2', 'N3', 'N4']);
    }

    /**
     * Check if violation matches filter
     */
    private function matchesFilter(string $violationType, ?string $filter): bool
    {
        if (!$filter) return true;
        
        $filterMap = [
            'absence' => ['absence'],
            'late' => ['late'],
            'early_leave' => ['early_leave'],
            'disciplinary' => ['disciplinary'],
            'cctv' => ['cctv'],
        ];

        $matchingTypes = $filterMap[$filter] ?? [$filter];
        return in_array($violationType, $matchingTypes);
    }
}