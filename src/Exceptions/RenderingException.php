<?php

namespace ricwein\Templater\Exceptions;

use ricwein\FileSystem\File;
use Throwable;

class RenderingException extends TemplatingException
{
    private ?File $template;
    private int $templateLine;

    public function __construct(string $message, int $code, ?Throwable $previous, ?File $template, int $line)
    {
        parent::__construct($message, $code, $previous);
        $this->template = $template;
        $this->templateLine = $line;
    }

    public function setTemplateFile(File $file): self
    {
        $this->template = $file;
        return $this;
    }

    public function getTemplateFile(): ?File
    {
        return $this->template;
    }

    public function getTemplateLine(): int
    {
        return $this->templateLine;
    }
}
