<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Scalar\LNumber;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Array_>
 */

final class ForbiddenManualAccessLevelRankTableRule implements Rule
{
	private const TARGET_CLASS = 'Plathix\\User\\AccessLevel';
	private const TARGET_FILE_SUFFIX = 'src/User/AccessLevel.php';

	public function getNodeType(): string
	{
		return Array_::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof Array_ ) {
			return [];
		}

		if ( str_ends_with( str_replace( '\\', '/', $scope->getFile() ), self::TARGET_FILE_SUFFIX ) ) {
			return [];
		}

		$rankEntries = 0;
		foreach ( $node->items as $item ) {
			if ( $item === null || $item->key === null ) {
				continue;
			}

			if ( $this->isAccessLevelValueFetch( $item->key, $scope ) && $item->value instanceof LNumber ) {
				++$rankEntries;
			}
		}

		if ( $rankEntries < 2 ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				'Manual AccessLevel rank table (%d entries mapping AccessLevel::Case->value to an ' .
				'integer) — use AccessLevel::satisfies() instead of a hand-written ranking table. ' .
				'Static analysis rule failed for a public contract violation.' .
				'required" comparison was found in RestController::level_satisfies() before the ' .
				'fix). If this is a genuine non-ranking lookup map, add @phpstan-ignore with a ' .
				'one-line justification.',
				$rankEntries
			) )->identifier( 'plathix.manualAccessLevelRankTable' )->build(),
		];
	}

	private function isAccessLevelValueFetch(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof PropertyFetch ) {
			return false;
		}

		if ( ! $node->name instanceof Node\Identifier || $node->name->toString() !== 'value' ) {
			return false;
		}

		if ( ! $node->var instanceof ClassConstFetch ) {
			return false;
		}

		if ( ! $node->var->class instanceof Node\Name ) {
			return false;
		}

		return $scope->resolveName( $node->var->class ) === self::TARGET_CLASS;
	}
}
