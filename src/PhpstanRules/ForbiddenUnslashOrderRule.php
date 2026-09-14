<?php

declare(strict_types=1);

namespace Plathix\PhpstanRules;

use PhpParser\Node;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Cast\Array_ as ArrayCast;
use PhpParser\Node\Expr\Cast\String_ as StringCast;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * @implements Rule<FuncCall>
 */
final class ForbiddenUnslashOrderRule implements Rule
{
	private const SANITIZER_FUNCTIONS = [
		'sanitize_key',
		'sanitize_text_field',
		'sanitize_title',
	];

	private const SUPERGLOBAL_NAMES = [
		'_GET',
		'_POST',
		'_REQUEST',
	];

	private const DECODE_FUNCTIONS = [
		'rawurldecode',
		'urldecode',
	];

	private const CLIENT_INFLUENCED_SERVER_KEYS = [
		'REQUEST_URI',
		'PHP_SELF',
		'REMOTE_ADDR',
	];

	public function getNodeType(): string
	{
		return FuncCall::class;
	}

	/**
	 * @param Node $node
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	public function processNode(Node $node, Scope $scope): array
	{
		if ( ! $node instanceof FuncCall || ! $node->name instanceof Node\Name ) {
			return [];
		}

		$functionName = $scope->resolveName( $node->name );

		if ( $functionName === 'array_map' ) {
			return $this->checkArrayMapCase( $node, $scope );
		}

		if ( in_array( $functionName, self::SANITIZER_FUNCTIONS, true ) ) {
			$decodeOrderErrors = $this->checkDecodeOrderCase( $node, $scope, $functionName );
			if ( $decodeOrderErrors !== [] ) {
				return $decodeOrderErrors;
			}

			return $this->checkDirectServerReadCase( $node, $scope, $functionName );
		}

		return [];
	}

	/**
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkArrayMapCase(FuncCall $node, Scope $scope): array
	{
		if ( count( $node->args ) < 2 || ! $node->args[0] instanceof Node\Arg || ! $node->args[1] instanceof Node\Arg ) {
			return [];
		}

		$callbackArg = $node->args[0]->value;
		if ( ! $callbackArg instanceof String_ || ! in_array( $callbackArg->value, self::SANITIZER_FUNCTIONS, true ) ) {
			return [];
		}

		$valueArg = $node->args[1]->value;

		if ( $this->isWpUnslashWrapped( $valueArg, $scope ) ) {
			return [];
		}

		if ( ! $this->readsSuperglobalDirectly( $valueArg, $scope ) ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				"array_map('%s', ...) reads a superglobal array directly without wrapping it in " . 'wp_unslash() first — WordPress magic-quotes slashing is applied per-element AFTER ' . 'array_map runs, so each element keeps its escaped form when sanitize_key()/' . 'sanitize_text_field() sees it. ' . 'Wrap the whole array: array_map(%1$s, wp_unslash((array) ...)). ',
				$callbackArg->value
			) )->identifier( 'plathix.unslashOrderArrayMap' )->build(),
		];
	}

	/**
	 * `sanitize_text_field(rawurldecode($_GET['x']))` — decode happens INSIDE the sanitizer
	 * call's argument instead of as a separate step before it, so the sanitizer's own
	 * unslash-then-sanitize contract can't apply to the final decoded characters.
	 *
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkDecodeOrderCase(FuncCall $node, Scope $scope, string $sanitizerName): array
	{
		if ( count( $node->args ) < 1 || ! $node->args[0] instanceof Node\Arg ) {
			return [];
		}

		$argValue = $node->args[0]->value;

		// Unwrap a leading (string) cast, if present, to look at the actual expression.
		if ( $argValue instanceof StringCast ) {
			$argValue = $argValue->expr;
		}

		if ( ! $argValue instanceof FuncCall || ! $argValue->name instanceof Node\Name ) {
			return [];
		}

		$innerName = $scope->resolveName( $argValue->name );
		if ( ! in_array( $innerName, self::DECODE_FUNCTIONS, true ) ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				'%s() is called directly on the result of %s() — decode must happen BEFORE ' . 'sanitize, as its own separate step, not nested inside the sanitizer argument. ' . 'The sanitizer must see the final decoded characters, not the still-encoded string. ' . 'Split into two statements: $decoded = %2$s(wp_unslash(...)); $clean = %1$s($decoded). ',
				$sanitizerName,
				$innerName
			) )->identifier( 'plathix.unslashOrderDecode' )->build(),
		];
	}

	/**
	 * @return list<\PHPStan\Rules\RuleError>
	 */
	private function checkDirectServerReadCase(FuncCall $node, Scope $scope, string $sanitizerName): array
	{
		if ( count( $node->args ) < 1 || ! $node->args[0] instanceof Node\Arg ) {
			return [];
		}

		$argValue = $node->args[0]->value;

		if ( $argValue instanceof StringCast ) {
			$argValue = $argValue->expr;
		}

		if ( $this->isWpUnslashWrapped( $argValue, $scope ) ) {
			return [];
		}

		$serverKey = $this->readsClientInfluencedServerKeyDirectly( $argValue );
		if ( $serverKey === null ) {
			return [];
		}

		return [
			RuleErrorBuilder::message( sprintf(
				"%s(\$_SERVER['%s']) reads a client-influenced/ambiguous \$_SERVER key directly " . 'without wrapping it in wp_unslash() first — this key can carry WordPress ' . 'magic-quotes slashing or client-controlled content, same as $_GET/$_POST. ' . 'Wrap it: ' . "%1\$s((string) wp_unslash(\$_SERVER['%2\$s'] ?? '')). ",
				$sanitizerName,
				$serverKey
			) )->identifier( 'plathix.unslashOrderServer' )->build(),
		];
	}

