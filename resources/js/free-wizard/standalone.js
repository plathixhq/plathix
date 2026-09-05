// Free first-run wizard — CSS-only entry ([internal], #113).
//
// Free-визард (FreeFirstRun\FreeWizard) — чистый server-side PHP-рендер overlay со
// статичными <a href> на admin-post.php (skip/apply/scratch). У него НЕТ JS-логики
// (в отличие от PRO onboarding-wizard/standalone.js, который SPA). Этот entry существует
// ТОЛЬКО чтобы webpack MiniCssExtractPlugin извлёк CSS визарда в assets/css/free-wizard.css,
// который FreeFirstRun\WizardAssets грузит на Dashboard. Логики здесь нет и не должно быть.
import '../../css/free-wizard.css';
