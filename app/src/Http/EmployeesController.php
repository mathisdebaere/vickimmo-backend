<?php

namespace Http;

use \Services\ApiResponse;
use \Services\DatabaseConnector;

class EmployeesController
{
    protected \Doctrine\DBAL\Connection $db;

    public function __construct()
    {
        $this->db = DatabaseConnector::getConnection();
    }

    public function methodNotAllowed(): void
    {
        ApiResponse::error('HTTP request method ' . $_SERVER['REQUEST_METHOD'] . ' not allowed.', 405);
    }

    public function overview(int $agencyID): void
    {
        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $employees = $this->db->fetchAllAssociative(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at
             FROM users u
             JOIN agencies_employees ae ON u.id = ae.user_id
             WHERE ae.agency_id = ?
             ORDER BY u.name ASC',
            [$agencyID]
        );

        ApiResponse::success([
            'agency_id' => $agencyID,
            'employees' => array_map(fn($emp) => [
                'id' => (int)$emp['id'],
                'name' => $emp['name'],
                'email' => $emp['email'],
                'phone' => $emp['phone'] ?? null,
                'role' => $emp['role'],
                'created_at' => $emp['created_at']
            ], $employees)
        ]);
    }

    public function post(int $agencyID): void
    {
        $data = $this->getJsonInput();
        if ($data === null) {
            return;
        }

        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $errors = $this->validateEmployeeData($data);
        
        if (!empty($errors)) {
            ApiResponse::error(json_encode($errors), 422);
            return;
        }

        $userID = (int)$data['user_id'];

        if (!$this->userExists($userID)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        if ($this->isEmployeeOfAgency($agencyID, $userID)) {
            ApiResponse::error('User is already an employee of this agency.', 409);
            return;
        }

        // Check if user is admin or owner - they cannot become employees
        $userRole = $this->getUserRole($userID);
        if (in_array($userRole, ['admin', 'owner'], true)) {
            ApiResponse::error('Users with role "admin" or "owner" cannot be added as employees.', 422);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Add employee to agency
            $this->db->executeStatement(
                'INSERT INTO agencies_employees (user_id, agency_id) VALUES (?, ?)',
                [$userID, $agencyID]
            );

            // Update user role to employee if they are currently a client
            if ($userRole === 'client') {
                $this->db->executeStatement(
                    'UPDATE users SET role = ? WHERE id = ?',
                    ['employee', $userID]
                );
            }

            $this->db->commit();

            ApiResponse::success([
                'message' => 'Employee added to agency successfully.' . 
                             ($userRole === 'client' ? ' User role updated to employee.' : ''),
                'agency_id' => $agencyID,
                'user_id' => $userID,
                'previous_role' => $userRole,
                'new_role' => 'employee'
            ], status: 201);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to add employee: ' . $e->getMessage(), 500);
        }
    }

    public function getByID(int $agencyID, int $employeeID): void
    {
        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $employee = $this->db->fetchAssociative(
            'SELECT u.id, u.name, u.email, u.phone, u.role, u.created_at, u.updated_at
             FROM users u
             JOIN agencies_employees ae ON u.id = ae.user_id
             WHERE ae.agency_id = ? AND u.id = ?',
            [$agencyID, $employeeID]
        );

        if ($employee === false) {
            ApiResponse::error('Employee not found in this agency.', 404);
            return;
        }

        ApiResponse::success([
            'id' => (int)$employee['id'],
            'name' => $employee['name'],
            'email' => $employee['email'],
            'phone' => $employee['phone'] ?? null,
            'role' => $employee['role'],
            'agency_id' => $agencyID,
            'created_at' => $employee['created_at'],
            'updated_at' => $employee['updated_at']
        ]);
    }

    public function deleteByID(int $agencyID, int $employeeID): void
    {
        if (!$this->isEmployeeOfAgency($agencyID, $employeeID)) {
            ApiResponse::error('Employee not found in this agency.', 404);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Remove employee from agency
            $this->db->executeStatement(
                'DELETE FROM agencies_employees WHERE user_id = ? AND agency_id = ?',
                [$employeeID, $agencyID]
            );

            // Check if user is still employee at other agencies
            $otherAgencies = $this->db->fetchAssociative(
                'SELECT COUNT(*) as count FROM agencies_employees WHERE user_id = ?',
                [$employeeID]
            );

            // If not employee at any other agency and role is employee, change back to client
            if ((int)$otherAgencies['count'] === 0) {
                $currentRole = $this->getUserRole($employeeID);
                if ($currentRole === 'employee') {
                    $this->db->executeStatement(
                        'UPDATE users SET role = ? WHERE id = ?',
                        ['client', $employeeID]
                    );
                }
            }

            $this->db->commit();

            $message = 'Employee removed from agency successfully.';
            if ((int)$otherAgencies['count'] === 0) {
                $message .= ' User role changed back to client.';
            }

            ApiResponse::success([
                'message' => $message,
                'agency_id' => $agencyID,
                'user_id' => $employeeID
            ], status: 200);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to remove employee: ' . $e->getMessage(), 500);
        }
    }

    public function deleteAll(int $agencyID): void
    {
        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        try {
            $this->db->beginTransaction();

            // Get all employees before deletion
            $employees = $this->db->fetchAllAssociative(
                'SELECT user_id FROM agencies_employees WHERE agency_id = ?',
                [$agencyID]
            );

            // Delete all employees from this agency
            $deleted = $this->db->executeStatement(
                'DELETE FROM agencies_employees WHERE agency_id = ?',
                [$agencyID]
            );

            // Update roles for users who are no longer employees at any agency
            foreach ($employees as $emp) {
                $userID = (int)$emp['user_id'];
                
                $otherAgencies = $this->db->fetchAssociative(
                    'SELECT COUNT(*) as count FROM agencies_employees WHERE user_id = ?',
                    [$userID]
                );

                if ((int)$otherAgencies['count'] === 0) {
                    $currentRole = $this->getUserRole($userID);
                    if ($currentRole === 'employee') {
                        $this->db->executeStatement(
                            'UPDATE users SET role = ? WHERE id = ?',
                            ['client', $userID]
                        );
                    }
                }
            }

            $this->db->commit();

            ApiResponse::success([
                'message' => "Removed $deleted employee(s) from agency. Roles updated where applicable.",
                'agency_id' => $agencyID
            ], status: 200);
        } catch (\Throwable $e) {
            $this->db->rollBack();
            ApiResponse::error('Failed to remove employees: ' . $e->getMessage(), 500);
        }
    }

    // ADDITIONAL UTILITY ENDPOINTS

    public function getAgenciesByEmployee(int $employeeID): void
    {
        if (!$this->userExists($employeeID)) {
            ApiResponse::error('User not found.', 404);
            return;
        }

        $agencies = $this->db->fetchAllAssociative(
            'SELECT 
                a.id,
                a.name,
                a.email,
                a.phone,
                a.description,
                a.website,
                a.logo,
                a.address,
                a.city,
                a.postal
            FROM agencies a
            JOIN agencies_employees ae ON a.id = ae.agency_id
            WHERE ae.user_id = ?
            ORDER BY a.name ASC',
            [$employeeID]
        );

        ApiResponse::success([
            'user_id' => $employeeID,
            'agencies' => array_map(static fn(array $agency): array => [
                'id' => (int)$agency['id'],
                'name' => $agency['name'],
                'email' => $agency['email'],
                'phone' => $agency['phone'] ?? null,
                'description' => $agency['description'] ?? null,
                'website' => $agency['website'] ?? null,
                'logo_url' => $agency['logo'] ?? null,
                'address' => $agency['address'] ?? null,
                'city' => $agency['city'] ?? null,
                'postal' => $agency['postal'] ?? null,
            ], $agencies),
        ]);
    }

    public function getEmployeeCount(int $agencyID): void
    {
        if (!$this->agencyExists($agencyID)) {
            ApiResponse::error('Agency not found.', 404);
            return;
        }

        $result = $this->db->fetchAssociative(
            'SELECT COUNT(*) as employee_count FROM agencies_employees WHERE agency_id = ?',
            [$agencyID]
        );

        ApiResponse::success([
            'agency_id' => $agencyID,
            'employee_count' => (int)$result['employee_count']
        ]);
    }

    public function checkEmployeeInAgency(int $agencyID, int $employeeID): void
    {
        $exists = $this->isEmployeeOfAgency($agencyID, $employeeID);

        ApiResponse::success([
            'agency_id' => $agencyID,
            'user_id' => $employeeID,
            'is_employee' => $exists
        ]);
    }

    // PRIVATE HELPER METHODS

    private function getJsonInput(): ?array
    {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            ApiResponse::error('Invalid JSON payload.', 422);
            return null;
        }

        return $data;
    }

    private function validateEmployeeData(array $data): array
    {
        $errors = [];

        if (!isset($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors[] = 'Valid user_id is required.';
        }

        return $errors;
    }

    private function agencyExists(int $agencyID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM agencies WHERE id = ?',
            [$agencyID]
        );
        
        return $result !== false;
    }

    private function userExists(int $userID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT id FROM users WHERE id = ?',
            [$userID]
        );
        
        return $result !== false;
    }

    private function isEmployeeOfAgency(int $agencyID, int $userID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT * FROM agencies_employees WHERE agency_id = ? AND user_id = ?',
            [$agencyID, $userID]
        );
        
        return $result !== false;
    }

    private function getUserRole(int $userID): ?string
    {
        $result = $this->db->fetchAssociative(
            'SELECT role FROM users WHERE id = ?',
            [$userID]
        );
        
        return $result ? $result['role'] : null;
    }
}