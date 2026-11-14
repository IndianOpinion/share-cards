<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards;

/**
 * Shared renderer: one composer, one canvas per render. Subclasses only say
 * which canvas to use.
 */
abstract class CanvasRenderer implements Renderer
{
    /** @var list<array{field: string, reason: string, scripts: string}> */
    private array $omitted = [];

    private string $backdropNote = '';

    public function __construct(
        private SourceResolver $sources,
        private ?PangoTextLayer $pango = null
    ) {
    }

    abstract protected function base(): string;

    abstract protected function createCanvas(): Canvas;

    public function name(): string
    {
        return $this->base() . ($this->pango !== null ? '+pango' : '');
    }

    public function canShape(): bool
    {
        return $this->pango !== null;
    }

    public function render(Subject $s, Template $t, string $dest): void
    {
        if (!$this->isAvailable()) {
            throw new RenderException($this->base() . ' renderer is not available.');
        }
        $composer = new CardComposer($this->createCanvas(), $this->pango, $this->sources);
        try {
            $data = $composer->compose($s, $t);
        } finally {
            $this->omitted = $composer->omitted();
            $this->backdropNote = $composer->backdropNote();
        }
        if (file_put_contents($dest, $data) !== strlen($data)) {
            throw new RenderException('Could not write the card file.');
        }
    }

    /**
     * Fields left off the last card.
     *
     * @return list<array{field: string, reason: string, scripts: string}>
     */
    public function lastOmitted(): array
    {
        return $this->omitted;
    }

    public function lastBackdropNote(): string
    {
        return $this->backdropNote;
    }
}
