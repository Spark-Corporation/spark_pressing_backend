<?php

namespace App\Actions\Staff;

use App\Models\Agency;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class PersistStaffUser
{
    public function create(array $data): User
    {
        $this->assertAgency($data['agency_id'], $data['pressing_id']);

        $user = User::query()->create([
            'name' => $data['fullname'],
            'fullname' => $data['fullname'],
            'email' => $data['email'],
            'password' => $data['password'],
            'phone_number' => $data['phone_number'] ?? null,
            'pressing_id' => $data['pressing_id'],
            'agency_id' => $data['agency_id'],
            'status' => true,
        ]);

        $user->assignRole($data['role']);
        $user->agencies()->syncWithoutDetaching([$data['agency_id'] => ['is_home' => true]]);

        return $user->load('roles');
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['agency_id'], $data['pressing_id'])) {
            $this->assertAgency($data['agency_id'], $data['pressing_id']);
        }

        if (isset($data['fullname'])) {
            $data['name'] = $data['fullname'];
        }

        if (array_key_exists('password', $data) && blank($data['password'])) {
            unset($data['password']);
        }

        $role = $data['role'] ?? null;
        unset($data['role']);

        $user->update($data);

        if ($role) {
            $user->syncRoles([$role]);
        }

        if (! empty($data['agency_id'])) {
            $user->agencies()->syncWithoutDetaching([$data['agency_id'] => ['is_home' => true]]);
        }

        return $user->fresh('roles');
    }

    private function assertAgency(int $agencyId, int $pressingId): void
    {
        $exists = Agency::query()->where('id', $agencyId)->where('pressing_id', $pressingId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'agency_id' => 'Cette agence n\'appartient pas au pressing.',
            ]);
        }
    }
}
