<?php

namespace App\Services\Web;

use Exception;

class WebExtractionException extends Exception
{
    public static function invalidUrl(string $url): self
    {
        return new self("Invalid URL provided: [{$url}]. Only http and https URLs are supported.");
    }

    public static function blockedUrl(string $url, string $reason = 'URL is not allowed.'): self
    {
        return new self("Blocked URL [{$url}]: {$reason}");
    }

    public static function requestFailed(string $url, string $error): self
    {
        return new self("Failed to fetch URL [{$url}]: {$error}");
    }

    public static function timeout(string $url): self
    {
        return new self("Timed out while fetching URL [{$url}]. The remote server took too long to respond.");
    }

    public static function emptyContent(string $url): self
    {
        return new self("No readable job content found at URL [{$url}]. The page may be empty, require JavaScript, or contain no text.");
    }

    public static function failed(string $message): self
    {
        return new self("Web extraction failed: {$message}");
    }
}
