<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\ClassConstFetch;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * [internal]: `AjaxGuard::require()` и `RestController::level_satisfies()` независимо
 * реализовывали "AccessLevel не ниже требуемого" ручным `||`-сравнением case'ов вместо
 * `AccessLevel::satisfies()`. Postwork cross-repo проверка нашла 3-ю копию того же паттерна
 * в PRO (`ApiKeyAuthenticator::level_grants_upload()`). Комментарии на `AccessLevel.php` уже
 * стояли и не предотвратили ни одного из трёх случаев — это enforced-барьер против 4-й копии.
 *
 * Триггер: `||`/`&&`-выражение с 2+ операндами вида `AccessLevel::Case === $x` (или `!==`)
 * вне `AccessLevel.php`. НЕ ловит: единичное точное сравнение (`=== AccessLevel::None`) —
 * это легитимный паттерн "один конкретный случай", не ranking-сравнение. НЕ ловит числовую
 * rank-таблицу (`array` с `AccessLevel::X->value => int`) — другой AST-паттерн, известный
 * gap.
 *
 * Правило регистрируется на `BinaryOp` — PHPStan обходит дерево узел-за-узлом, включая
 * вложенные `A || B || C` (цепочка вложенных `BooleanOr` в AST: `(A || B) || C`).
 * [internal]: разворачиваем всю однородную цепочку до листьев на каждом вызове, но
 * регистрируем находку только на самом верхнем узле цепочки — если пара уже целиком
 * содержится в одном непосредственном операнде, более глубокий узел сам её найдёт и
 * зарегистрирует, повторная регистрация на внешнем уровне не нужна. До этого фикса
 * правило проверяло только непосредственные left/right одного узла — `AccessLevel::X ===
 * $a || $unrelated || AccessLevel::Y === $b` (2 реальных сравнения, разделённые
 * нерелевантным операндом) не ловился ни одним узлом дерева.
 *
 * @implements Rule<BinaryOp>
 */
final class ForbiddenManualAccessLevelRankingRule implements Rule
{
	private const TARGET_CLASS = 'Plathix\\User\\AccessLevel';
	private const TARGET_FILE_SUFFIX = 'src/User/AccessLevel.php';

	public function getNodeType(): string
	{
		return BinaryOp::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof BooleanOr && ! $node instanceof BooleanAnd ) {
			return [];
		}

		if ( str_ends_with( str_replace( '\\', '/', $scope->getFile() ), self::TARGET_FILE_SUFFIX ) ) {
			return [];
		}

		if ( $this->chainComparisonCount( $node->left, $scope ) >= 2 || $this->chainComparisonCount( $node->right, $scope ) >= 2 ) {
			return [];
		}

		$accessLevelComparisons = $this->chainComparisonCount( $node, $scope );

		if ( $accessLevelComparisons < 2 ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				'Manual AccessLevel ranking comparison (%d case checks in one %s expression) — ' .
				'use AccessLevel::satisfies() instead of comparing cases by hand. See [internal] ' .
				'(3 independent reimplementations of the same "level not below required" check ' .
				'found across Free and PRO). If this is a genuine single-case check unrelated to ' .
				'ranking, add @phpstan-ignore with a one-line justification.',
				$accessLevelComparisons,
				$node instanceof BooleanOr ? '||' : '&&'
			) )->identifier( 'plathix.manualAccessLevelRanking' )->build(),
		];
	}

	/**
	 * Разворачивает однородную цепочку BooleanAnd/BooleanOr в список листовых операндов.
	 * Останавливается на границе смены типа — BooleanAnd внутри BooleanOr и наоборот не
	 * разворачивается, это разные логические группы с разным приоритетом.
	 *
	 * @param class-string<BooleanAnd|BooleanOr> $rootClass
	 * @return list<Node>
	 */
	private function flattenChain(Node $node, string $rootClass): array
	{
		if ( ( $node instanceof BooleanAnd || $node instanceof BooleanOr ) && $node::class === $rootClass ) {
			return array_merge(
				$this->flattenChain( $node->left, $rootClass ),
				$this->flattenChain( $node->right, $rootClass )
			);
		}

		return [ $node ];
	}

	/**
	 * Считает AccessLevel::Case-сравнения среди ВСЕХ листовых операндов однородной цепочки,
	 * начиная с `$node`. Возвращает 0, если `$node` сам не BooleanAnd/BooleanOr.
	 */
	private function chainComparisonCount(Node $node, Scope $scope): int
	{
		if ( ! $node instanceof BooleanAnd && ! $node instanceof BooleanOr ) {
			return 0;
		}

		$count = 0;
		foreach ( $this->flattenChain( $node, $node::class ) as $operand ) {
			if ( $this->isAccessLevelCaseComparison( $operand, $scope ) ) {
				++$count;
			}
		}

		return $count;
	}

	private function isAccessLevelCaseComparison(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof Identical && ! $node instanceof NotIdentical ) {
			return false;
		}

		return $this->isAccessLevelCaseFetch( $node->left, $scope )
			|| $this->isAccessLevelCaseFetch( $node->right, $scope );
	}

	private function isAccessLevelCaseFetch(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof ClassConstFetch ) {
			return false;
		}

		if ( ! $node->class instanceof Node\Name ) {
			return false;
		}

		$resolvedType = $scope->resolveName( $node->class );

		return $resolvedType === self::TARGET_CLASS;
	}
}
