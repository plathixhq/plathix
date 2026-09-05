<?php

/**
 * Тонкий читатель файла-данных коммерции продающей страницы ([internal]).
 *
 * @package Plathix
 */

declare(strict_types=1);

namespace Plathix\Modules\Pro;

/**
 * Коммерческие данные продающей страницы — ЕДИНСТВЕННЫЙ источник цен, тарифов,
 * валюты и срока возврата в плагине ([internal] → [internal]).
 *
 * Данные живут в соседнем commerce.json и правятся владельцем напрямую, без PHP-диффа.
 * Значения финальные (подтверждены владельцем 2026-08-24); правка допустима только
 * вместе с публичной сеткой на plathix.com и парным data-pin тестом (CommerceDataTest).
 * Срок возврата (refundDays) — единый источник на все 5 мест страницы.
 *
 * Контракт надёжности: страница ОБЯЗАНА рендериться при любом состоянии файла.
 * Файл принимается только целиком валидным по схеме; любое нарушение — атомарный
 * откат на ПОЛНЫЙ набор дефолтов (никакого частичного мерджа «новая цена + старый
 * флаг»). Дефолты — точная копия commerce.json; рассинхрон ловит блокирующий
 * sync-тест, а не прод.
 *
 * Механизм single-use по решению пакета [internal]: не обобщать до
 * платформенного «реестра файлов-данных», пока нет второго кейса.
 */
final class CommerceData
{
	/**
	 * Дефолты — точная копия commerce.json (порядок ключей включительно, его
	 * проверяет sync-тест). Используются целиком при невалидном/отсутствующем файле.
	 */
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

	/**
	 * Мемоизация на запрос, по пути файла: страница читает данные несколько раз
	 * за рендер (hero + plans + trust strip + FAQ) — файл декодируется один раз.
	 *
	 * @var array<string, array{currency:string,refundDays:int,plans:array<int,array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>}>
	 */
	private static array $memo = [];

	/** Путь к файлу-данных. */
	private string $file;

	/**
	 * @param string|null $file Путь к файлу-данных; null — штатный commerce.json рядом с классом.
	 */
	public function __construct(?string $file = null) {
		$this->file = $file ?? __DIR__ . '/commerce.json';
	}

	/**
	 * Тарифы в порядке файла.
	 *
	 * @return array<int, array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>
	 */
	public function plans(): array {
		return $this->data()['plans'];
	}

	/** Символ валюты (v4 — евро). */
	public function currency(): string {
		return $this->data()['currency'];
	}

	/**
	 * Срок возврата в днях — публичная оферта, светится в 5 местах продающей страницы.
	 * 30 — сознательно больше 14, которые директива 2011/83/EU задаёт как МИНИМУМ права
	 * отказа в ЕС: превышение минимума законно и работает как аргумент при покупке.
	 */
	public function refundDays(): int {
		return $this->data()['refundDays'];
	}

	/** Цена первого тарифа — для hero-CTA (цифра нигде не дублируется). */
	public function startingPrice(): int {
		return $this->data()['plans'][0]['price'];
	}

	/**
	 * Данные: валидный файл целиком или полные дефолты.
	 *
	 * @return array{currency:string,refundDays:int,plans:array<int,array{key:string,line:string,price:int,sitesCount:int,mostPopular:bool}>}
	 */
	private function data(): array {
		if ( ! isset( self::$memo[ $this->file ] ) ) {
			self::$memo[ $this->file ] = $this->load();
		}

		return self::$memo[ $this->file ];
	}

	/**
	 * Читает и валидирует файл; wp_json_file_decode сам пишет trigger_error в
	 * debug.log при битом/отсутствующем файле — отдельный логгер не нужен.
	 *
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
	 * Атомарная проверка схемы: типы и диапазоны ДО любого каста — (int) на мусоре
	 * дал бы «0-day money-back guarantee» на продающей странице.
	 *
	 * @param array<mixed> $data Декодированный файл.
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
