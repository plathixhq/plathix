<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Issue #506: {@see ForbiddenCrossModuleInternalsRule} матчит только AST-узел `Use_`
 * (statement `use Plathix\Modules\<X>\...`), поэтому не ловит:
 *   - инлайн полностью квалифицированные ссылки — `\Plathix\Modules\X\Y::method()`,
 *     `\Plathix\Modules\X\Y::CONST`, `new \Plathix\Modules\X\Y()`, `X::$staticProp`;
 *   - `extends`/`implements`/`instanceof`/return-type/param-type/typed property/`catch`/
 *     `use TraitName`/атрибуты (`#[...]`), ссылающиеся на cross-module класс;
 *   - строковые ЛИТЕРАЛЫ (не переменные/конкатенацию) в динамическом резолве —
 *     `class_exists('Plathix\Modules\X\Y')`, `is_a($x, 'Plathix\Modules\X\Y')`,
 *     `new ReflectionClass('Plathix\Modules\X\Y')`.
 *
 * Семантика allowlist/module-detection идентична {@see ForbiddenCrossModuleInternalsRule} —
 * тот же контракт (feature-модуль↔feature-модуль внутри Free), другой AST-вход. Зеркало
 * PRO-версии этого же нового правила (`PlathixPro\PhpstanRules\
 * ForbiddenCrossModuleInternalsInlineReferenceRule`, [internal]).
 *
 * Honest known limitation (тот же disclaimer, что PRO-версия), уточнено [internal]:
 *   - покрывает ТОЛЬКО строковые ЛИТЕРАЛЫ в аргументе. Переменную или конкатенацию строки
 *     (`class_exists($var)`, `class_exists('Prefix\\' . $suffix)`) НЕ ловит и не может —
 *     halting problem для произвольной строки, собранной в рантайме;
 *   - `call_user_func` покрывает ТОЛЬКО строковую форму первого аргумента
 *     (`call_user_func('Plathix\Modules\X\Y::method')`), НЕ покрывает array-callable
 *     (`call_user_func(['Plathix\Modules\X\Y', 'method'])`) — первый элемент массива не
 *     является строковым литералом-аргументом функции в терминах AST этого правила
 *     (`$node->args[0]->value` для array-формы — это `Array_`, не `String_`).
 *
 * @implements Rule<Node>
 */
final class ForbiddenCrossModuleInternalsInlineReferenceRule implements Rule
{
	/** Строковые аргументы этих вызовов (первый параметр — литерал класса) проверяются. */
	private const CHECKED_FUNCTIONS = [
		'class_exists',
		'call_user_func',
		'is_a',
		'class_implements',
		'method_exists',
	];

	public function getNodeType(): string
	{
		return Node::class;
	}

