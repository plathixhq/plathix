<?php

declare(strict_types=1);

namespace Plathix\Contracts;

interface ModuleInterface
{
	public function register(): void;
}
