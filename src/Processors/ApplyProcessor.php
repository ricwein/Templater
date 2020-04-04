<?php


namespace ricwein\Templater\Processors;


use ricwein\Templater\Engine\BaseFunction;
use ricwein\Templater\Engine\Context;
use ricwein\Templater\Exceptions\RenderingException;
use ricwein\Templater\Exceptions\RuntimeException;
use ricwein\Templater\Exceptions\UnexpectedValueException;
use ricwein\Templater\Processors\Symbols\BlockSymbols;

class ApplyProcessor extends Processor
{

    public static function startKeyword(): string
    {
        return 'apply';
    }

    protected static function endKeyword(): ?string
    {
        return 'endapply';
    }

    private static function getFunction(string $name, Context $context): ?BaseFunction
    {
        if (isset($context->functions[$name])) {
            return $context->functions[$name];
        }

        foreach ($context->functions as $function) {
            if ($function->getName() === $name) {
                return $function;
            }
        }

        return null;
    }

    /**
     * @inheritDoc
     * @param Context $context
     * @return array
     * @throws RenderingException
     * @throws RuntimeException
     * @throws UnexpectedValueException
     */
    public function process(Context $context): array
    {
        if (!$this->symbols instanceof BlockSymbols) {
            throw new RuntimeException(sprintf("Unsupported Processor-Symbols of type: %s", substr(strrchr(get_class($this->symbols), "\\"), 1)), 500);
        }

        $headTokens = $this->symbols->headTokens();
        $functionName = implode('', $headTokens);

        if (null === $function = static::getFunction(trim($functionName), $context)) {
            throw new RenderingException("Call to unknown function: {$functionName}()", 400, null, $context->template(), reset($headTokens)->line());
        }

        $lines = $this->templater->resolveSymbols($this->symbols->content, $context);
        $content = implode('', $lines);

        return [
            $function->call([$content]),
        ];
    }
}