	/**
	 * True if `$node` reads `$_SERVER['KEY']` directly (optionally through a leading
	 * `(string)` cast or a `?? default` coalesce) AND `KEY` is on the client-influenced
	 * allowlist (a literal `REQUEST_URI`/`PHP_SELF`/`REMOTE_ADDR`, or any `HTTP_*` key).
	 * Returns the matched key name, or null if this is not a matching $_SERVER read.
	 */
	private function readsClientInfluencedServerKeyDirectly(Node $node): ?string
	{
		if ( $node instanceof Node\Expr\BinaryOp\Coalesce ) {
			$node = $node->left;
		}

		if ( ! $node instanceof ArrayDimFetch || $node->dim === null ) {
			return null;
		}

		$var = $node->var;
		if ( ! $var instanceof Variable || $var->name !== '_SERVER' ) {
			return null;
		}

		if ( ! $node->dim instanceof String_ ) {
			return null;
		}

		$key = $node->dim->value;

		if ( in_array( $key, self::CLIENT_INFLUENCED_SERVER_KEYS, true ) || str_starts_with( $key, 'HTTP_' ) ) {
			return $key;
		}

		return null;
	}

	/**
	 * True if `$node` is `wp_unslash(...)`, optionally wrapped in a leading `(array)` cast
	 * on the outside (`(array) wp_unslash(...)`) or inside (`wp_unslash((array) ...)`).
	 */
	private function isWpUnslashWrapped(Node $node, Scope $scope): bool
	{
		if ( $node instanceof ArrayCast ) {
			$node = $node->expr;
		}

		if ( ! $node instanceof FuncCall || ! $node->name instanceof Node\Name ) {
			return false;
		}

		return $scope->resolveName( $node->name ) === 'wp_unslash';
	}

	/**
	 * True if `$node` reads `$_GET`/`$_POST`/`$_REQUEST` directly (optionally through a
	 * leading `(array)` cast) — e.g. `$_POST['x']`, `(array) ($_POST['x'] ?? [])`.
	 */
	private function readsSuperglobalDirectly(Node $node, Scope $scope): bool
	{
		if ( $node instanceof ArrayCast ) {
			$node = $node->expr;
		}

		if ( $node instanceof Node\Expr\BinaryOp\Coalesce ) {
			$node = $node->left;
		}

		if ( ! $node instanceof ArrayDimFetch ) {
			return false;
		}

		$var = $node->var;

		return $var instanceof Variable && is_string( $var->name ) && in_array( $var->name, self::SUPERGLOBAL_NAMES, true );
	}
}
