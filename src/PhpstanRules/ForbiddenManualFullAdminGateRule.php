<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BinaryOp;
use PhpParser\Node\Expr\BinaryOp\BooleanAnd;
use PhpParser\Node\Expr\BinaryOp\BooleanOr;
use PhpParser\Node\Expr\BinaryOp\Identical;
use PhpParser\Node\Expr\BinaryOp\NotIdentical;
use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * [internal] (follow-up от [internal], [internal]): 8 мест в Free и PRO независимо
 * повторяли `current_user_can('manage_options') && AccessResolver::for_current_user() ===
 * AccessLevel::Full` вручную, пока [internal] не извлёк единый
 * `AccessResolver::currentUserIsFullAdmin()`. Существующий `ForbiddenManualAccessLevelRankingRule`
 * ([internal]) НЕ ловит этот паттерн — его триггер требует 2+ `AccessLevel::Case`-сравнения
 * в одном выражении, здесь только одно (второй операнд — `current_user_can()`, не
 * AccessLevel-сравнение). Подтверждено живым PHPStan-прогоном при паковке #535, не
 * предположением. Это enforced-барьер против 9-й ручной копии.
 *
 * Триггер: `||`/`&&`-выражение, где один непосредственный операнд —
 * `current_user_can('manage_options')` (или его De Morgan-негация `!current_user_can(...)`),
 * а другой — `AccessResolver::for_current_user() === AccessLevel::Full` (или `!==`), вне
 * `AccessResolver.php`. В отличие от ranking-правила, ждать 2+ вхождений не нужно — сама пара
 * (cap-check + Full-check) уже полностью эквивалентна `currentUserIsFullAdmin()`.
 *
 * НЕ ловит: `current_user_can()` с любой ДРУГОЙ capability-строкой рядом с
 * `AccessLevel::Full`-сравнением (легитимная независимая проверка, не копия этого гейта) —
 * привязка жёстко к `'manage_options'`, не к любой строке.
 *
 * @implements Rule<BinaryOp>
 */
final class ForbiddenManualFullAdminGateRule implements Rule
{
	private const TARGET_ACCESS_LEVEL_CLASS = 'Plathix\\User\\AccessLevel';
	private const TARGET_ACCESS_RESOLVER_CLASS = 'Plathix\\User\\AccessResolver';
	private const TARGET_FILE_SUFFIX = 'src/User/AccessResolver.php';
	private const MANAGE_OPTIONS_CAP = 'manage_options';

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

		// [internal]: PHP строит ассоциативную цепочку `A && B && C` как вложенные BinaryOp-узлы
		// (`(A && B) && C`) — проверка только $node->left/$node->right ловит пару лишь на двух
		// операндах. Разворачиваем всю однородную цепочку до листьев, чтобы пара cap-check +
		// Full-comparison находилась независимо от того, сколько операндов и в какую сторону
		// сгруппировал парсер. Регистрируем ошибку только на САМОМ ВЕРХНЕМ узле цепочки (когда пара
		// уже не помещается целиком в один из двух непосредственных операндов) — иначе один и тот
		// же реальный дефект выдал бы дублирующиеся ошибки на каждом вложенном узле цепочки.
		if ( $this->chainContainsPair( $node->left, $scope ) || $this->chainContainsPair( $node->right, $scope ) ) {
			return [];
		}

		$operands = $this->flattenChain( $node, $node::class );

		$hasCapCheck = false;
		$hasFullComparison = false;
		foreach ( $operands as $operand ) {
			$hasCapCheck = $hasCapCheck || $this->isManageOptionsCapCheck( $operand, $scope );
			$hasFullComparison = $hasFullComparison || $this->isAccessResolverFullComparison( $operand, $scope );
		}

