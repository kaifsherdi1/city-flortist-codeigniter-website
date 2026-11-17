<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Libraries\Text;
use App\Models\AdminModel;
use CodeIgniter\HTTP\ResponseInterface;

/** Settings → Team (owner only): approve staff sign-ups, switch accounts off and on, remove them. */
class Team extends ApiController
{
    /** GET /api/admin/team */
    public function index(): ResponseInterface
    {
        $me   = service('adminAuth')->id();
        $rows = (new AdminModel())
            ->orderBy("CASE status WHEN 'pending' THEN 0 WHEN 'active' THEN 1 ELSE 2 END", '', false)
            ->orderBy('id')
            ->findAll();

        return $this->json([
            'team' => array_map(static fn (array $a): array => AdminModel::toPublic($a) + [
                'created_at' => Text::isoDate($a['created_at'] ?? null),
                'me'         => (int) $a['id'] === $me,
            ], $rows),
            'statuses' => AdminModel::STATUSES,
        ]);
    }

    /** PATCH /api/admin/team/{id} {status: active|disabled} */
    public function update(string $id): ResponseInterface
    {
        $admin = $this->member((int) $id);
        $this->guardOther($admin);
        $status = self::text($this->input('status'));
        if (! in_array($status, ['active', 'disabled'], true)) {
            $this->fail('Unknown status.');
        }
        (new AdminModel())->builder()->where('id', (int) $admin['id'])->update(['status' => $status]);
        if ($status === 'disabled') {
            $this->endSessions($admin);
        }

        return $this->json([
            'message' => $status === 'active' ? "{$admin['username']} can now use the dashboard." : "{$admin['username']} has been switched off.",
            'pending' => self::pending(),
        ]);
    }

    /** DELETE /api/admin/team/{id} */
    public function destroy(string $id): ResponseInterface
    {
        $admin = $this->member((int) $id);
        $this->guardOther($admin);
        $this->endSessions($admin);
        (new AdminModel())->builder()->where('id', (int) $admin['id'])->delete();

        return $this->json(['message' => "{$admin['username']} has been removed.", 'pending' => self::pending()]);
    }

    // ------------------------------------------------------------------------------------------------------

    /** The account in the URL (Laravel's route-model binding: a missing one is a 404). */
    private function member(int $id): array
    {
        $admin = (new AdminModel())->find($id);
        if (! is_array($admin)) {
            $this->missing();
        }

        return $admin;
    }

    /** The owner can't lock themselves (or another owner) out. */
    private function guardOther(array $admin): void
    {
        if ((int) $admin['id'] === service('adminAuth')->id() || AdminModel::isOwner($admin)) {
            $this->fail('The owner account can’t be changed here.', 403);
        }
    }

    /**
     * A new remember token ends "remember me". Sessions are files, so they can't be listed per account: the admin
     * filter logs a switched-off (or removed) account out on its next request instead.
     */
    private function endSessions(array $admin): void
    {
        service('adminAuth')->cycleRememberToken((int) $admin['id']);
    }

    private static function pending(): int
    {
        return (new AdminModel())->builder()->where('status', 'pending')->countAllResults();
    }

    /** A request field read as text, like Laravel's (string) $request->input(...); arrays count as empty. */
    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
