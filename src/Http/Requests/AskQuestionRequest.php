<?php

namespace Omniglies\LaravelRag\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AskQuestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'question' => 'required|string|min:3|max:1000',
            'stream' => 'sometimes|boolean',
            'max_results' => 'sometimes|integer|min:1|max:20',
            'context_limit' => 'sometimes|integer|min:1|max:10',
            'include_sources' => 'sometimes|boolean',
            'temperature' => 'sometimes|numeric|min:0|max:2',
            'model' => 'sometimes|string|max:100',
        ];
    }

    public function messages(): array
    {
        return [
            'question.required' => 'A question is required.',
            'question.min' => 'The question must be at least 3 characters long.',
            'question.max' => 'The question cannot exceed 1000 characters.',
            'max_results.integer' => 'Max results must be a number.',
            'max_results.min' => 'Max results must be at least 1.',
            'max_results.max' => 'Max results cannot exceed 20.',
            'context_limit.integer' => 'Context limit must be a number.',
            'context_limit.min' => 'Context limit must be at least 1.',
            'context_limit.max' => 'Context limit cannot exceed 10.',
            'temperature.numeric' => 'Temperature must be a number.',
            'temperature.min' => 'Temperature must be at least 0.',
            'temperature.max' => 'Temperature cannot exceed 2.',
        ];
    }
}