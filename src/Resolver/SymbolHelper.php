<?php


namespace ricwein\Templater\Resolver;

use ricwein\Tokenizer\Result\ResultBlock;
use ricwein\Tokenizer\Result\ResultSymbolBase;

class SymbolHelper
{
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

        return $block->delimiter() === null && $block->prefix() !== null;
    }

    public static function isChainedUserFunctionCall(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        return $block->delimiter() !== null && $block->delimiter()->is('|') && $block->prefix() !== null;
    }

    public static function isMethodCall(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('()')) {
            return false;
        }

        return $block->delimiter() !== null && $block->delimiter()->is('.') && $block->prefix() !== null;
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

    public static function isArrayAccess(ResultSymbolBase $block): bool
    {
        if (!$block instanceof ResultBlock) {
            return false;
        }

        if (!$block->isBlock('[]')) {
            return false;
        }

        return $block->prefix() !== null;
    }
}
