<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\BooleanNot;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<BooleanNot>
 */

final class ForbiddenBareFullAdminGateRule implements Rule
{
	private const TARGET_FILE_SUFFIX = 'src/User/AccessResolver.php';

	public function getNodeType(): string
	{
		return BooleanNot::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof BooleanNot ) {
			return [];
		}

		if ( str_ends_with( str_replace( '\\', '/', $scope->getFile() ), self::TARGET_FILE_SUFFIX ) ) {
			return [];
		}

		if ( ! ForbiddenManualFullAdminGateRule::isManageOptionsCapCheck( $node, $scope ) ) {
			return [];
		}

		return [
			RuleErrorBuilder::message(
				'Bare full-admin gate (current_user_can(\'manage_options\') without a paired ' .
				'AccessResolver::forCurrentUser() === AccessLevel::Full check) — this does not see ' .
				'PRO RolePolicy per-role/per-user access-level override (plathix/user/access_level). ' .
				'Use AccessResolver::currentUserIsFullAdmin() for non-AJAX call sites, or ' .
				'Plathix\\Http\\AjaxGuard::requireCap(AccessLevel::Full, \'manage_options\') for AJAX ' .
				'Static analysis rule failed for a public contract violation.' .
				'occurrences of this exact blind spot — ForbiddenManualFullAdminGateRule/#535 only ' .
				'catches the paired && / || form). If this is a genuine unrelated check, add ' .
				'@phpstan-ignore with a one-line justification.'
			)->identifier( 'plathix.manualFullAdminGate' )->build(),
		];
	}
}
