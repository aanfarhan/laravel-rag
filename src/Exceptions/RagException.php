<?php

namespace Omniglies\LaravelRag\Exceptions;

use Exception;

class RagException extends Exception
{
    public static function documentNotFound(int $documentId): self
    {
        return new static("Document with ID {$documentId} not found.");
    }

    public static function documentProcessingFailed(string $reason): self
    {
        return new static("Document processing failed: {$reason}");
    }

    public static function chunkNotFound(int $chunkId): self
    {
        return new static("Chunk with ID {$chunkId} not found.");
    }

    public static function searchFailed(string $reason): self
    {
        return new static("Search operation failed: {$reason}");
    }

    public static function embeddingFailed(string $reason): self
    {
        return new static("Embedding generation failed: {$reason}");
    }

    public static function configurationMissing(string $key): self
    {
        return new static("Missing required configuration: {$key}");
    }

    public static function vectorDatabaseError(string $reason): self
    {
        return new static("Vector database error: {$reason}");
    }

    public static function invalidFileType(string $type): self
    {
        return new static("Invalid file type: {$type}. Allowed types: " . implode(', ', config('rag.file_upload.allowed_types', [])));
    }

    public static function fileTooLarge(int $size, int $maxSize): self
    {
        return new static("File size ({$size} KB) exceeds maximum allowed size ({$maxSize} KB).");
    }
}