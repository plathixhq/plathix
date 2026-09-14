<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\If_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\FunctionLike>
 */
final class ForbiddenOptionalExtensionUsageRule implements Rule
{
	private const GUARD_FUNCTIONS = [ 'class_exists', 'function_exists' ];

	private const OWNER_FILE_SUFFIXES = [
		'src/Core/MbCompat.php',
	];

	/**
	 * @var array<class-string, array<string, string>>
	 */
	private const TARGET_SYMBOLS = [
		New_::class    => [
			'ZipArchive' => 'class_exists',
		],
		FuncCall::class => [
			'mb_strtolower' => 'function_exists',
			'mb_substr'     => 'function_exists',
			'mb_strlen'     => 'function_exists',
		],
	];

	public function getNodeType(): string
	{
		return Node\FunctionLike::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof Node\FunctionLike || $node->getStmts() === null ) {
			return [];
		}

		$normalizedFile = str_replace( '\\', '/', $scope->getFile() );
		foreach ( self::OWNER_FILE_SUFFIXES as $ownerSuffix ) {
			if ( str_ends_with( $normalizedFile, $ownerSuffix ) ) {
				return [];
			}
		}

		$stmts = $node->getStmts();

		/**
		 * @var array<string, int> $guardedAtIndex
		 */
		$guardedAtIndex = [];
		foreach ( $stmts as $index => $stmt ) {
			$guardedSymbol = $this->extractGuardedSymbol( $stmt );
			if ( $guardedSymbol !== null && ! isset( $guardedAtIndex[ $guardedSymbol ] ) ) {
				$guardedAtIndex[ $guardedSymbol ] = $index;
			}
		}

		$errors = [];
		foreach ( $stmts as $index => $stmt ) {
			$finder = new NodeFinder();
			$hits   = $finder->find( $stmt, function (Node $n) use ($guardedAtIndex, $index): ?array {
				$match = $this->matchTargetSymbol( $n );
				if ( $match === null ) {
					return null;
				}
				[ $symbol, $guardFunction ] = $match;
				if ( isset( $guardedAtIndex[ $symbol ] ) && $guardedAtIndex[ $symbol ] < $index ) {
					return null;
				}
				return [ $symbol, $guardFunction ];
			} );

			foreach ( $hits as $hit ) {
				$match = $this->matchTargetSymbol( $hit );
				if ( $match === null ) {
					continue;
				}
				[ $symbol, $guardFunction ] = $match;
				if ( isset( $guardedAtIndex[ $symbol ] ) && $guardedAtIndex[ $symbol ] < $index ) {
					continue;
				}

				$errors[] = RuleErrorBuilder::message( sprintf(
					'%s used without a preceding top-level %s(...) guard in this function — this ' . 'is an optional PHP extension/function, not guaranteed to be available. ' . 'Add ' . '`if ( ! %s(...) ) { ...fail...; }` immediately before this call, as a top-level ' . 'statement (see PresetExportPipeline.php for the established pattern). ' . 'If this ' . 'call is genuinely guarded another way, add @phpstan-ignore with a one-line ' . 'justification. ',
					$this->describeSymbol( $hit ),
					$guardFunction,
					$guardFunction
				) )->identifier( 'plathix.optionalExtensionUsage' )->line( $hit->getStartLine() )->build();
			}
		}

		return $errors;
	}

	private function extractGuardedSymbol(Node $stmt): ?string
	{
		if ( ! $stmt instanceof If_ ) {
			return null;
		}
		if ( ! $stmt->cond instanceof BooleanNot ) {
			return null;
		}

		$inner = $stmt->cond->expr;
		if ( ! $inner instanceof FuncCall || ! $inner->name instanceof Node\Name ) {
			return null;
		}
		if ( ! in_array( $inner->name->toString(), self::GUARD_FUNCTIONS, true ) ) {
			return null;
		}

		$args = $inner->getArgs();
		if ( count( $args ) < 1 ) {
			return null;
		}

		return $this->symbolFromArgValue( $args[0]->value );
	}

	private function symbolFromArgValue(Node $value): ?string
	{
		if ( $value instanceof String_ ) {
			return ltrim( $value->value, '\\' );
		}
		if ( $value instanceof ClassConstFetch && $value->class instanceof Node\Name && $value->name instanceof Node\Identifier && strtolower( $value->name->toString() ) === 'class' ) {
			return ltrim( $value->class->toString(), '\\' );
		}
		return null;
	}

	/**
	 * @return array{0: string, 1: string}|null
	 */
	private function matchTargetSymbol(Node $node): ?array
	{
		if ( $node instanceof New_ && $node->class instanceof Node\Name ) {
			$symbol = ltrim( $node->class->toString(), '\\' );
			$guard  = self::TARGET_SYMBOLS[ New_::class ][ $symbol ] ?? null;
			return $guard !== null ? [ $symbol, $guard ] : null;
		}
		if ( $node instanceof FuncCall && $node->name instanceof Node\Name ) {
			$symbol = ltrim( $node->name->toString(), '\\' );
			$guard  = self::TARGET_SYMBOLS[ FuncCall::class ][ $symbol ] ?? null;
			return $guard !== null ? [ $symbol, $guard ] : null;
		}
		return null;
	}

	private function describeSymbol(Node $node): string
	{
		if ( $node instanceof New_ && $node->class instanceof Node\Name ) {
			return 'new ' . $node->class->toString() . '()';
		}
		if ( $node instanceof FuncCall && $node->name instanceof Node\Name ) {
			return $node->name->toString() . '()';
		}
		return 'symbol';
	}
}
