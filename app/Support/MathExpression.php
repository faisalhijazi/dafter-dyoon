<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Safe evaluator for calculator input in amount fields, e.g. "150*3 + 20".
 * Supports + - * / and parentheses; accepts Arabic-Indic digits and "×", "÷".
 * No eval(): a small recursive-descent parser.
 */
class MathExpression
{
    private array $tokens = [];

    private int $pos = 0;

    public static function evaluate(string|int|float|null $input): ?float
    {
        if ($input === null || trim((string) $input) === '') {
            return null;
        }

        try {
            return (new self)->parse((string) $input);
        } catch (InvalidArgumentException|\DivisionByZeroError) {
            return null;
        }
    }

    private function parse(string $input): float
    {
        $input = strtr($input, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', '،' => '', ',' => '', '×' => '*', '÷' => '/', ' ' => '',
        ]);

        if (! preg_match('/^[0-9.+\-*\/()]+$/', $input)) {
            throw new InvalidArgumentException('Invalid characters');
        }

        preg_match_all('/\d+(?:\.\d+)?|\.\d+|[+\-*\/()]/', $input, $m);
        $this->tokens = $m[0];
        $this->pos = 0;

        $value = $this->expression();

        if ($this->pos !== count($this->tokens)) {
            throw new InvalidArgumentException('Unexpected token');
        }

        return round($value, 4);
    }

    private function expression(): float
    {
        $value = $this->term();

        while (in_array($this->peek(), ['+', '-'], true)) {
            $value = $this->next() === '+' ? $value + $this->term() : $value - $this->term();
        }

        return $value;
    }

    private function term(): float
    {
        $value = $this->factor();

        while (in_array($this->peek(), ['*', '/'], true)) {
            $op = $this->next();
            $rhs = $this->factor();
            $value = match ($op) {
                '*' => $value * $rhs,
                '/' => $rhs == 0 ? throw new InvalidArgumentException('Division by zero') : $value / $rhs,
            };
        }

        return $value;
    }

    private function factor(): float
    {
        $token = $this->next();

        if ($token === '-') {
            return -$this->factor();
        }

        if ($token === '(') {
            $value = $this->expression();

            if ($this->next() !== ')') {
                throw new InvalidArgumentException('Missing )');
            }

            return $value;
        }

        if ($token !== null && is_numeric($token)) {
            return (float) $token;
        }

        throw new InvalidArgumentException('Unexpected token');
    }

    private function peek(): ?string
    {
        return $this->tokens[$this->pos] ?? null;
    }

    private function next(): ?string
    {
        return $this->tokens[$this->pos++] ?? null;
    }
}
