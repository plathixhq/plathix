<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<Node\Stmt\Expression>
 */
final class ForbiddenDiscardedWriteReturnRule implements Rule
{
	private const TARGET_FUNCTIONS = [
		'update_option',
		'update_site_option',
		'update_term_meta',
		'update_post_meta',
		'update_user_meta',
		'update_metadata',
		'update_comment_meta',
		'delete_option',
		'delete_site_option',
		'delete_term_meta',
		'delete_post_meta',
		'delete_user_meta',
		'delete_metadata',
		'delete_comment_meta',
	];

	public function getNodeType(): string
	{
		return Node\Stmt\Expression::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof Node\Stmt\Expression || ! $node->expr instanceof FuncCall ) {
			return [];
		}

		$call = $node->expr;
		if ( ! $call->name instanceof Node\Name ) {
			return [];
		}

		$functionName = $call->name->toString();
		if ( ! in_array( $functionName, self::TARGET_FUNCTIONS, true ) ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				'%s() return value is discarded — this function returns false indistinguishably ' . 'for a genuine write failure AND an honest no-op (value already matches). ' . 'Capture the result and readback-compare (see FolderRepository::setPosition() or ' . 'OptionWrite::ifChanged() for the established pattern). ' . 'If this call is genuinely fire-and-forget (result truly does not matter), add ' . '@phpstan-ignore with a one-line justification. ',
				$functionName
			) )->identifier( 'plathix.discardedWriteReturn' )->build(),
		];
	}
}
