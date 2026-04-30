<?php

namespace Calibre;

class Book
{
    private string $title;
    private string $author;
    private string $path;
    private array $formats;
    private array $metadata;
    private ?string $coverPath;

    public function __construct(
        string $title,
        string $author,
        string $path,
        array $formats = [],
        array $metadata = [],
        ?string $coverPath = null
    ) {
        $this->title = $title;
        $this->author = $author;
        $this->path = $path;
        $this->formats = $formats;
        $this->metadata = $metadata;
        $this->coverPath = $coverPath;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getAuthor(): string
    {
        return $this->author;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getFormats(): array
    {
        return $this->formats;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getCoverPath(): ?string
    {
        return $this->coverPath;
    }

    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'author' => $this->author,
            'path' => $this->path,
            'formats' => $this->formats,
            'metadata' => $this->metadata,
            'cover_path' => $this->coverPath,
        ];
    }
}
