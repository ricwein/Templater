<?php


namespace ricwein\Templater\Resolver;

use ricwein\Templater\Resolver\Symbol\Symbol;
use ricwein\Tokenizer\Result\ResultBlock;
use ricwein\Tokenizer\Result\ResultSymbol;
use ricwein\Tokenizer\Result\ResultSymbolBase;

class SymbolHelper
{
    public static function isFloat(array $symbols): bool
    {
        if (count($symbols) !== 2) {
            return false;
        }

        $lhs = reset($symbols);
        $rhs = end($symbols);

        if (!$lhs instanceof ResultSymbol || !$rhs instanceof ResultSymbol) {
            return false;
        }

        if (!is_numeric($lhs->symbol()) || strlen($lhs->symbol()) !== strlen((string)(int)$lhs->symbol())) {
            return false;
        }

        if (!is_numeric($rhs->symbol()) || strlen($rhs->symbol()) !== strlen((string)(int)$rhs->symbol())) {
            return false;
        }

        if ($rhs->delimiter() === null || !$rhs->delimiter()->is('.')) {
            return false;
        }

        return true;
    }

    public static function isString(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        return $block->isBlock('\'\'') || $block->isBlock('""');
    }

    public static function isDirectUserFunctionCall(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        if ($block->delimiter() !== null) {
            return false;
        }

        return $block->prefix() !== null;
    }

    public static function isChainedUserFunctionCall(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        if ($block->delimiter() === null || $block->delimiter()->is('.')) {
            return false;
        }

        return $block->prefix() !== null;
    }

    public static function isMethodCall(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        if ($block->delimiter() === null || !$block->delimiter()->is('.')) {
            return false;
        }

        return $block->prefix() !== null;
    }

    public static function isInlineArray(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('[]')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isInlineAssoc(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('{}')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isPriorityBrace(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isArrayAccess(ResultSymbolBase $block, Symbol $value = null): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('[]')) {
            return false;
        }

        if ($value === null) {
            return $block->prefix() !== null;
        } elseif ($value->is(Symbol::TYPE_ARRAY)) {
            return true;
        }

        return false;
    }
}
