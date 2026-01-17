<?php

namespace Http;

use \Services\ApiResponse;

class EmployeesController extends BaseController
{
class EmployeesController extends BaseController
{

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

    // PRIVATE HELPER METHODS

    private function validateEmployeeData(array $data): array
    {
        $errors = [];

        if (!isset($data['user_id']) || (int)$data['user_id'] <= 0) {
            $errors[] = 'Valid user_id is required.';
        }

        return $errors;
    }

    private function getUserRole(int $userID): ?string
    {
        $result = $this->db->fetchOne('SELECT role FROM users WHERE id = ?', [$userID]);
        return $result ?: null;
    }

    private function isEmployeeOfAgency(int $agencyID, int $userID): bool
    {
        $result = $this->db->fetchAssociative(
            'SELECT * FROM agencies_employees WHERE agency_id = ? AND user_id = ?',
            [$agencyID, $userID]
        );
        
        return $result !== false;
    }

}