		if ( ! $hasCapCheck || ! $hasFullComparison ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				'Manual full-admin gate (current_user_can(\'manage_options\') combined with ' .
				'AccessResolver::for_current_user() === AccessLevel::Full in one %s expression) — ' .
				'use AccessResolver::currentUserIsFullAdmin() instead of comparing by hand. See ' .
				'[internal] (follow-up of [internal], which extracted the shared helper after 8 ' .
				'independent manual copies were found across Free and PRO). If this is a genuine ' .
				'unrelated check, add @phpstan-ignore with a one-line justification.',
				$node instanceof BooleanOr ? '||' : '&&'
			) )->identifier( 'plathix.manualFullAdminGate' )->build(),
		];
	}

	/**
	 * Разворачивает однородную цепочку BooleanAnd/BooleanOr в список листовых операндов.
	 * Останавливается на границе смены типа (BooleanAnd внутри BooleanOr и наоборот не
	 * разворачивается — это разные логические группы с разным приоритетом); `$rootClass`
	 * фиксирует, какой именно тип считается "тем же уровнем цепочки".
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
	 * True, если пара cap-check + Full-comparison уже целиком содержится внутри одного
	 * непосредственного операнда (то есть более глубокий узел цепочки сам найдёт и зарегистрирует
	 * её самостоятельно) — используется, чтобы не дублировать ошибку на каждом уровне цепочки.
	 */
	private function chainContainsPair(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof BooleanAnd && ! $node instanceof BooleanOr ) {
			return false;
		}

		$operands = $this->flattenChain( $node, $node::class );

		$hasCapCheck = false;
		$hasFullComparison = false;
		foreach ( $operands as $operand ) {
			$hasCapCheck = $hasCapCheck || $this->isManageOptionsCapCheck( $operand, $scope );
			$hasFullComparison = $hasFullComparison || $this->isAccessResolverFullComparison( $operand, $scope );
		}

		return $hasCapCheck && $hasFullComparison;
	}

	/**
	 * Matches `current_user_can('manage_options')`, optionally wrapped in `!` (De Morgan form).
	 */
	private function isManageOptionsCapCheck(Node $node, Scope $scope): bool
	{
		if ( $node instanceof BooleanNot ) {
			$node = $node->expr;
		}

		if ( ! $node instanceof FuncCall ) {
			return false;
		}

		if ( ! $node->name instanceof Node\Name ) {
			return false;
		}

		if ( $scope->resolveName( $node->name ) !== 'current_user_can' ) {
			return false;
		}

		if ( count( $node->args ) < 1 || ! $node->args[0] instanceof Node\Arg ) {
			return false;
		}

		$firstArg = $node->args[0]->value;

		return $firstArg instanceof String_ && $firstArg->value === self::MANAGE_OPTIONS_CAP;
	}

	/**
	 * Matches `AccessResolver::for_current_user() === AccessLevel::Full` (or `!==`).
	 */
	private function isAccessResolverFullComparison(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof Identical && ! $node instanceof NotIdentical ) {
			return false;
		}

		return ( $this->isAccessResolverForCurrentUserCall( $node->left, $scope ) && $this->isAccessLevelFullFetch( $node->right, $scope ) )
			|| ( $this->isAccessResolverForCurrentUserCall( $node->right, $scope ) && $this->isAccessLevelFullFetch( $node->left, $scope ) );
	}

	private function isAccessResolverForCurrentUserCall(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof StaticCall ) {
			return false;
		}

		if ( ! $node->class instanceof Node\Name ) {
			return false;
		}

		if ( $scope->resolveName( $node->class ) !== self::TARGET_ACCESS_RESOLVER_CLASS ) {
			return false;
		}

		return $node->name instanceof Node\Identifier && $node->name->toString() === 'for_current_user';
	}

	private function isAccessLevelFullFetch(Node $node, Scope $scope): bool
	{
		if ( ! $node instanceof ClassConstFetch ) {
			return false;
		}

		if ( ! $node->class instanceof Node\Name ) {
			return false;
		}

		if ( $scope->resolveName( $node->class ) !== self::TARGET_ACCESS_LEVEL_CLASS ) {
			return false;
		}

		return $node->name instanceof Node\Identifier && $node->name->toString() === 'Full';
	}
}
