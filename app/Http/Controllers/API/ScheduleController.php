<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\EmployeeSchedule;
use App\Models\MonthlySchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ScheduleController extends Controller
{
    // GET /api/schedules/{depId}/{month}/{year}
    public function getForDepartment(int $depId, int $month, int $year): JsonResponse
    {
        $dep = Department::findOrFail($depId);

        $schedules = EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->with('employee')
            ->get()
            ->groupBy('employee_id');

        // Build per-employee schedule grid
        $result = $schedules->map(function ($rows, $empId) {
            $emp = $rows->first()->employee;
            $grid = [];
            foreach ($rows as $row) {
                $grid[$row->day] = $row->shift_code;
            }
            return [
                'employee_id' => $empId,
                'name'        => $emp?->name,
                'job_title'   => $emp?->job_title,
                'shift_type'  => $emp?->shift_type,
                'schedule'    => $grid,
                'working_days'=> count(array_filter($grid, fn($c) => EmployeeSchedule::isWorkingCode($c))),
            ];
        })->values();

        $uploaded = MonthlySchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->first();

        return response()->json([
            'department'        => ['id' => $dep->id, 'name' => $dep->name],
            'month'             => $month,
            'year'              => $year,
            'schedule_uploaded' => (bool)$uploaded,
            'upload_date'       => $uploaded?->created_at,
            'employees'         => $result,
        ]);
    }

    // POST /api/schedules/upload
    // Receives parsed schedule data from the frontend (from the Excel upload)
    // Format: {
    //   department_id, department_name, month, year,
    //   employees: [{ employee_id, days: { 1: "M", 2: "O", ... } }]
    // }
    public function upload(Request $request): JsonResponse
    {
        $request->validate([
            'department_id'            => 'required|integer',
            'month'                    => 'required|integer|between:1,12',
            'year'                     => 'required|integer|min:2020',
            'employees'                => 'required|array|min:1',
            'employees.*.employee_id'  => 'required|string',
            'employees.*.days'         => 'required|array',
        ]);

        $depId     = $request->department_id;
        $depName   = $request->department_name ?? Department::find($depId)?->name ?? "قسم {$depId}";
        $month     = $request->month;
        $year      = $request->year;
        $employees = $request->employees;

        // De-duplicate employees by employee_id (last one wins)
        $empMap = [];
        foreach ($employees as $empData) {
            $empId = trim($empData['employee_id']);
            if ($empId) $empMap[$empId] = $empData;
        }

        $supabaseUrl = 'https://vuezoztxocpzooatxuxo.supabase.co';
        $supabaseKey = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJzdXBhYmFzZSIsInJlZiI6InZ1ZXpvenR4b2Nwem9vYXR4dXhvIiwicm9sZSI6ImFub24iLCJpYXQiOjE3ODQzNzE2NjgsImV4cCI6MjA5OTk0NzY2OH0.5w6L9zsSxoYG-GWrn1g1a_y7pXVSXFam8B1di4MsCAo';

        try {
            // 1. Upsert employees to Supabase
            foreach ($empMap as $empId => $empData) {
                $name = isset($empData['name']) ? trim($empData['name']) : $empId;
                
                $response = Http::timeout(30)->withHeaders([
                    'apikey' => $supabaseKey,
                    'Authorization' => "Bearer {$supabaseKey}",
                    'Content-Type' => 'application/json',
                    'Prefer' => 'resolution=ignore-duplicates'
                ])->post("{$supabaseUrl}/rest/v1/employees", [
                    'employee_id' => $empId,
                    'name' => $name,
                    'department_id' => $depId,
                    'shift_type' => '8hr',
                    'full_or_part' => 'full',
                ]);

                if (!$response->successful()) {
                    throw new \Exception("Failed to upsert employee {$empId}: " . $response->body());
                }
            }

            // 2. Delete existing schedules for this dept/month/year
            $deleteResponse = Http::timeout(30)->withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => "Bearer {$supabaseKey}",
            ])->delete("{$supabaseUrl}/rest/v1/employee_schedules", [
                'department_id' => "eq.{$depId}",
                'month' => "eq.{$month}",
                'year' => "eq.{$year}",
            ]);

            // 3. Insert new schedule rows
            $insertedRows = 0;
            $allRows = [];

            foreach ($empMap as $empId => $empData) {
                $days = $empData['days'] ?? [];
                foreach ($days as $day => $code) {
                    if (!$code || trim($code) === '') continue;
                    $allRows[] = [
                        'employee_id' => $empId,
                        'department_id' => $depId,
                        'month' => (int)$month,
                        'year' => (int)$year,
                        'day' => (int)$day,
                        'shift_code' => strtoupper(trim((string)$code)),
                    ];
                }
            }

            foreach (array_chunk($allRows, 100) as $chunk) {
                $insertResponse = Http::timeout(30)->withHeaders([
                    'apikey' => $supabaseKey,
                    'Authorization' => "Bearer {$supabaseKey}",
                    'Content-Type' => 'application/json',
                    'Prefer' => 'resolution=ignore-duplicates'
                ])->post("{$supabaseUrl}/rest/v1/employee_schedules", $chunk);

                if (!$insertResponse->successful()) {
                    throw new \Exception("Failed to insert schedules: " . $insertResponse->body());
                }
                $insertedRows += count($chunk);
            }

            // 4. Mark schedule as uploaded
            $deleteMonthlyResponse = Http::timeout(30)->withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => "Bearer {$supabaseKey}",
            ])->delete("{$supabaseUrl}/rest/v1/monthly_schedules", [
                'department_id' => "eq.{$depId}",
                'month' => "eq.{$month}",
                'year' => "eq.{$year}",
            ]);

            $monthlyResponse = Http::withHeaders([
                'apikey' => $supabaseKey,
                'Authorization' => "Bearer {$supabaseKey}",
                'Content-Type' => 'application/json',
            ])->post("{$supabaseUrl}/rest/v1/monthly_schedules", [
                'department_id' => $depId,
                'department_name' => $depName,
                'month' => (int)$month,
                'year' => (int)$year,
                'uploaded_by' => 'dashboard',
            ]);

            if (!$monthlyResponse->successful()) {
                throw new \Exception("Failed to mark schedule as uploaded: " . $monthlyResponse->body());
            }

            return response()->json([
                'success'         => true,
                'department'      => $depName,
                'month'           => $month,
                'year'            => $year,
                'employees_count' => count($empMap),
                'schedule_rows'   => $insertedRows,
                'message'         => "تم رفع جدول {$depName} بنجاح — {$insertedRows} خلية جدول",
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    // DELETE /api/schedules/{depId}/{month}/{year}
    public function delete(int $depId, int $month, int $year): JsonResponse
    {
        EmployeeSchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        MonthlySchedule::where('department_id', $depId)
            ->where('month', $month)
            ->where('year', $year)
            ->delete();

        return response()->json(['message' => 'Schedule deleted']);
    }
}
