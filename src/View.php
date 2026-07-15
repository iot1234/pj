<?php
declare(strict_types=1);

namespace Dormitory;

final class View
{
    public function __construct(private readonly string $templateRoot)
    {
    }

    /** @param array<string,mixed> $variables */
    public function render(string $template, array $variables = []): string
    {
        $contentTemplate = $this->resolve($template);
        $layout = $this->resolve('layout.php');
        extract($variables, EXTR_SKIP);
        ob_start();
        try {
            require $layout;
            return (string) ob_get_clean();
        } catch (\Throwable $error) {
            ob_end_clean();
            throw $error;
        }
    }

    private function resolve(string $template): string
    {
        $candidate = realpath($this->templateRoot . '/' . ltrim($template, '/'));
        $root = realpath($this->templateRoot);
        if ($candidate === false || $root === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Template not found: ' . $template);
        }
        return $candidate;
    }
}
