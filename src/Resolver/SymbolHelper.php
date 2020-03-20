<?php


namespace ricwein\Templater\Resolver;

use ricwein\Templater\Resolver\Symbol\Symbol;
use ricwein\Tokenizer\Result\BlockToken;
use ricwein\Tokenizer\Result\Token;
use ricwein\Tokenizer\Result\BaseToken;

class SymbolHelper
{
    public static function isFloat(array $symbols): bool
    {
        if (count($symbols) !== 2) {
            return false;
        }

        $lhs = reset($symbols);
        $rhs = end($symbols);

        if (!$lhs instanceof Token || !$rhs instanceof Token) {
            return false;
        }

        if (!is_numeric($lhs->token()) || strlen($lhs->token()) !== strlen((string)(int)$lhs->token())) {
            return false;
        }

        if (!is_numeric($rhs->token()) || strlen($rhs->token()) !== strlen((string)(int)$rhs->token())) {
            return false;
        }

        if ($rhs->delimiter() === null || !$rhs->delimiter()->is('.')) {
            return false;
        }

        return true;
    }

    public static function isString(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
            return false;
        }

        return $block->isBlock('\'\'') || $block->isBlock('""');
    }

    public static function isDirectUserFunctionCall(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
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

    public static function isChainedUserFunctionCall(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
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

    public static function isMethodCall(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
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

    public static function isInlineArray(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
            return false;
        }

        if (!$block->isBlock('[]')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isInlineAssoc(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
            return false;
        }

        if (!$block->isBlock('{}')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isPriorityBrace(BaseToken $block): bool
    {
        if (!$block instanceof BlockToken) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        return $block->prefix() === null;
    }

    public static function isArrayAccess(BaseToken $block, Symbol $value = null): bool
    {
        if (!$block instanceof BlockToken) {
            return false;
        }

        if (!$block->isBlock('[]')) {
            return false;
        }

        if ($value === null || $value->is(Symbol::TYPE_NULL)) {
            return $block->prefix() !== null;
        } elseif ($value->is(Symbol::TYPE_ARRAY)) {
            return true;
        }

        return false;
    }
}