	/**
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$currentModule = $this->moduleOf( $scope->getNamespace() ?? '' );
		if ( $currentModule === null ) {
			return [];
		}

		$errors = [];
		foreach ( $this->extractTargetFqcns( $node ) as $fqcn ) {
			$error = $this->buildErrorForFqcn( $fqcn, $currentModule );
			if ( $error !== null ) {
				$errors[] = $error;
			}
		}

		return $errors;
	}

	private function buildErrorForFqcn(string $fqcn, string $currentModule): ?\PHPStan\Rules\RuleError
	{
		if ( ! str_starts_with( $fqcn, 'Plathix\\Modules\\' ) ) {
			return null;
		}

		// Issue #635: здесь раньше был цикл по константе ALLOWED_PREFIXES
		// (Core\/Infrastructure\/User\/Contracts\/PublicApi\) — все её префиксы не могут
		// начинаться с 'Plathix\Modules\', которым уже гарантированно начинается $fqcn на
		// этой строке, так что str_starts_with($fqcn, $allowed) не мог вернуть true ни при
		// каком входе. Цикл и сама константа были мёртвым кодом и удалены (allowed-prefix
		// классы физически не могут попасть под эту ветку — они не Plathix\Modules\*).

		$targetModule = $this->moduleOf( $fqcn );
		if ( $targetModule === null || $targetModule === $currentModule ) {
			return null;
		}

		return RuleErrorBuilder::message( sprintf(
			'Module "%s" must not reference internal class %s of module "%s" via inline FQCN or a ' .
			'string-literal dynamic call — depend on a stable contract instead (Plathix\PublicApi\*, ' .
			'or Plathix\Core\*/Infrastructure\*/User\*/Contracts\*, or a plathix/* WP hook). ' .
			'See [internal] / [internal].',
			$currentModule,
			$fqcn,
			$targetModule
		) )->identifier( 'plathix.forbiddenCrossModuleInternalInline' )->build();
	}

	/**
	 * Достаёт FQCN-строки из inline FQCN-ссылки (`ClassConstFetch`/`New_`/`StaticCall`/
	 * `StaticPropertyFetch` с `Name\FullyQualified`), из `extends`/`implements` объявлений
	 * класса/интерфейса/enum, из `instanceof`, из return-type объявления метода, из типа
	 * параметра (включая promoted constructor params) и typed property, из `catch (...)`,
	 * из `use TraitName` внутри класса, из атрибутов (`#[...]`), или из строкового литерала
	 * аргумента одного из {@see self::CHECKED_FUNCTIONS} (`class_exists('...')` и т.п., для
	 * `is_a` — второй аргумент, класс, а не первый — объект) либо `new ReflectionClass('...')`.
	 * Большинство узлов дают максимум одну FQCN, но `implements`/`interface extends`/union
	 * return-type/`catch`/`use`-трейты могут содержать несколько.
	 *
	 * Важно: PHPStan резолвит короткие/qualified `Name`-узлы (через `use`-импорт) в
	 * `Name\FullyQualified` ДО того, как AST доходит до `processNode()` — значит
	 * `extends`/`implements`/`instanceof`/return-type/param-type/property-type/catch/
	 * trait-use/атрибуты ловят cross-module ссылку независимо от того, написана ли она с
	 * буквальным `\`-префиксом в исходнике или через `use`-алиас.
	 * Honest known limitation (строковые литералы в {@see self::CHECKED_FUNCTIONS} не
	 * покрывают переменную/конкатенацию) относится только к тем веткам — строка не является
	 * AST `Name`-узлом и PHPStan её не резолвит.
	 *
	 * @return list<string>
	 */
	private function extractTargetFqcns(Node $node): array
	{
		if ( $node instanceof StaticCall || $node instanceof ClassConstFetch || $node instanceof StaticPropertyFetch ) {
			$fqcn = $this->fqcnFromNameNode( $node->class );
			return $fqcn !== null ? [ $fqcn ] : [];
		}

		if ( $node instanceof New_ ) {
			$fromClassNode = $this->fqcnFromNameNode( $node->class );
			if ( $fromClassNode !== null ) {
				return [ $fromClassNode ];
			}

			// new ReflectionClass('Plathix\Modules\X\Y') — class-нода сама по себе
			// Node\Name('ReflectionClass'), не FQCN-цель; цель — строковый литерал аргумента.
			if ( $node->class instanceof Name && $node->class->toString() === 'ReflectionClass' ) {
				$fqcn = $this->fqcnFromStringArg( $node->args, 0 );
				return $fqcn !== null ? [ $fqcn ] : [];
			}

			return [];
		}

		if ( $node instanceof FuncCall && $node->name instanceof Name ) {
			$functionName = $node->name->toString();
			if ( in_array( $functionName, self::CHECKED_FUNCTIONS, true ) ) {
				// is_a(object|string $object, string $class, bool $allow_string = false) —
				// класс второй аргумент ([internal]: раньше бралось args[0], т.е. $object).
				$argIndex = $functionName === 'is_a' ? 1 : 0;
				$fqcn = $this->fqcnFromStringArg( $node->args, $argIndex );
				return $fqcn !== null ? [ $fqcn ] : [];
			}

			return [];
		}

		// class X extends \Plathix\Modules\Y\Z implements \Plathix\Modules\Y\W
		if ( $node instanceof Node\Stmt\Class_ ) {
			$fqcns = $this->fqcnsFromTypeNode( $node->extends );
			foreach ( $node->implements as $implemented ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $implemented ) );
			}

