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
				'Static analysis rule failed for a public contract violation.',
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
