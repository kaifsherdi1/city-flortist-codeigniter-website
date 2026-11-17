<?php

namespace App\Controllers\Admin;

use App\Controllers\ApiController;
use App\Models\ReviewModel;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Dashboard → Reviews: approve, hide or delete what customers wrote. Same answers as the Laravel version's
 * Admin\ReviewController.
 */
class Reviews extends ApiController
{
    /** GET /api/admin/reviews?status= */
    public function index(): ResponseInterface
    {
        $status = (string) $this->query('status', '');
        $q      = db_connect()->table('reviews');
        if (isset(ReviewModel::STATUSES[$status])) {
            $q->where('status', $status);
        }
        // Waiting reviews first, then newest first; at most 200.
        $rows = $q->orderBy("CASE status WHEN 'pending' THEN 0 ELSE 1 END", '', false)
            ->orderBy('id', 'DESC')
            ->limit(200)
            ->get()->getResultArray();

        return $this->json([
            'reviews' => array_map(
                static fn (array $r): array => ReviewModel::toPublic($r) + ['full_name' => $r['name'], 'phone' => $r['phone'], 'status' => $r['status']],
                $rows,
            ),
            'statuses' => ReviewModel::STATUSES,
            'pending'  => $this->pending(),
            'summary'  => (new ReviewModel())->summary(),
        ]);
    }

    /** PATCH /api/admin/reviews/{review} {status} */
    public function update(int|string $id): ResponseInterface
    {
        $review = $this->review((int) $id);
        $status = (string) $this->input('status');
        if (! isset(ReviewModel::STATUSES[$status])) {
            $this->fail('Unknown status.');
        }
        (new ReviewModel())->builder()->where('id', $review['id'])->update(['status' => $status]);

        return $this->json([
            'message' => $status === 'approved' ? 'Review is now on the website.' : 'Review hidden.',
            'pending' => $this->pending(),
        ]);
    }

    /** DELETE /api/admin/reviews/{review} */
    public function destroy(int|string $id): ResponseInterface
    {
        $review = $this->review((int) $id);
        (new ReviewModel())->builder()->where('id', $review['id'])->delete();

        return $this->json(['message' => 'Review deleted.', 'pending' => $this->pending()]);
    }

    /** The review for {review} in the URL (Laravel's route-model binding: 404 "Not found." when it doesn't exist). */
    private function review(int $id): array
    {
        return (new ReviewModel())->find($id) ?? $this->missing();
    }

    private function pending(): int
    {
        return db_connect()->table('reviews')->where('status', 'pending')->countAllResults();
    }
}
