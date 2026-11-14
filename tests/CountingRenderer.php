<?php

declare(strict_types=1);

namespace IndianOpinion\ShareCards\Tests;

use IndianOpinion\ShareCards\RenderException;
use IndianOpinion\ShareCards\Renderer;
use IndianOpinion\ShareCards\Subject;
use IndianOpinion\ShareCards\Template;

/**
 * Wraps a real renderer (or writes a tiny JPEG), counts calls, and can throw.
 */
final class CountingRenderer implements Renderer
{
    public int $calls = 0;

    /** @var (callable(): void)|null */
    public $during = null;

    public function __construct(
        private ?Renderer $inner = null,
        private bool $throw = false,
        private string $counterFile = ''
    ) {
    }

    public function name(): string
    {
        return $this->inner?->name() ?? 'gd';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function canShape(): bool
    {
        return $this->inner?->canShape() ?? false;
    }

    public function render(Subject $s, Template $t, string $dest): void
    {
        $this->calls++;
        if ($this->counterFile !== '') {
            file_put_contents($this->counterFile, "1\n", FILE_APPEND | LOCK_EX);
        }
        if ($this->during !== null) {
            ($this->during)();
        }
        if ($this->throw) {
            throw new RenderException('Boom.');
        }
        if ($this->inner !== null) {
            $this->inner->render($s, $t, $dest);
            return;
        }
        $im = imagecreatetruecolor(Template::WIDTH, Template::HEIGHT);
        ob_start();
        imagejpeg($im, null, 80);
        file_put_contents($dest, (string) ob_get_clean());
        imagedestroy($im);
    }
}
