<?php


declare(strict_types=1);

namespace Plathix\Modules\Pro;

final class CommerceData
{

	private const DEFAULTS = [
		'currency'   => '€',
		'refundDays' => 30,
		'plans'      => [
			[
				'key'         => 'starter',
				'line'        => 'Pro',
				'price'       => 69,
				'sitesCount'  => 1,
				'mostPopular' => false,
			],
			[
				'key'         => 'agency3',
				'line'        => 'Pro',
				'price'       => 99,
				'sitesCount'  => 3,
				'mostPopular' => true,
			],
			[
				'key'         => 'unlimited',
				'line'        => 'Agency',
				'price'       => 190,
				'sitesCount'  => 15,
				'mostPopular' => false,
			],
		],
	];

	private static array $memo = [];

	private string $file;

	/**
	 * @param string|null $file
	 */

	public function __construct(?string $file = null) {
		$this->file = $file ?? __DIR__ . '/commerce.json';
	}

	/**
	 * @return array<int, array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>
	 */

	public function plans(): array {
		return $this->data()['plans'];
	}

	public function currency(): string {
		return $this->data()['currency'];
	}

	public function refundDays(): int {
		return $this->data()['refundDays'];
	}

	public function startingPrice(): int {
		return $this->data()['plans'][0]['price'];
	}

	/**
	 * @return array{currency:string,refundDays:int,plans:array<int,array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>}
	 */

	private function data(): array {
		if ( ! isset( self::$memo[ $this->file ] ) ) {
			self::$memo[ $this->file ] = $this->load();
		}

		return self::$memo[ $this->file ];
	}

	/**
	 * @return array{currency:string,refundDays:int,plans:array<int,array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>}
	 */

	private function load(): array {
		$decoded = wp_json_file_decode( $this->file, [ 'associative' => true ] );

		if ( is_array( $decoded ) && $this->isValidSchema( $decoded ) ) {
			/** @var array{currency:string,refundDays:int,plans:array<int,array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>} $decoded */
			return $decoded;
		}

		return self::DEFAULTS;
	}

	/**
	 * @param array<mixed> $data
	 */

	private function isValidSchema(array $data): bool {
		if (
			! isset( $data['currency'], $data['refundDays'], $data['plans'] )
			|| ! is_string( $data['currency'] ) || '' === $data['currency']
			|| ! is_int( $data['refundDays'] ) || $data['refundDays'] < 1
			|| ! is_array( $data['plans'] ) || [] === $data['plans']
		) {
			return false;
		}

		foreach ( $data['plans'] as $plan ) {
			if (
				! is_array( $plan )
				|| ! isset( $plan['key'], $plan['line'], $plan['price'], $plan['sitesCount'], $plan['mostPopular'] )
				|| ! is_string( $plan['key'] ) || '' === $plan['key']
				|| ! is_string( $plan['line'] ) || '' === $plan['line']
				|| ! is_int( $plan['price'] ) || $plan['price'] < 1
				|| ! is_int( $plan['sitesCount'] ) || $plan['sitesCount'] < 1
				|| ! is_bool( $plan['mostPopular'] )
			) {
				return false;
			}
		}

		return true;
	}
}
