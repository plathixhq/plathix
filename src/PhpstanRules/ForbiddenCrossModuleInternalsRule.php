<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Use_;
use PhpParser\Node\Stmt\UseUse;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * [internal]: запрещает feature-модулю `use`-ить internal-класс ДРУГОГО
 * feature-модуля (`Plathix\Modules\<A>\*` из файла в `Plathix\Modules\<B>\*`, где `A !== B`) —
 * обязан идти через `Plathix\PublicApi\*` (или платформенный `Core`/`Infrastructure`/`User`/
 * `Contracts`, или WP-хук `plathix/*`). Тот же принцип, что уже применяется к границе
 * Free→PRO (`[internal]`), только внутри одного репозитория. Введено ПОСЛЕ полной миграции
 * (21 найденный случай — 0 осталось на момент введения правила, см. spec).
 *
 * Known limitation ([internal], добавлено постфактум — этот disclaimer раньше отсутствовал,
 * хотя PRO-версия того же правила его уже несла): правило матчит только AST-узел `Use_`
 * (statement `use Plathix\Modules\<X>\...`). Оно НЕ видит динамические способы достать
 * internal-класс другого модуля: `class_exists('Plathix\Modules\X\Y')`,
 * `new (\Plathix\Modules\X\Y::class)()`, `call_user_func(['Plathix\Modules\X\Y', 'm'])`,
 * `ReflectionClass`, `$var::method()` с динамической строкой. Рантайм-эквивалента гейта нет.
 * Частично закрыто {@see ForbiddenCrossModuleInternalsInlineReferenceRule} ([internal]) —
 * та ловит инлайн FQCN-ссылки и строковые ЛИТЕРАЛЫ в динамическом резолве, но не переменные/
 * конкатенацию (halting problem для произвольной строки).
 *
 * @implements Rule<Use_>
 */
final class ForbiddenCrossModuleInternalsRule implements Rule
{
	private const ALLOWED_PREFIXES = [
		'Plathix\\Core\\',
		'Plathix\\Infrastructure\\',
		'Plathix\\User\\',
		'Plathix\\Contracts\\',
		'Plathix\\PublicApi\\',
	];

	public function getNodeType(): string
	{
		return Use_::class;
	}

	/**
	 * @param Use_ $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		$errors = [];

		$currentModule = $this->moduleOf( $scope->getNamespace() ?? '' );
		if ( $currentModule === null ) {
			return $errors;
		}

		foreach ( $node->uses as $use ) {
			if ( ! $use instanceof UseUse ) {
				continue;
			}

			$fqcn = $this->nameToString( $use->name );

			if ( ! str_starts_with( $fqcn, 'Plathix\\Modules\\' ) ) {
				continue;
			}

			foreach ( self::ALLOWED_PREFIXES as $allowed ) {
				if ( str_starts_with( $fqcn, $allowed ) ) {
					continue 2;
				}
			}

			$targetModule = $this->moduleOf( $fqcn );

			if ( $targetModule === null || $targetModule === $currentModule ) {
				continue;
			}

			$errors[] = RuleErrorBuilder::message( sprintf(
				'Module "%s" must not "use" internal class %s of module "%s" directly — depend on a stable ' .
				'contract instead (Plathix\PublicApi\*, or Plathix\Core\*/Infrastructure\*/User\*/Contracts\*, ' .
				'or a plathix/* WP hook). See [internal].',
				$currentModule,
				$fqcn,
				$targetModule
			) )->identifier( 'plathix.forbiddenCrossModuleInternal' )->build();
		}

		return $errors;
	}

	private function nameToString(Name $name): string
	{
		return implode( '\\', $name->getParts() );
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