			return $fqcns;
		}

		// interface X extends \Plathix\Modules\Y\Z, \Plathix\Modules\Y\W — множественное extends
		if ( $node instanceof Node\Stmt\Interface_ ) {
			$fqcns = [];
			foreach ( $node->extends as $extended ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $extended ) );
			}

			return $fqcns;
		}

		// enum X implements \Plathix\Modules\Y\Z
		if ( $node instanceof Node\Stmt\Enum_ ) {
			$fqcns = [];
			foreach ( $node->implements as $implemented ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $implemented ) );
			}

			return $fqcns;
		}

		// $x instanceof \Plathix\Modules\Y\Z — динамическое выражение в ->class (не Name) вне покрытия.
		if ( $node instanceof Node\Expr\Instanceof_ ) {
			return $this->fqcnsFromTypeNode( $node->class instanceof Name ? $node->class : null );
		}

		// function foo(): \Plathix\Modules\Y\Z (включая nullable/union/intersection)
		if ( $node instanceof Node\Stmt\ClassMethod ) {
			return $this->fqcnsFromTypeNode( $node->returnType );
		}

		// function foo(\Plathix\Modules\Y\Z $x) — включает promoted constructor params
		// (флаг видимости не влияет на положение типа в AST, тип всегда в ->type).
		if ( $node instanceof Node\Param ) {
			return $this->fqcnsFromTypeNode( $node->type );
		}

		// class X { private \Plathix\Modules\Y\Z $prop; } — тип один на весь Property-stmt,
		// даже при групповом объявлении `private T $a, $b`.
		if ( $node instanceof Node\Stmt\Property ) {
			return $this->fqcnsFromTypeNode( $node->type );
		}

		// catch (\Plathix\Modules\Y\Z $e) — может перечислять несколько типов через `|`.
		if ( $node instanceof Node\Stmt\Catch_ ) {
			$fqcns = [];
			foreach ( $node->types as $caughtType ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $caughtType ) );
			}

			return $fqcns;
		}

		// class X { use \Plathix\Modules\Y\SomeTrait; } — трейты, не use-импорт statement.
		if ( $node instanceof Node\Stmt\TraitUse ) {
			$fqcns = [];
			foreach ( $node->traits as $trait ) {
				$fqcn = $this->fqcnFromNameNode( $trait );
				if ( $fqcn !== null ) {
					$fqcns[] = $fqcn;
				}
			}

			return $fqcns;
		}

		// #[\Plathix\Modules\Y\SomeAttribute] — одна группа может содержать несколько атрибутов.
		if ( $node instanceof Node\AttributeGroup ) {
			$fqcns = [];
			foreach ( $node->attrs as $attribute ) {
				$fqcn = $this->fqcnFromNameNode( $attribute->name );
				if ( $fqcn !== null ) {
					$fqcns[] = $fqcn;
				}
			}

			return $fqcns;
		}

		return [];
	}

	private function fqcnFromNameNode(mixed $classNode): ?string
	{
		if ( ! $classNode instanceof Name\FullyQualified ) {
			return null;
		}

		return $classNode->toString();
	}

	/**
	 * Разворачивает typed AST-узел (return-type объявление метода, либо любой другой
	 * typed node) в список FQCN-строк. Обрабатывает обёртки `Name\FullyQualified`,
	 * `NullableType` (один уровень `->type`) и `UnionType`/`IntersectionType`
	 * (`->types`, рекурсивно на каждый элемент — union может смешивать FQCN с `Identifier`
	 * скалярами типа `int`/`void`/`self`/`static`, которые корректно игнорируются). PHPStan
	 * резолвит короткие/qualified `Name` (через `use`-импорт) в `Name\FullyQualified` до
	 * вызова правила, поэтому этот метод покрывает cross-module ссылку независимо от того,
	 * как она записана в исходнике.
	 *
	 * @return list<string>
	 */
	private function fqcnsFromTypeNode(?Node $typeNode): array
	{
		if ( $typeNode === null ) {
			return [];
		}

		if ( $typeNode instanceof Name\FullyQualified ) {
			return [ $typeNode->toString() ];
		}

		if ( $typeNode instanceof NullableType ) {
			return $this->fqcnsFromTypeNode( $typeNode->type );
		}

		if ( $typeNode instanceof UnionType || $typeNode instanceof IntersectionType ) {
			$fqcns = [];
			foreach ( $typeNode->types as $subType ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $subType ) );
			}

			return $fqcns;
		}

		return [];
	}

	/** @param array<Arg|\PhpParser\Node\VariadicPlaceholder> $args */
	private function fqcnFromStringArg(array $args, int $index): ?string
	{
		$arg = $args[ $index ] ?? null;
		if ( ! $arg instanceof Arg || ! $arg->value instanceof String_ ) {
			// Переменная/конкатенация/отсутствующий аргумент — вне покрытия этого правила
			// (см. Honest known limitation в докблоке класса), не false positive попытка.
			return null;
		}

		return $arg->value->value;
	}

	/** Извлекает имя модуля из `Plathix\Modules\<Module>\...`; null если не подходит под паттерн. */
	private function moduleOf(string $namespaceOrFqcn): ?string
	{
		if ( ! str_starts_with( $namespaceOrFqcn, 'Plathix\\Modules\\' ) ) {
			return null;
		}

		$rest = substr( $namespaceOrFqcn, strlen( 'Plathix\\Modules\\' ) );
		$parts = explode( '\\', $rest );

		return $parts[0] ?? null;
	}
}
