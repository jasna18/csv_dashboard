<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ImportCsvRequest extends FormRequest
{
    /**
     * The only extensions an upload may be stored under.
     *
     * The controller re-checks against this list rather than trusting the name
     * on the way to disk — see the note there about content-sniffed extensions.
     */
    public const ALLOWED_EXTENSIONS = ['csv', 'txt', 'xlsx', 'xls'];

    public function authorize(): bool
    {
        // Deliberately open: this API has no authentication yet (TASK.md §3
        // lists it as a v1 non-goal). Anyone who can reach the endpoint can
        // import, and with `truncate` replace the whole table. Add a real check
        // here the moment auth exists.
        return true;
    }

    public function rules(): array
    {
        return [
            // 32768 KB = 32 MB, matching the frontend's client-side check and
            // the upload_max_filesize / post_max_size values in php.ini.
            'file' => ['required', 'file', 'max:32768', 'extensions:' . implode(',', self::ALLOWED_EXTENSIONS)],
            // Clears add_csv before importing. Off by default — re-running an
            // import without it appends, it does not replace.
            'truncate' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'No file was uploaded.',
            'file.max' => 'The file is larger than 32 MB.',
            'file.extensions' => 'The file must be a CSV or Excel file (.csv, .xlsx, .xls, .txt).',
        ];
    }

    protected function prepareForValidation(): void
    {
        // Browsers send "true"/"1" as strings in multipart form data.
        if ($this->has('truncate')) {
            $this->merge([
                'truncate' => filter_var($this->input('truncate'), FILTER_VALIDATE_BOOLEAN),
            ]);
        }
    }
}
