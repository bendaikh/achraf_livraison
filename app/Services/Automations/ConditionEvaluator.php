<?php

namespace App\Services\Automations;

/** Evaluates AND/OR condition rule sets against a run context. */
class ConditionEvaluator
{
    public function __construct(protected VariableResolver $resolver) {}

    /**
     * @param  array{logic?: string, rules?: list<array>}  $condition
     * @param  array<string, mixed>  $context
     * @return array{matched: bool, results: list<array>}
     */
    public function evaluate(array $condition, array $context): array
    {
        $logic = strtolower((string) ($condition['logic'] ?? 'and'));
        $rules = array_values((array) ($condition['rules'] ?? []));
        if ($rules === []) {
            return ['matched' => true, 'results' => []];
        }

        $results = [];
        $matched = $logic === 'or' ? false : true;

        foreach ($rules as $rule) {
            $field = (string) ($rule['field'] ?? '');
            $op = (string) ($rule['op'] ?? 'eq');
            $expected = $rule['value'] ?? null;
            $actual = $this->resolver->fieldValue($field, $context);
            $ok = $this->compare($actual, $op, $expected);
            $results[] = [
                'field' => $field,
                'op' => $op,
                'expected' => $expected,
                'actual' => $actual,
                'matched' => $ok,
            ];
            if ($logic === 'or') {
                $matched = $matched || $ok;
            } else {
                $matched = $matched && $ok;
            }
        }

        return ['matched' => $matched, 'results' => $results];
    }

    protected function compare(mixed $actual, string $op, mixed $expected): bool
    {
        return match ($op) {
            'eq' => $this->normalize($actual) == $this->normalize($expected),
            'neq' => $this->normalize($actual) != $this->normalize($expected),
            'contains' => str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'not_contains' => ! str_contains(mb_strtolower((string) $actual), mb_strtolower((string) $expected)),
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'gte' => is_numeric($actual) && is_numeric($expected) && (float) $actual >= (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            'lte' => is_numeric($actual) && is_numeric($expected) && (float) $actual <= (float) $expected,
            'empty' => blank($actual),
            'not_empty' => filled($actual),
            'in' => in_array($this->normalize($actual), $this->asList($expected), true),
            'not_in' => ! in_array($this->normalize($actual), $this->asList($expected), true),
            default => false,
        };
    }

    protected function normalize(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strtolower(trim($value));
        }

        return $value;
    }

    /** @return list<mixed> */
    protected function asList(mixed $expected): array
    {
        if (is_array($expected)) {
            return array_map(fn ($v) => $this->normalize($v), $expected);
        }
        if (is_string($expected) && str_contains($expected, ',')) {
            return array_map(fn ($v) => $this->normalize(trim($v)), explode(',', $expected));
        }

        return [$this->normalize($expected)];
    }
}
