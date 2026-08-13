<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\DailyViolationService;
use App\Services\WhatsAppNotificationService;
use App\Models\Employee;
use App\Models\Violation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class DailyViolationController extends Controller
{
    public function __construct(
        private DailyViolationService $dailyService,
        private WhatsAppNotificationService $whatsapp
    ) {}

    /**
     * GET /api/daily-violations/{date}?department_id=&violation_type=
     * Get daily violation report for a specific date (7am-6am cycle)
     */
    public function getDailyViolations(string $date, Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');
        $violationType = $request->query('violation_type');

        $report = $this->dailyService->calculateDailyViolations($date, $departmentId, $violationType);

        return response()->json($report);
    }

    /**
     * POST /api/daily-violations/{date}/notify
     * Send WhatsApp notifications for all violations on a specific date
     */
    public function sendDailyNotifications(string $date, Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');
        $violationType = $request->query('violation_type');

        $report = $this->dailyService->calculateDailyViolations($date, $departmentId, $violationType);

        $notificationResults = [];
        $totalViolations = 0;
        $successfulNotifications = 0;
        $failedNotifications = 0;

        foreach ($report['violations'] as $employeeData) {
            $employee = Employee::where('employee_id', $employeeData['employee_id'])->first();
            if (!$employee) continue;

            foreach ($employeeData['violations'] as $violation) {
                $totalViolations++;

                // Create a temporary violation object for WhatsApp service
                $tempViolation = new Violation([
                    'employee_id' => $employee->employee_id,
                    'violation_category' => $violation['violation_type'],
                    'violation_row' => $this->getViolationRow($violation['type']),
                    'incident_date' => $violation['violation_date'],
                    'notes' => $violation['violation_details'],
                    'penalty' => $violation['penalty_description'],
                ]);

                $result = $this->whatsapp->sendViolationNotification($employee, $tempViolation);

                $notificationResults[] = [
                    'employee_id' => $employee->employee_id,
                    'employee_name' => $employee->name,
                    'violation_type' => $violation['violation_type'],
                    'success' => $result['success'],
                    'message' => $result['message'] ?? null,
                ];

                if ($result['success']) {
                    $successfulNotifications++;
                } else {
                    $failedNotifications++;
                }
            }
        }

        return response()->json([
            'date' => $date,
            'calculation_window' => $report['calculation_window'],
            'total_violations' => $totalViolations,
            'successful_notifications' => $successfulNotifications,
            'failed_notifications' => $failedNotifications,
            'notification_results' => $notificationResults,
        ]);
    }

    /**
     * POST /api/daily-violations/test-employee-2812
     * Test the system with employee ID 2812 and early leave violation
     */
    public function testEmployee2812(Request $request): JsonResponse
    {
        $employeeId = '2812';
        $date = $request->input('date', Carbon::today()->toDateString());

        // Get employee 2812
        $employee = Employee::where('employee_id', $employeeId)->first();
        if (!$employee) {
            return response()->json([
                'success' => false,
                'message' => 'Employee 2812 not found in database',
            ], 404);
        }

        // Create a test early leave violation
        $testViolation = new Violation([
            'employee_id' => $employeeId,
            'violation_category' => 'مغادرة مبكرة',
            'violation_row' => 1,
            'incident_date' => $date,
            'notes' => 'مغادرة مبكرة 10 دقائق - اختبار النظام',
            'penalty' => 'تنبيه شفوي',
        ]);

        // Send WhatsApp notification
        $result = $this->whatsapp->sendViolationNotification($employee, $testViolation);

        return response()->json([
            'employee' => [
                'employee_id' => $employee->employee_id,
                'name' => $employee->name,
                'phone_number' => $employee->phone_number,
            ],
            'test_violation' => [
                'type' => 'early_leave',
                'violation_type' => 'مغادرة مبكرة لغاية 15 دقيقة',
                'violation_date' => $date,
                'violation_details' => 'مغادرة مبكرة 10 دقائق - اختبار النظام',
                'article_number' => 'المادة الأولى',
                'penalty_description' => 'تنبيه شفوي',
            ],
            'whatsapp_result' => $result,
        ]);
    }

    /**
     * GET /api/daily-violations/summary/{date}
     * Get summary statistics for daily violations
     */
    public function getDailySummary(string $date, Request $request): JsonResponse
    {
        $departmentId = $request->query('department_id');

        $report = $this->dailyService->calculateDailyViolations($date, $departmentId, null);

        // Calculate summary statistics
        $summary = [
            'absence' => 0,
            'late' => 0,
            'early_leave' => 0,
            'cctv' => 0,
            'disciplinary' => 0,
        ];

        foreach ($report['violations'] as $employeeData) {
            foreach ($employeeData['violations'] as $violation) {
                if (isset($summary[$violation['type']])) {
                    $summary[$violation['type']]++;
                }
            }
        }

        return response()->json([
            'date' => $date,
            'calculation_window' => $report['calculation_window'],
            'employees_with_violations' => $report['total_employees_with_violations'],
            'violation_breakdown' => $summary,
            'total_violations' => array_sum($summary),
        ]);
    }

    /**
     * Map violation type to violation row number
     */
    private function getViolationRow(string $type): int
    {
        return match($type) {
            'absence' => 6, // Use article 6 for absence
            'late' => 1,    // Will be determined by severity
            'early_leave' => 1,
            'cctv' => 6,
            'disciplinary' => 6,
            default => 1,
        };
    }
}