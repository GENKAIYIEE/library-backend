<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBookTitleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     * FormData sends everything as strings. Convert empty strings to null
     * so that 'nullable' rules work correctly for optional fields.
     */
    protected function prepareForValidation()
    {
        $fieldsToNullify = [
            'subtitle',
            'isbn',
            'accession_no',
            'lccn',
            'issn',
            'publisher',
            'place_of_publication',
            'published_year',
            'copyright_year',
            'call_number',
            'pages',
            'physical_description',
            'edition',
            'series',
            'volume',
            'price',
            'book_penalty',
            'language',
            'description',
            'location',
        ];

        $updates = [];
        foreach ($fieldsToNullify as $field) {
            if ($this->has($field) && $this->input($field) === '') {
                $updates[$field] = null;
            }
        }

        if (!empty($updates)) {
            $this->merge($updates);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $bookId = $this->route('id');

        return [
            'title' => [
                'required',
                'string',
                'max:255',
                Rule::unique('book_titles', 'title')
                    ->ignore($bookId)
                    ->whereNull('deleted_at'),
            ],
            'subtitle' => 'nullable|string|max:255',
            'author' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'isbn' => 'nullable|string|max:50',
            'accession_no' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('book_titles', 'accession_no')
                    ->ignore($bookId)
                    ->whereNull('deleted_at'),
            ],
            'lccn' => 'nullable|string|max:50',
            'issn' => 'nullable|string|max:50',
            'publisher' => 'nullable|string|max:255',
            'place_of_publication' => 'nullable|string|max:255',
            'published_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'copyright_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'call_number' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('book_titles', 'call_number')
                    ->ignore($bookId)
                    ->whereNull('deleted_at'),
            ],
            'pages' => 'nullable|integer|min:1',
            'physical_description' => 'nullable|string|max:255',
            'edition' => 'nullable|string|max:100',
            'series' => 'nullable|string|max:255',
            'volume' => 'nullable|string|max:50',
            'price' => 'nullable|numeric|min:0',
            'book_penalty' => 'nullable|numeric|min:0',
            'language' => 'nullable|string|max:50',
            'description' => 'nullable|string',
            'location' => 'nullable|string|max:255',
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'added_copies' => 'nullable|integer|min:1',
            'is_damaged_copies' => 'nullable|boolean',
        ];
    }

    /**
     * Custom error messages for better UX.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'title.unique' => 'A book with this title already exists.',
            'accession_no.unique' => 'This accession number already exists, try a new one.',
        ];
    }
}
