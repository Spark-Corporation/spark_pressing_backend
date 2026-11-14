<?php

namespace App\Support\Scribe\Strategies;

use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Attributes\BodyParam;
use Knuckles\Scribe\Extracting\MethodAstParser;
use Knuckles\Scribe\Extracting\Strategies\BodyParameters\GetFromInlineValidator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;

/**
 * Complète le body des POST/PUT/PATCH quand Scribe ignore la validation :
 * `$request->validate($this->rules())`, `validate([... + self::profileRules()])`,
 * ou des règles qui ne sont pas de simples chaînes (`Rule::in`, ternaires).
 */
class DocumentInlineRequestBody extends GetFromInlineValidator
{
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        $method = $endpointData->method;
        if (! $method instanceof \ReflectionMethod || ! $endpointData->controller) {
            return [];
        }

        $verb = strtoupper($endpointData->httpMethods[0] ?? 'GET');
        if (! in_array($verb, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return [];
        }

        if ($method->getAttributes(BodyParam::class) !== []) {
            return [];
        }

        $ast = MethodAstParser::getMethodAst($method);
        $arg = $this->findValidateArgument($ast);
        if (! $arg) {
            return [];
        }

        $rules = $this->rulesFromExpr($arg, $endpointData->controller);
        if ($rules === []) {
            return [];
        }

        $custom = [];
        foreach ($rules as $name => $rule) {
            $custom[$name] = ['description' => $this->describe($name, $rule)];
            $example = $this->example($name, $rule);
            if ($example !== null) {
                $custom[$name]['example'] = $example;
            }
        }

        return $this->normaliseArrayAndObjectParameters(
            $this->getParametersFromValidationRules($rules, $custom)
        );
    }

    protected function shouldCastUserExample(): bool
    {
        return false;
    }

    private function findValidateArgument(ClassMethod $method): ?Expr
    {
        $call = (new NodeFinder)->findFirst($method->stmts ?? [], function (Node $node) {
            return $node instanceof Expr\MethodCall
                && $node->name instanceof Identifier
                && $node->name->name === 'validate'
                && $node->var instanceof Expr\Variable
                && in_array($node->var->name, ['request', 'req'], true)
                && isset($node->args[0]);
        });

        return $call instanceof Expr\MethodCall ? $call->args[0]->value : null;
    }

    /** @return array<string, string> */
    private function rulesFromExpr(?Expr $expr, \ReflectionClass $class, int $depth = 0): array
    {
        if (! $expr || $depth > 5) {
            return [];
        }

        if ($expr instanceof Expr\Array_) {
            $rules = [];
            foreach ($expr->items as $item) {
                if (! $item) {
                    continue;
                }
                if ($item->unpack) {
                    $rules += $this->rulesFromExpr($item->value, $class, $depth + 1);

                    continue;
                }
                if (! $item->key instanceof String_) {
                    continue;
                }
                $rule = $this->ruleToString($item->value);
                if ($rule !== '') {
                    $rules[$item->key->value] = $rule;
                }
            }

            return $rules;
        }

        if ($expr instanceof Expr\BinaryOp\Plus || $expr instanceof Expr\BinaryOp\Concat) {
            return $this->rulesFromExpr($expr->left, $class, $depth + 1)
                + $this->rulesFromExpr($expr->right, $class, $depth + 1);
        }

        if ($expr instanceof Expr\MethodCall && $expr->name instanceof Identifier) {
            return $this->rulesFromMethod($class, $expr->name->name, $depth + 1);
        }

        if ($expr instanceof Expr\StaticCall && $expr->name instanceof Identifier) {
            $target = $expr->class instanceof Node\Name ? $expr->class->toString() : '';
            if (in_array($target, ['self', 'static', 'parent'], true) || str_ends_with($target, 'Controller')) {
                return $this->rulesFromMethod($class, $expr->name->name, $depth + 1);
            }
        }

        return [];
    }

    /** @return array<string, string> */
    private function rulesFromMethod(\ReflectionClass $class, string $name, int $depth): array
    {
        if ($depth > 5 || ! $class->hasMethod($name)) {
            return [];
        }

        $ast = MethodAstParser::getMethodAst($class->getMethod($name));
        $rules = [];
        foreach ($ast->stmts ?? [] as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Expr) {
                $rules += $this->rulesFromExpr($stmt->expr, $class, $depth);
            }
        }

