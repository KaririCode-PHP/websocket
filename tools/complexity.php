<?php

declare(strict_types=1);

/*
 * KaririCode Spec V4.3.3 §13 complexity budgets for src/, measured on the
 * AST (nikic/php-parser, installed by kcode with the toolchain). Cognitive
 * complexity (<= 15) is a PHPStan rule (tomasvotruba/cognitive-complexity,
 * phpstan.neon); this script checks the other budgets:
 *
 *   cyclomatic complexity per method <= 10  (McCabe; a match counts once)
 *   nesting depth per method         <= 3
 *   parameters per method            <= 4   (exempt: the constructor of a
 *                                             readonly class whose parameters
 *                                             are all promoted, because that
 *                                             object is the Parameter Object)
 *   public methods per class         <= 10  (soft: reported, not failing)
 *
 * tools/complexity-baseline.json lists the accepted breaches, each with its
 * reason, per metric. It can only shrink: a breach that is not listed fails,
 * and so does an entry that no longer breaches.
 *
 * Usage: php tools/complexity.php src. Exit code 1 on a hard violation.
 */

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

require dirname(__DIR__) . '/.kcode/vendor/autoload.php';

const BUDGETS = ['cyclomatic' => 10, 'nesting' => 3, 'parameters' => 4];
const MAX_PUBLIC = 10;

const DECISIONS = [
    Node\Stmt\If_::class, Node\Stmt\ElseIf_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class,
    Node\Stmt\While_::class, Node\Stmt\Do_::class, Node\Stmt\Catch_::class, Node\Stmt\Case_::class,
    Node\Expr\Ternary::class, Node\Expr\Match_::class, Node\Expr\BinaryOp\Coalesce::class,
    Node\Expr\BinaryOp\BooleanAnd::class, Node\Expr\BinaryOp\BooleanOr::class,
    Node\Expr\BinaryOp\LogicalAnd::class, Node\Expr\BinaryOp\LogicalOr::class, Node\Expr\BinaryOp\LogicalXor::class,
];

const NESTERS = [
    Node\Stmt\If_::class, Node\Stmt\For_::class, Node\Stmt\Foreach_::class, Node\Stmt\While_::class,
    Node\Stmt\Do_::class, Node\Stmt\Switch_::class, Node\Stmt\TryCatch::class, Node\Expr\Match_::class,
];

function cyclomatic(ClassMethod $method): int
{
    $nodes = (new NodeFinder())->find($method->stmts ?? [], static fn(Node $node): bool => in_array($node::class, DECISIONS, true));

    return 1 + count($nodes);
}

/**
 * @param array<mixed> $nodes
 */
function nesting(array $nodes, int $depth = 0): int
{
    $deepest = $depth;

    foreach ($nodes as $node) {
        if (is_array($node)) {
            $deepest = max($deepest, nesting($node, $depth));

            continue;
        }

        if (!$node instanceof Node) {
            continue;
        }

        $inner = in_array($node::class, NESTERS, true) ? $depth + 1 : $depth;
        $deepest = max($deepest, $inner);

        foreach ($node->getSubNodeNames() as $name) {
            $deepest = max($deepest, nesting([$node->{$name}], $inner));
        }
    }

    return $deepest;
}

function isParameterObjectConstructor(Node\Stmt\ClassLike $type, ClassMethod $method): bool
{
    return $method->name->toString() === '__construct'
        && $type instanceof Node\Stmt\Class_
        && $type->isReadonly()
        && array_all($method->params, static fn(Node\Param $param): bool => $param->flags !== 0);
}

/**
 * @return array<string, array<string, string>>
 */
function baseline(string $file): array
{
    $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
    $baseline = [];

    foreach (is_array($data) ? $data : [] as $metric => $entries) {
        foreach (is_array($entries) ? $entries : [] as $where => $reason) {
            $baseline[(string) $metric][(string) $where] = is_string($reason) ? $reason : '';
        }
    }

    return $baseline;
}

$directory = $argv[1] ?? 'src';
$parser = (new ParserFactory())->createForHostVersion();
$accepted = baseline(__DIR__ . '/complexity-baseline.json');
$breaches = [];
$violations = [];
$warnings = [];
$methods = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
        continue;
    }

    $ast = $parser->parse((string) file_get_contents($file->getPathname())) ?? [];

    foreach ((new NodeFinder())->findInstanceOf($ast, Node\Stmt\ClassLike::class) as $type) {
        $name = $type->name?->toString() ?? '(anonymous)';
        $public = 0;

        foreach ($type->getMethods() as $method) {
            ++$methods;
            $where = sprintf('%s::%s', $name, $method->name->toString());
            $public += $method->isPublic() ? 1 : 0;

            $measured = [
                'cyclomatic' => cyclomatic($method),
                'nesting' => nesting($method->stmts ?? []),
                'parameters' => isParameterObjectConstructor($type, $method) ? 0 : count($method->params),
            ];

            foreach ($measured as $metric => $value) {
                if ($value <= BUDGETS[$metric]) {
                    continue;
                }

                $breaches[$metric][$where] = true;

                if (!isset($accepted[$metric][$where])) {
                    $violations[] = sprintf('%s %s %d > %d', $where, $metric, $value, BUDGETS[$metric]);
                }
            }
        }

        if ($public > MAX_PUBLIC) {
            $warnings[] = sprintf('%s has %d public methods (soft budget %d)', $name, $public, MAX_PUBLIC);
        }
    }
}

foreach ($accepted as $metric => $entries) {
    foreach (array_keys($entries) as $where) {
        if (!isset($breaches[$metric][$where])) {
            $violations[] = sprintf('%s: the baseline accepts a %s breach that no longer exists; remove the entry', $where, $metric);
        }
    }
}

foreach ($warnings as $warning) {
    fwrite(STDERR, "warning: {$warning}\n");
}

foreach ($violations as $violation) {
    fwrite(STDERR, "violation: {$violation}\n");
}

$acceptedCount = array_sum(array_map('count', $accepted));
echo $violations === []
    ? sprintf("complexity: %d methods within the §13 budgets; %d accepted breaches (tools/complexity-baseline.json)\n", $methods, $acceptedCount)
    : sprintf("complexity: %d violation(s)\n", count($violations));

exit($violations === [] ? 0 : 1);
