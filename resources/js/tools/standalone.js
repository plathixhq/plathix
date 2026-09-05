// Tools page — CSS-only entry ([internal]).
//
// ToolsPage (Modules\Tools\ToolsPage) — чистый server-side PHP-рендер страницы
// Tools (export-карточка хоста; ApiKey/Import карточки co-located отдельно).
// У неё нет JS-логики своей. Этот entry существует ТОЛЬКО чтобы webpack
// MiniCssExtractPlugin извлёк CSS в assets/css/tools.css, который
// ToolsPage::enqueue_scripts грузит на странице Tools. Зеркало propage/standalone.js.
import './tools.css';
