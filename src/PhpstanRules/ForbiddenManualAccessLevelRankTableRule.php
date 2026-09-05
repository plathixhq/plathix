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
 * [internal]/#298: `ForbiddenManualAccessLevelRankingRule` catches the `||`/`&&` manual
 * comparison pattern, but the historical `RestController::level_satisfies()` bug (before
 * the #290 fix) used a DIFFERENT AST shape — a hand-written numeric rank table:
 *
 * ```php
 * $rank = [ AccessLevel::None->value => 0, AccessLevel::View->value => 1, ... ];
 * return ( $rank[ $user->value ] ?? 0 ) >= ( $rank[ $required->value ] ?? 0 );
 * ```
 *
 * `PHPStan\Rules\Rule<T>` is typed to a single node type per class, so this is a second,
 * narrow rule rather than extending the first one — same prevention goal, different AST
 * pattern. Triggers on: array literal with 2+ items whose KEY is `AccessLevel::Case->value`
 * (PropertyFetch on a ClassConstFetch) AND whose VALUE is an integer literal — the integer
 * value check specifically distinguishes a ranking table from a legitimate
 * `AccessLevel::X->value => 'label'` lookup map (different intent, not this bug's shape).
 *
 * HONEST SCOPE LIMIT: this rule (together with `ForbiddenManualAccessLevelRankingRule`)
 * catches the 2 specific AST shapes that have actually occurred (3 incidents total, 2 of
 * them the `||`/`&&` shape, 1 the rank-table shape). It does not — and cannot, as a finite
 * set of pattern-matchers — guarantee no future reimplementation in some third syntactic
 * form (`match` returning a rank int, `in_array()`, a parallel enum elsewhere). The actual
 * [internal] these two rules address is "no single canonical comparator existed before
 * `AccessLevel::satisfies()` was introduced" — that gap is now closed at the source-of-truth
 * level; these rules are a recurrence guard for the two forms already seen, not a closed-
 * ended guarantee against every conceivable future form.
 *
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
				'See [internal]/#298 (a rank-table shaped copy of the same "level not below ' .
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
