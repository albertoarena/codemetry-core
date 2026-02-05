<?php

declare(strict_types=1);

namespace Codemetry\Core\Domain;

final readonly class CommitInfo implements \JsonSerializable
{
    /**
     * @param array<string> $files
     */
    public function __construct(
        public string $hash,
        public string $authorName,
        public string $authorEmail,
        public \DateTimeImmutable $authoredAt,
        public string $subject,
        public int $insertions = 0,
        public int $deletions = 0,
        public array $files = [],
    ) {}

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return [
            'hash' => $this->hash,
            'author_name' => $this->authorName,
            'author_email' => $this->authorEmail,
            'authored_at' => $this->authoredAt->format('c'),
            'subject' => $this->subject,
            'insertions' => $this->insertions,
            'deletions' => $this->deletions,
            'files' => $this->files,
        ];
    }
}
