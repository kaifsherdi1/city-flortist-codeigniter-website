<?php

namespace App\Models;

use CodeIgniter\Model;

/** A customer review from the website. Shown publicly only once the owner approves it. */
class ReviewModel extends Model
{
    public const STATUSES = ['pending' => 'Waiting', 'approved' => 'Shown on website', 'hidden' => 'Hidden'];

    protected $table         = 'reviews';
    protected $primaryKey    = 'id';
    protected $returnType    = 'array';
    protected $allowedFields = ['name', 'rating', 'text', 'phone', 'verified', 'status'];
    protected $useTimestamps = true;
    protected $createdField  = 'created_at';
    protected $updatedField  = '';
    protected array $casts   = ['id' => 'int', 'rating' => 'int', 'verified' => 'bool'];

    /** What the public website gets: no phone number, first name and initial only. */
    public static function toPublic(array $review): array
    {
        return [
            'id'       => (int) $review['id'],
            'name'     => self::shortName((string) $review['name']),
            'rating'   => (int) $review['rating'],
            'text'     => $review['text'],
            'verified' => (bool) $review['verified'],
            'date'     => substr((string) $review['created_at'], 0, 10),
        ];
    }

    /** "Priya Patil" → "Priya P." so customers aren't fully named on the internet. */
    public static function shortName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';

        return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
    }

    /** Average and count of approved reviews, for the website. */
    public function summary(): array
    {
        $row = $this->builder()->select('COUNT(*) AS n, AVG(rating) AS average', false)->where('status', 'approved')->get()->getRowArray();

        return ['count' => (int) ($row['n'] ?? 0), 'average' => round((float) ($row['average'] ?? 0), 1)];
    }
}
