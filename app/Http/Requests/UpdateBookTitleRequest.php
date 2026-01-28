<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBookTitleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true; // Authorized for now, can restrict to admin later
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'category' => 'required|string|max:100',
            'isbn' => 'nullable|string|max:50',
            'accession_no' => 'nullable|string|max:100',
            'lccn' => 'nullable|string|max:50',
            'issn' => 'nullable|string|max:50',
            'publisher' => 'nullable|string|max:255',
            'place_of_publication' => 'nullable|string|max:255',
            'published_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'copyright_year' => 'nullable|integer|min:1800|max:' . (date('Y') + 1),
            'call_number' => 'nullable|string|max:100',
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
            'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120'
        ];
    }
}
