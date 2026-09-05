// ProPage — CSS-only entry ([internal], #113).
//
// ProPage (Admin\ProPage) — чистый server-side PHP-рендер апселл-страницы «Перейти на PRO»
// (hero, таблица сравнения, планы). У неё НЕТ JS-логики. Этот entry существует ТОЛЬКО чтобы
// webpack MiniCssExtractPlugin извлёк CSS ProPage в assets/css/propage.css, который
// Modules\Pro\ProPageAssets грузит на странице ProPage. Логики здесь нет и не должно быть.
// Зеркало resources/js/free-wizard/standalone.js.
import '../../css/propage.css';
