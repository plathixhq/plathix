<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantArrayType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * @implements Rule<Node\FunctionLike>
 */

final class ForbiddenSnakeCaseResponseKeyRule implements Rule
{
	private const TARGET_FUNCTIONS = [ 'wp_send_json_success', 'wp_send_json_error' ];
	private const TARGET_CLASS     = 'WP_REST_Response';

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

		$topLevelAssignments = [];
		foreach ( $node->getStmts() as $stmt ) {
			$this->collectTopLevelAssignments( $stmt, $topLevelAssignments );
		}

		$finder = new NodeFinder();
		$calls  = $finder->find( $node->getStmts(), static function (Node $n): bool {
			if ( $n instanceof FuncCall ) {
				return $n->name instanceof Node\Name
					&& in_array( $n->name->toString(), self::TARGET_FUNCTIONS, true );
			}
			if ( $n instanceof New_ ) {
				return $n->class instanceof Node\Name
					&& $n->class->toString() === self::TARGET_CLASS;
			}
			return false;
		} );

		$errors = [];
		foreach ( $calls as $call ) {
			if ( ! $call instanceof CallLike ) {
				continue;
			}
			$errors = array_merge(
				$errors,
				$this->checkCall( $call, $scope, $topLevelAssignments )
			);
		}

		return $errors;
	}

	/**
	 * @param array<string, list<string>|null> $topLevelAssignments
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkCall(CallLike $call, Scope $scope, array $topLevelAssignments): array
	{
		$firstArg = $call->getArgs()[0] ?? null;
		if ( $firstArg === null || ! $firstArg instanceof Node\Arg ) {
			return [];
		}

		if ( $firstArg->value instanceof Array_ ) {
			return $this->checkArrayLiteral( $firstArg->value );
		}

		if ( $firstArg->value instanceof Node\Expr\Variable && is_string( $firstArg->value->name ) ) {
			$varName = $firstArg->value->name;

			$traced = $topLevelAssignments[ $varName ] ?? null;
			if ( $traced !== null ) {
				return $this->checkKeys( $traced );
			}

			$type = $scope->getType( $firstArg->value );
			return $this->checkShapeType( $type );
		}

		$type = $scope->getType( $firstArg->value );
		return $this->checkShapeType( $type );
	}

	/**
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkArrayLiteral(Array_ $array): array
	{
		$keys = [];
		foreach ( $array->items as $item ) {
			if ( $item === null || $item->key === null || ! $item->key instanceof String_ ) {
				continue;
			}
			$keys[] = $item->key->value;
		}
		return $this->checkKeys( $keys );
	}

	/**
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkShapeType(Type $type): array
	{
		$type = TypeCombinator::removeNull( $type );
		if ( ! $type instanceof ConstantArrayType ) {
			return [];
		}

		$keys = [];
		foreach ( $type->getKeyTypes() as $keyType ) {
			if ( $keyType instanceof ConstantStringType ) {
				$keys[] = $keyType->getValue();
			}
		}
		return $this->checkKeys( $keys );
	}

	/**
	 * @param array<string, list<string>|null> $collected
	 */

	private function collectTopLevelAssignments(Node\Stmt $stmt, array &$collected): void
	{
		if (
			$stmt instanceof Node\Stmt\If_
			|| $stmt instanceof Node\Stmt\Foreach_
			|| $stmt instanceof Node\Stmt\For_
			|| $stmt instanceof Node\Stmt\While_
			|| $stmt instanceof Node\Stmt\Switch_
		) {
			$finder = new NodeFinder();
			$vars   = $finder->find( [ $stmt ], static function (Node $n): bool {
				return $n instanceof Node\Expr\Variable && is_string( $n->name );
			} );
			foreach ( $vars as $var ) {
				if ( $var instanceof Node\Expr\Variable && is_string( $var->name ) ) {
					$collected[ $var->name ] = null;
				}
			}
			return;
		}

		if ( ! $stmt instanceof Node\Stmt\Expression || ! $stmt->expr instanceof Node\Expr\Assign ) {
			return;
		}

		$assign = $stmt->expr;

		if ( $assign->var instanceof Node\Expr\Variable && is_string( $assign->var->name ) ) {
			$varName = $assign->var->name;
			if ( $assign->expr instanceof Array_ && count( $assign->expr->items ) === 0 ) {
				$collected[ $varName ] = [];
				return;
			}
			$collected[ $varName ] = null;
			return;
		}

		if (
			$assign->var instanceof Node\Expr\ArrayDimFetch
			&& $assign->var->var instanceof Node\Expr\Variable
			&& is_string( $assign->var->var->name )
			&& $assign->var->dim instanceof String_
		) {
			$varName = $assign->var->var->name;
			if ( array_key_exists( $varName, $collected ) && $collected[ $varName ] === null ) {
				return;
			}
			$collected[ $varName ][] = $assign->var->dim->value;
		}
	}

	/**
	 * @param list<string> $keys
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkKeys(array $keys): array
	{
		$errors = [];
		foreach ( $keys as $key ) {
			if ( $this->isSnakeCase( $key ) ) {
				$errors[] = RuleErrorBuilder::message( sprintf(
					'Response payload key "%s" is snake_case — use camelCase (see code-naming-standard.md). ' .
					'If this is a WP-native/persisted/legacy contract exception, add @phpstan-ignore with a one-line justification.',
					$key
				) )->identifier( 'plathix.forbiddenSnakeCaseResponseKey' )->build();
			}
		}
		return $errors;
	}

	private function isSnakeCase(string $key): bool
	{
		return str_contains( $key, '_' ) && preg_match( '/^[a-z][a-z0-9_]*$/', $key ) === 1;
	}
}
