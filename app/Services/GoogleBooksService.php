<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleBooksService
{
    /**
     * Google Books API base URL
     */
    private const API_URL = 'https://www.googleapis.com/books/v1/volumes';

    /**
     * Lookup book information by ISBN
     *
     * @param string $isbn The ISBN-10 or ISBN-13
     * @return array|null Book data or null if not found
     */
    public function lookupByIsbn(string $isbn): ?array
    {
        try {
            // Clean ISBN (remove dashes, spaces)
            $cleanIsbn = preg_replace('/[^0-9X]/i', '', $isbn);

            // Query Google Books API
            $response = Http::timeout(10)->get(self::API_URL, [
                'q' => 'isbn:' . $cleanIsbn,
                'maxResults' => 1
            ]);

            if (!$response->successful()) {
                Log::warning('Google Books API request failed', [
                    'isbn' => $isbn,
                    'status' => $response->status()
                ]);
                return null;
            }

            $data = $response->json();

            // Check if we have results
            if (!isset($data['totalItems']) || $data['totalItems'] === 0) {
                return null;
            }

            // Get the first volume
            $volume = $data['items'][0]['volumeInfo'] ?? null;

            if (!$volume) {
                return null;
            }

            // Extract and format the book data
            return [
                'title' => $volume['title'] ?? null,
                'subtitle' => $volume['subtitle'] ?? null,
                'authors' => $volume['authors'] ?? [],
                'author' => isset($volume['authors']) ? implode(', ', $volume['authors']) : null,
                'publisher' => $volume['publisher'] ?? null,
                'published_date' => $volume['publishedDate'] ?? null,
                'published_year' => isset($volume['publishedDate']) ? (int) substr($volume['publishedDate'], 0, 4) : null,
                'description' => $volume['description'] ?? null,
                'page_count' => $volume['pageCount'] ?? null,
                'categories' => $volume['categories'] ?? [],
                'category' => isset($volume['categories']) ? $volume['categories'][0] : null,
                'language' => $this->mapLanguageCode($volume['language'] ?? 'en'),
                'thumbnail' => $this->getHighResImage($volume['imageLinks'] ?? []),
                'isbn_10' => $this->extractIsbn($volume['industryIdentifiers'] ?? [], 'ISBN_10'),
                'isbn_13' => $this->extractIsbn($volume['industryIdentifiers'] ?? [], 'ISBN_13'),
                'found' => true
            ];
        } catch (\Exception $e) {
            Log::error('Google Books API error', [
                'isbn' => $isbn,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Map ISO language code to full language name
     */
    private function mapLanguageCode(string $code): string
    {
        $languages = [
            'en' => 'English',
            'fil' => 'Filipino',
            'tl' => 'Filipino',
            'es' => 'Spanish',
            'ja' => 'Japanese',
            'zh' => 'Chinese',
            'ko' => 'Korean',
            'fr' => 'French',
            'de' => 'German',
        ];

        return $languages[$code] ?? 'Other';
    }

    /**
     * Get the highest resolution image available
     */
    private function getHighResImage(array $imageLinks): ?string
    {
        // Priority order: extraLarge, large, medium, small, thumbnail, smallThumbnail
        $priorities = ['extraLarge', 'large', 'medium', 'small', 'thumbnail', 'smallThumbnail'];

        foreach ($priorities as $size) {
            if (isset($imageLinks[$size])) {
                // Convert HTTP to HTTPS if needed
                return str_replace('http://', 'https://', $imageLinks[$size]);
            }
        }

        return null;
    }

    /**
     * Extract specific ISBN from industry identifiers
     */
    private function extractIsbn(array $identifiers, string $type): ?string
    {
        foreach ($identifiers as $identifier) {
            if (isset($identifier['type']) && $identifier['type'] === $type) {
                return $identifier['identifier'] ?? null;
            }
        }
        return null;
    }
}
