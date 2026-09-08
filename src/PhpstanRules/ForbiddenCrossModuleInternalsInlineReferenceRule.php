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
 * @implements Rule<Node>
 */

final class ForbiddenCrossModuleInternalsInlineReferenceRule implements Rule
{

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


		$targetModule = $this->moduleOf( $fqcn );
		if ( $targetModule === null || $targetModule === $currentModule ) {
			return null;
		}

		return RuleErrorBuilder::message( sprintf(
			'Module "%s" must not reference internal class %s of module "%s" via inline FQCN or a ' .
			'string-literal dynamic call — depend on a stable contract instead (Plathix\PublicApi\*, ' .
			'or Plathix\Core\*/Infrastructure\*/User\*/Contracts\*, or a plathix/* WP hook). ' .
			'Static analysis rule failed for a public contract violation.',
			$currentModule,
			$fqcn,
			$targetModule
		) )->identifier( 'plathix.forbiddenCrossModuleInternalInline' )->build();
	}

	/**
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

		if ( $node instanceof Node\Expr\Instanceof_ ) {
			return $this->fqcnsFromTypeNode( $node->class instanceof Name ? $node->class : null );
		}

		if ( $node instanceof Node\Stmt\ClassMethod ) {
			return $this->fqcnsFromTypeNode( $node->returnType );
		}

		if ( $node instanceof Node\Param ) {
			return $this->fqcnsFromTypeNode( $node->type );
		}

		if ( $node instanceof Node\Stmt\Property ) {
			return $this->fqcnsFromTypeNode( $node->type );
		}

		if ( $node instanceof Node\Stmt\Catch_ ) {
			$fqcns = [];
			foreach ( $node->types as $caughtType ) {
				$fqcns = array_merge( $fqcns, $this->fqcnsFromTypeNode( $caughtType ) );
			}

			return $fqcns;
		}

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

			return null;
		}

		return $arg->value->value;
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
