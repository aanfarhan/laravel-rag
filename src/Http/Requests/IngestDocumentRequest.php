<?php

namespace Omniglies\LaravelRag\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class IngestDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowedTypes = config('rag.file_upload.allowed_types', []);
        $maxSize = config('rag.file_upload.max_size', 10240);

        return [
            'title' => 'required|string|max:255',
            'file' => [
                'required_without:content',
                'file',
                'max:' . $maxSize,
                'mimes:' . implode(',', $allowedTypes),
            ],
            'content' => 'required_without:file|string',
            'metadata' => 'sometimes|array',
            'metadata.*' => 'string',
        ];
    }

    public function messages(): array
    {
        $allowedTypes = implode(', ', config('rag.file_upload.allowed_types', []));
        $maxSizeMB = config('rag.file_upload.max_size', 10240) / 1024;

        return [
            'title.required' => 'A document title is required.',
            'title.max' => 'The document title cannot exceed 255 characters.',
            'file.required_without' => 'Either a file or content is required.',
            'file.max' => "The file size cannot exceed {$maxSizeMB}MB.",
            'file.mimes' => "The file must be one of the following types: {$allowedTypes}.",
            'content.required_without' => 'Either content or a file is required.',
            'metadata.array' => 'Metadata must be an array.',
        ];
    }
}