        return $rules;
    }

    private function ruleToString(?Expr $node): string
    {
        if (! $node) {
            return '';
        }
        if ($node instanceof String_) {
            return $node->value;
        }
        if ($node instanceof Expr\Ternary) {
            $parts = array_filter([
                $node->if ? $this->ruleToString($node->if) : '',
                $this->ruleToString($node->else),
            ]);

            return implode('|', $parts);
        }
        if ($node instanceof Expr\Array_) {
            $parts = [];
            foreach ($node->items as $item) {
                if ($item && ($part = $this->ruleToString($item->value)) !== '') {
                    $parts[] = $part;
                }
            }

            return implode('|', $parts);
        }
        if ($node instanceof Expr\StaticCall && $node->name instanceof Identifier) {
            return match ($node->name->name) {
                'in' => ($vals = $this->stringList($node->args[0]->value ?? null)) !== []
                    ? 'in:'.implode(',', $vals)
                    : 'string',
                'exists' => 'integer',
                'unique', 'enum' => 'string',
                default => '',
            };
        }
        if ($node instanceof Expr\BinaryOp\Concat) {
            $left = $this->ruleToString($node->left);

            return str_starts_with($left, 'in:') ? $left : 'string';
        }

        return '';
    }

    /** @return list<string> */
    private function stringList(?Expr $node): array
    {
        if (! $node instanceof Expr\Array_) {
            return [];
        }
        $values = [];
        foreach ($node->items as $item) {
            if ($item?->value instanceof String_) {
                $values[] = $item->value->value;
            }
        }

        return $values;
    }

    private function describe(string $name, string $rules): string
    {
        if ($name === 'client_uuid' || str_ends_with($name, '.client_uuid')) {
            $required = str_contains($rules, 'required');

            return 'Clé d’idempotence générée par l’app (UUID). '
                .($required ? 'Requis' : 'Optionnel — le serveur en génère une si omise')
                .'. Ce n’est pas l’identifiant d’un client CRM.';
        }

        $bits = [str_contains($rules, 'required') ? 'Requis' : 'Optionnel'];
        if (preg_match('/(?:^|\|)exists:(\w+)/', $rules, $m)) {
            $bits[] = 'id existant ('.$m[1].')';
        }
        if (preg_match('/(?:^|\|)in:([^|]+)/', $rules, $m)) {
            $bits[] = 'parmi '.str_replace(',', ', ', $m[1]);
        } elseif (str_contains($rules, 'integer') || str_contains($rules, 'numeric')) {
            $bits[] = 'nombre';
        } elseif (str_contains($rules, 'boolean')) {
            $bits[] = 'booléen';
        } elseif (str_contains($rules, 'array')) {
            $bits[] = 'tableau';
        } elseif (str_contains($rules, 'email')) {
            $bits[] = 'e-mail';
        } elseif (str_contains($rules, 'date')) {
            $bits[] = 'date ISO 8601';
        } elseif (str_contains($rules, 'uuid')) {
            $bits[] = 'UUID';
        } elseif (str_contains($rules, 'file') || str_contains($rules, 'image')) {
            $bits[] = 'fichier';
        }

        $label = str_replace(['_', '.'], ' ', $name);

        return $label.' — '.implode(', ', $bits);
    }

    private function example(string $name, string $rules): mixed
    {
        if (str_contains($rules, 'array') || str_contains($rules, 'file') || str_contains($rules, 'image')) {
            return null;
        }
        // Optionnel : ne pas le mettre dans l’exemple curl (évite la confusion « UUID client CRM »).
        if (($name === 'client_uuid' || str_ends_with($name, '.client_uuid')) && ! str_contains($rules, 'required')) {
            return 'No-example';
        }
        if (preg_match('/(?:^|\|)in:([^|]+)/', $rules, $m)) {
            return explode(',', $m[1])[0];
        }
        if (str_contains($rules, 'boolean')) {
            return true;
        }
        if (str_contains($rules, 'uuid')) {
            return '550e8400-e29b-41d4-a716-446655440000';
        }
        if (str_contains($rules, 'email') || str_ends_with($name, 'email')) {
            return 'client@example.com';
        }
        if (str_contains($rules, 'date')) {
            return '2026-11-06T10:00:00+00:00';
        }
        if (str_contains($name, 'phone')) {
            return '690000001';
        }
        if (str_contains($name, 'password')) {
            return 'password';
        }
        if (str_contains($rules, 'integer') || str_contains($rules, 'numeric') || str_ends_with($name, '_id')) {
            return str_contains($name, 'price') || str_contains($name, 'amount') || str_contains($name, 'fee') ? 1000 : 1;
        }
        if (in_array($name, ['fullname', 'name', 'label'], true)) {
            return $name === 'fullname' ? 'Jean Dupont' : 'Exemple';
        }

        return 'exemple';
    }
}
