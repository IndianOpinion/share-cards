<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * What one card shows. Immutable; the `with*` methods return copies.
 */
final class Subject
{
    /**
     * @param string $type     Key type: `post`, `term`, `site` or a registered custom type.
     * @param string $id       Key id, already valid for the type.
     * @param string $headline Raw headline; normalised by TextLayout before use.
     * @param string $kicker   Raw kicker.
     * @param string $footnote Raw footnote.
     * @param int    $backdropId Attachment ID of the backdrop, 0 for none.
     * @param string $alt      Alt text; empty means "use the headline, else the site name".
     * @param bool   $isPublic Whether anyone may see this subject.
     */
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly string $headline = '',
        public readonly string $kicker = '',
        public readonly string $footnote = '',
        public readonly int $backdropId = 0,
        public readonly string $alt = '',
        public readonly bool $isPublic = true
    ) {
    }

    public function withHeadline(string $headline): self
    {
        return new self($this->type, $this->id, $headline, $this->kicker, $this->footnote, $this->backdropId, $this->alt, $this->isPublic);
    }

    public function withKicker(string $kicker): self
    {
        return new self($this->type, $this->id, $this->headline, $kicker, $this->footnote, $this->backdropId, $this->alt, $this->isPublic);
    }

    public function withFootnote(string $footnote): self
    {
        return new self($this->type, $this->id, $this->headline, $this->kicker, $footnote, $this->backdropId, $this->alt, $this->isPublic);
    }

    public function withBackdropId(int $backdropId): self
    {
        return new self($this->type, $this->id, $this->headline, $this->kicker, $this->footnote, max(0, $backdropId), $this->alt, $this->isPublic);
    }

    public function withAlt(string $alt): self
    {
        return new self($this->type, $this->id, $this->headline, $this->kicker, $this->footnote, $this->backdropId, $alt, $this->isPublic);
    }

    public function withPublic(bool $isPublic): self
    {
        return new self($this->type, $this->id, $this->headline, $this->kicker, $this->footnote, $this->backdropId, $this->alt, $isPublic);
    }
}
